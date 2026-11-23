<?php

namespace Depman\WpCli;

/**
 * CI gating: wait for the report to be scanned, then decide whether to fail the
 * build.
 *
 * **This is the only mode permitted to fail a build, and it must never run from
 * a post-install hook.** It belongs in a dedicated CI step somebody added on
 * purpose — see docs/ingest-clients.md §10.
 *
 * Fail-closed throughout. A timeout, an unreadable response and a rejected poll
 * all exit non-zero, because the alternative is a security gate that answers
 * "probably fine" whenever our queue is slow. Everything else this client does
 * leans the other way; this is the exception the user asked for.
 *
 * **Not final, so the two waits can be overridden in tests.** Polling is a loop
 * around a clock and a sleep, and a suite that exercises it for real is a suite
 * nobody runs.
 */
class Gate
{
    /**
     * The server's own severity ordering, mirrored.
     *
     * It has to match App\Enums\Severity::rank() or `--fail-on=high` means
     * something different on each side of the wire. `unknown` outranks `none`
     * for the reason the server gives: an advisory we could not band deserves a
     * human's attention more than one scored as genuinely harmless.
     *
     * **Public so the server's suite can pin it**, the way DogfoodTest pins the
     * manifest digest. A client and a server that disagree about what "high"
     * means fail the same way the digest does — silently, and only for the
     * people relying on it.
     */
    public const RANKS = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'unknown' => 1,
        'none' => 0,
    ];

    /** Thresholds a user may ask for. `none` is excluded: it would fail on nothing. */
    public const THRESHOLDS = ['critical', 'high', 'medium', 'low', 'unknown'];

    private const FIRST_BACKOFF_SECONDS = 2;

    private const MAX_BACKOFF_SECONDS = 15;

    /** @var Logger */
    private $logger;

    /** @var Reporter */
    private $reporter;

    public function __construct(Logger $logger, Reporter $reporter)
    {
        $this->logger = $logger;
        $this->reporter = $reporter;
    }

    /**
     * The CLI entry point: resolve what polling needs, then wait.
     *
     * The resolution lives here rather than in `bin/depman` on purpose. The command
     * class is by this package's PHPStan paths or its test suite —
     * it is a shell around the library — and a security gate whose decision
     * logic sits in the one unanalysed file is a gate nobody has checked.
     *
     * @param  array<string, string>  $env
     */
    public function run(
        string $projectRoot,
        Result $result,
        ?string $threshold,
        int $timeoutSeconds,
        array $env = []
    ): GateResult {
        if ($result->status !== 'reported') {
            // A gate that shrugs when the report never landed is a gate that
            // goes green for every build with a rotated token. The user asked
            // for a gate; not being able to run one is a failure of it.
            return GateResult::errored('the report was not accepted ('.$result->detail.'), so there is nothing to gate on.');
        }

        if ($result->statusUrl === null) {
            return GateResult::errored('DepMan accepted the report but returned no status URL to poll.');
        }

        $config = Config::load($projectRoot, $this->logger, $env);

        if ($config === null) {
            return GateResult::errored('depman.json disappeared between reporting and polling.');
        }

        $endpoint = $config->endpoint($env);
        $found = Token::locate($endpoint, $env, $projectRoot);

        if ($found === null) {
            return GateResult::errored('no token found, so the report cannot be polled.');
        }

        return $this->await(
            $endpoint,
            $result->statusUrl,
            $found->value,
            $threshold,
            $timeoutSeconds,
            $config->timeoutMs($env)
        );
    }

    /**
     * Poll until the report has been scanned, then judge it.
     *
     * `$threshold` null means "wait and report, but do not gate" — `--wait`
     * without `--fail-on=`, which is useful for seeing the numbers in a build
     * log without yet trusting them to stop a deploy.
     */
    public function await(
        string $endpoint,
        string $statusUrl,
        string $token,
        ?string $threshold,
        int $timeoutSeconds,
        int $requestTimeoutMs
    ): GateResult {
        if ($threshold !== null && !in_array($threshold, self::THRESHOLDS, true)) {
            // Never "carry on without gating". A pipeline written against a
            // misspelled threshold would go green forever, which is the exact
            // failure this mode exists to prevent.
            return GateResult::errored(sprintf(
                'unknown severity threshold "%s". Use one of: %s.',
                $threshold,
                implode(', ', self::THRESHOLDS)
            ));
        }

        if (!$this->sameOrigin($endpoint, $statusUrl)) {
            // The URL arrives in a response body, and the next thing we would
            // do is put a bearer token on it. A token belongs to one host, which
            // is the same reason §7 forbids following redirects.
            return GateResult::errored('the server returned a status URL on another host; refusing to send the token there.');
        }

        $deadline = $this->now() + $timeoutSeconds;
        $backoff = self::FIRST_BACKOFF_SECONDS;
        $lastDetail = 'the report was not scanned in time';

        while (true) {
            $response = $this->reporter->fetch($statusUrl, $token, $requestTimeoutMs);

            $verdict = $response === null
                ? null
                : $this->judge($response, $threshold);

            if ($verdict !== null) {
                return $verdict;
            }

            $lastDetail = $response === null
                ? 'could not reach DepMan while waiting for the scan'
                : 'the report was not scanned in time';

            $remaining = $deadline - $this->now();

            if ($remaining <= 0) {
                return GateResult::timedOut($lastDetail);
            }

            $wait = min($backoff, $remaining);
            $this->logger->debug(sprintf('%s; polling again in %ds.', $lastDetail, $wait));

            $this->pause($wait);
            $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
        }
    }

    /**
     * One poll: a verdict, or null meaning "not yet, keep waiting".
     *
     * @param  array{status: int, body: string}  $response
     */
    private function judge(array $response, ?string $threshold): ?GateResult
    {
        // 5xx and 429 are transient by definition; keep waiting rather than
        // failing the build over one bad poll.
        if ($response['status'] >= 500 || $response['status'] === 429) {
            return null;
        }

        if ($response['status'] !== 200) {
            $decoded = json_decode($response['body'], true);
            $code = is_array($decoded) ? (string) ($decoded['error']['code'] ?? '') : '';

            return GateResult::errored('the status endpoint refused the poll'.($code === '' ? '' : ': '.$code));
        }

        $body = json_decode($response['body'], true);

        if (!is_array($body)) {
            return GateResult::errored('the status endpoint returned something that is not JSON.');
        }

        $reportStatus = (string) ($body['status'] ?? '');

        if ($reportStatus === 'failed' || $reportStatus === 'rejected') {
            // A gate cannot pass a tree that was never reconciled. This is the
            // distinction the server's `failed()` handler exists to preserve:
            // "no vulnerabilities" and "we never processed your tree" must not
            // look the same.
            return GateResult::errored('DepMan could not process the report: '
                .(is_string($body['error'] ?? null) ? $body['error'] : $reportStatus));
        }

        $findings = $body['findings'] ?? null;

        // Null findings is the server saying "accepted, not scanned yet", and
        // it is a distinct state from an empty summary on purpose. Treating the
        // two alike would pass every build that polled once, quickly.
        if (!is_array($findings)) {
            return null;
        }

        $counts = $this->counts($findings);

        if ($threshold === null) {
            return GateResult::passed($counts);
        }

        $breaching = 0;

        foreach ($counts as $severity => $count) {
            if (self::RANKS[$severity] >= self::RANKS[$threshold]) {
                $breaching += $count;
            }
        }

        return $breaching > 0
            ? GateResult::breached($breaching, $threshold, $counts)
            : GateResult::passed($counts);
    }

    /**
     * The per-severity buckets, worst first and with the totals dropped.
     *
     * Read key by key rather than taken wholesale: the summary also carries
     * `total` and `maxSeverity`, and a future band we do not rank would
     * otherwise be silently summed into a threshold comparison.
     *
     * @param  array<mixed>  $findings
     * @return array<string, int>
     */
    private function counts(array $findings): array
    {
        $counts = [];

        foreach (array_keys(self::RANKS) as $severity) {
            $counts[$severity] = (int) ($findings[$severity] ?? 0);
        }

        return $counts;
    }

    /**
     * Whether the server's status URL points back at the endpoint we posted to.
     *
     * Scheme, host and port, and nothing else — the path is the server's
     * business. A missing port compares equal to the scheme's default, because
     * `https://depman.io` and `https://depman.io:443` are the same origin and a
     * deployment behind a proxy may spell it either way.
     */
    private function sameOrigin(string $endpoint, string $statusUrl): bool
    {
        $a = $this->origin($endpoint);
        $b = $this->origin($statusUrl);

        return $a !== null && $a === $b;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);

        return $scheme.'://'.strtolower((string) $parts['host']).':'.($parts['port'] ?? $default);
    }

    /**
     * Seams. Overridden in tests, so the suite exercises the loop without
     * spending the wall-clock time the loop exists to spend.
     */
    protected function now(): int
    {
        return time();
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }
}
