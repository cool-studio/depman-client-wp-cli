<?php

namespace Depman\WpCli;

/**
 * Drains the offline spool.
 *
 * Writing to a queue that nothing empties is not resilience; it is a directory
 * of files that will never be sent. This is the other half of Spool, and the
 * command the client specification requires (docs/ingest-clients.md §9).
 *
 * Deliberately a separate orchestrator from Client rather than a mode of it.
 * The two share almost nothing: a report resolves a tree and builds a payload,
 * a push has a payload already and must not touch the installed tree at all —
 * the whole point is that it runs long after the install that produced it, when
 * the vendor directory may look nothing like it did.
 */
final class Pusher
{
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
     * @param  array<string, string>  $env
     */
    public function push(string $projectRoot, array $env = []): PushResult
    {
        $config = Config::load($projectRoot, $this->logger, $env);

        if ($config === null) {
            return PushResult::skipped('no depman.json');
        }

        if (!$config->isEnabled($env)) {
            return PushResult::skipped('disabled');
        }

        $spool = new Spool($config->spoolDirectory($env), $this->logger, $config->projectRoot);
        $pending = $spool->pending();

        if ($pending === []) {
            return PushResult::drained(0, 0);
        }

        $endpoint = $config->endpoint($env);
        $found = Token::locate($endpoint, $env, $projectRoot);

        if ($found === null) {
            // The spool is left exactly as it was. Discarding an inventory
            // because this particular invocation had no credential would throw
            // away the thing the spool exists to protect.
            $this->logger->info('no token found, skipping. Set DEPMAN_TOKEN in .env or the environment to push.');

            return PushResult::skipped('no token');
        }

        $this->logger->debug('token found via '.$found->source.'.');

        return $this->drain($spool, $pending, $endpoint, $found->value, $config->timeoutMs($env));
    }

    /**
     * @param  array<int, string>  $pending  Oldest first.
     */
    private function drain(Spool $spool, array $pending, string $endpoint, string $token, int $timeoutMs): PushResult
    {
        $sent = 0;
        $discarded = 0;

        foreach ($pending as $index => $file) {
            $payload = $spool->read($file);

            if ($payload === null) {
                // A truncated write. It will never decode, and at the cap a
                // slot that can never empty evicts payloads that could.
                $this->logger->warn('a spooled report could not be read and has been discarded: '.basename($file));
                $spool->forget($file);
                $discarded++;

                continue;
            }

            $response = $this->reporter->send($endpoint, $token, $this->replay($payload), $timeoutMs);
            $remaining = count($pending) - $index;

            if ($response === null) {
                // Still offline. This is the ordinary case and not an error:
                // the queue is doing its job.
                return PushResult::stopped($sent, $discarded, $remaining, 'could not reach DepMan', false);
            }

            $status = $response['status'];

            if ($status === 200 || $status === 202) {
                $spool->forget($file);
                $sent++;

                continue;
            }

            // Stop rather than work through the rest. Every remaining payload
            // goes to the same host with the same token, so whatever refused
            // this one refuses all of them, and hammering a rate limiter is how
            // a drain turns into an outage of its own.
            if ($status === 429 || $status >= 500) {
                return PushResult::stopped($sent, $discarded, $remaining,
                    $status === 429 ? 'rate limited' : 'DepMan is unavailable', false);
            }

            if ($status === 401 || $status === 403) {
                // Fixable, and fixable once for the whole queue — so keep every
                // payload and say what to fix. Discarding here would lose an
                // inventory to a rotated token.
                $this->logger->error($this->reason($response).' Nothing was discarded; fix the token and push again.');

                return PushResult::stopped($sent, $discarded, $remaining, 'the token was rejected', true);
            }

            if ($status < 400) {
                // A 3xx: the endpoint redirects, and we do not follow redirects
                // (§7). That is a configuration problem rather than a property
                // of this payload, so **nothing is discarded** — a proxy
                // answering 302 must not be able to eat a whole spool one file
                // at a time.
                $this->logger->error(sprintf(
                    'unexpected HTTP %d from %s; the endpoint may be redirecting. Nothing was discarded.',
                    $status,
                    $endpoint
                ));

                return PushResult::stopped($sent, $discarded, $remaining, 'unexpected HTTP '.$status, true);
            }

            // A 4xx — 5xx returned above — and a permanent property of this
            // payload: a wire version we have retired, a project that was
            // renamed. Retrying it forever hides the misconfiguration instead
            // of surfacing it, which is the same rule §8 applies to a live
            // report.
            $this->logger->error('a spooled report was rejected and has been discarded: '.$this->reason($response));
            $spool->forget($file);
            $discarded++;
        }

        return PushResult::drained($sent, $discarded);
    }

    /**
     * Mark the payload as a replay, leaving everything else untouched.
     *
     * `spool-replay` is a documented reason the ingest API accepts and nothing
     * else can produce, so it is the only way the server can tell a tree that
     * arrived late from one that arrived now. It is safe to rewrite because the
     * manifest digest covers semantic content only — purl, scope, relationship
     * — and never the reason, so a replayed payload still deduplicates against
     * whatever else reported that tree.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replay(array $payload): array
    {
        if (is_array($payload['report'] ?? null)) {
            $payload['report']['reason'] = 'spool-replay';
        }

        return $payload;
    }

    /**
     * @param  array{status: int, body: string}  $response
     */
    private function reason(array $response): string
    {
        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];

        $code = (string) ($decoded['error']['code'] ?? 'http_'.$response['status']);
        $message = (string) ($decoded['error']['message'] ?? 'The report was rejected.');

        return $code.': '.$message;
    }
}
