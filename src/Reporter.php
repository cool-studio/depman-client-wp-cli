<?php

namespace Depman\WpCli;

/**
 * Builds the wire payload and delivers it.
 *
 * Zero runtime dependencies by design — this package installs into everyone
 * else's dependency tree, so it uses curl when available and a stream context
 * otherwise, and nothing else.
 *
 * **Not final, so that `send()` can be overridden in tests.** It is the only
 * class here that touches the network, which makes it the seam the orchestrator
 * has to be tested through — every branch in Client::interpret() is a response
 * status, and reaching them for real would mean a live server per case. The
 * client specification requires this seam of every client; see
 * docs/ingest-clients.md.
 */
class Reporter
{
    public const WIRE_VERSION = 1;

    public const CLIENT_NAME = 'depman/wp-cli-client';

    /**
     * The client's own release version, sent in the payload and the User-Agent.
     *
     * **Bump this in the commit that gets tagged**, not afterwards: it is the
     * only thing that tells support which build produced a report, and a
     * constant that lags the tag makes every report lie about itself. See the
     * client release checklist in docs/release-checklist.md.
     */
    public const CLIENT_VERSION = '0.1.0';

    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @param  array{name: string, resolvedFrom: string}  $environment
     * @return array<string, mixed>
     */
    public function buildPayload(Config $config, array $packages, array $environment, string $reason): array
    {
        return [
            'depmanWireVersion' => self::WIRE_VERSION,
            'report' => [
                'manifestDigest' => $this->digest($packages, $environment['name']),
                'generatedAt' => gmdate('c'),
                'reason' => $reason,
            ],
            'project' => $config->project(),
            'environment' => [
                'name' => $environment['name'],
                'resolvedFrom' => $environment['resolvedFrom'],
                'ci' => $this->looksLikeCi(),
            ],
            'client' => [
                'name' => self::CLIENT_NAME,
                'version' => self::CLIENT_VERSION,
                // WP-CLI is what read the site, so it is named here; the
                // server has no enum value for it yet and stores null rather
                // than rejecting the report.
                'packageManager' => [
                    'name' => 'wp-cli',
                    'version' => defined('WP_CLI_VERSION') ? (string) constant('WP_CLI_VERSION') : null,
                ],
                'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            ],
            'ecosystem' => 'composer',
            // No lockfileName: a WordPress site has no lockfile. What is
            // installed is read from WordPress itself.
            'manifest' => [
                'path' => $config->manifestPath(),
            ],
            'packages' => $packages,
            'counts' => ['packages' => count($packages)],
            'warnings' => [],
        ];
    }

    /**
     * Must match the server's computation exactly, or every report reports a
     * digest mismatch. Semantic content only — no timestamps, no client version.
     *
     * @param  array<int, array<string, mixed>>  $packages
     */
    public function digest(array $packages, string $environmentName): string
    {
        $lines = [];

        foreach ($packages as $package) {
            $lines[] = implode("\t", [
                (string) $package['purl'],
                (string) $package['scope'],
                (string) $package['relationship'],
            ]);
        }

        sort($lines, SORT_STRING);

        return 'sha256:'.hash('sha256',
            "depman-manifest-v1\ncomposer\n".$environmentName."\n".implode("\n", $lines)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}|null Null on a transport failure.
     */
    public function send(string $endpoint, string $token, array $payload, int $timeoutMs): ?array
    {
        $url = $endpoint.'/api/v1/reports';
        $body = (string) json_encode($payload);

        $headers = [
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Depman-Wire-Version: '.self::WIRE_VERSION,
            'User-Agent: '.self::CLIENT_NAME.'/'.self::CLIENT_VERSION,
        ];

        return $this->request('POST', $url, $headers, $body, $timeoutMs);
    }

    /**
     * A GET against a URL the server itself handed us, for `report --wait`.
     *
     * Overridable for the same reason `send()` is: polling is the other half of
     * CI gating, and every branch of it is a response the client cannot produce
     * without a live server and a queue.
     *
     * **The caller must have checked the origin before calling this.** The URL
     * arrives in a response body, and a bearer token belongs to one host only —
     * see Gate::sameOrigin().
     *
     * @return array{status: int, body: string}|null Null on a transport failure.
     */
    public function fetch(string $url, string $token, int $timeoutMs): ?array
    {
        $headers = [
            'Authorization: Bearer '.$token,
            'Accept: application/json',
            'Depman-Wire-Version: '.self::WIRE_VERSION,
            'User-Agent: '.self::CLIENT_NAME.'/'.self::CLIENT_VERSION,
        ];

        return $this->request('GET', $url, $headers, null, $timeoutMs);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, body: string}|null
     */
    private function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): ?array
    {
        return function_exists('curl_init')
            ? $this->sendWithCurl($method, $url, $headers, $body, $timeoutMs)
            : $this->sendWithStream($method, $url, $headers, $body, $timeoutMs);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, body: string}|null
     */
    private function sendWithCurl(string $method, string $url, array $headers, ?string $body, int $timeoutMs): ?array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 5000),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        // No curl_close(). Since PHP 8.0 the handle is an object freed by the
        // garbage collector, and calling it is deprecated in 8.5 — which the
        // client printed on every `composer install`, from inside a tool whose
        // entire job is to be invisible during one.
        unset($handle);

        if ($response === false || $status === 0) {
            $this->logger->debug('transport failure: '.$error);

            return null;
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, body: string}|null
     */
    private function sendWithStream(string $method, string $url, array $headers, ?string $body, int $timeoutMs): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeoutMs / 1000,
            'ignore_errors' => true,
            // Same reason the curl path sets CURLOPT_FOLLOWLOCATION => false: a
            // redirect would replay the bearer token at whatever host answered.
            'follow_location' => 0,
        ]]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $status = 0;

        // PHP 8.5 deprecated the magic local $http_response_header in favour of
        // http_get_last_response_headers(). This package supports 8.1, where
        // that function does not exist, so both spellings have to stay — and
        // the ternary is what keeps the deprecated one from being evaluated at
        // all on the runtimes that warn about it.
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers() ?? [];
        } else {
            // Reached only on PHP < 8.5, and read through get_defined_vars()
            // rather than by name on purpose: 8.5 deprecated the magic local
            // $http_response_header, and it emits that deprecation when the
            // file is COMPILED, not when the branch runs — so spelling the
            // variable out here would warn on every 8.5 host even though this
            // line can never execute on one.
            $headers = get_defined_vars()['http_response_header'];
        }

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $response];
    }

    private function looksLikeCi(): bool
    {
        foreach (['CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE', 'CIRCLECI'] as $variable) {
            $value = getenv($variable);

            if ($value !== false && $value !== '' && strtolower($value) !== 'false') {
                return true;
            }
        }

        return false;
    }
}
