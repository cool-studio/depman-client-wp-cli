<?php

namespace Depman\WpCli;

/**
 * The depman.json contract, as loaded from a consumer project.
 *
 * Precedence for every setting is: **project `.env` > process env var >
 * depman.json > default.**
 *
 * `.env` comes first for the same reason it does for the token: the hook fires
 * during `composer install`, when the consumer's framework has not booted and
 * nothing has loaded `.env` into the process environment. Until this was
 * extended past `DEPMAN_TOKEN`, a developer who put `DEPMAN_ENDPOINT` or
 * `DEPMAN_ENV` beside their token — the only sensible place — had it silently
 * ignored, and the two mechanisms disagreed about where they looked.
 *
 * depman.json is committed to source control, so it must never contain a
 * credential. A `token` key is refused loudly rather than silently honoured.
 */
final class Config
{
    private const TOKEN_KEYS = ['token', 'apiToken', 'api_token', 'secret'];

    /**
     * Non-`DEPMAN_` variables this class may read out of a consumer's `.env`.
     *
     * `environment.from` names arbitrary variables and comes from depman.json,
     * which is committed and reviewed far less carefully than code. Without
     * this list, a one-line change to that array — `["DB_PASSWORD"]` — would
     * read a secret out of the densest secret store in the project and ship it
     * to the server as an environment name. A `DEPMAN_`-prefixed key is the
     * consumer's own namespace and needs no allowlist; anything else does.
     *
     * These are the variables that name an environment and nothing else. They
     * cover the documented default list plus the two other frameworks that use
     * the same convention.
     */
    private const DOTENV_READABLE = [
        'APP_ENV',
        'NODE_ENV',
        'ENVIRONMENT',
        'CI_ENVIRONMENT_NAME',
        'RAILS_ENV',
        'SYMFONY_ENV',
    ];

    /**
     * Memoised `.env` lookups, per key.
     *
     * `environment()` walks up to five source variables, and re-reading the
     * file once per source turns one hook into five file scans for no reason.
     * Caching the *result* rather than the parsed file keeps DotEnv's promise
     * that only the requested key ever leaves it.
     *
     * @var array<string, string|null>
     */
    private array $dotEnvCache = [];

    /** @var string */
    public $projectRoot;

    /** @var array<string, mixed> */
    private $raw;

    /** @var Logger */
    private $logger;

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(string $projectRoot, array $raw, Logger $logger)
    {
        $this->projectRoot = $projectRoot;
        $this->raw = $raw;
        $this->logger = $logger;
    }

    /**
     * @param  array<string, string>  $env
     */
    public static function load(string $projectRoot, Logger $logger, array $env = []): ?self
    {
        $path = rtrim($projectRoot, '/').'/depman.json';

        if (!is_file($path)) {
            $logger->debug('No depman.json found; nothing to report.');

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            $logger->error('depman.json is not valid JSON; skipping.');

            return null;
        }

        $config = new self($projectRoot, $decoded, $logger);
        $config->warnAboutCommittedSecrets();

        return $config;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(string $projectRoot, array $raw, Logger $logger): self
    {
        return new self($projectRoot, $raw, $logger);
    }

    private function warnAboutCommittedSecrets(): void
    {
        foreach (self::TOKEN_KEYS as $key) {
            if (!array_key_exists($key, $this->raw)) {
                continue;
            }

            // Deliberately printed regardless of log level. A token in a
            // committed file is already leaked; staying quiet helps nobody.
            $this->logger->always(sprintf(
                'a "%s" key was found in depman.json, which is committed to source control. '
                .'It has been ignored. Rotate that token and use the DEPMAN_TOKEN environment variable.',
                $key
            ));
        }
    }

    /**
     * @param  array<string, string>  $env
     */
    public function isEnabled(array $env = []): bool
    {
        // Via fromEnv() like every other getter. It used to read $env and
        // getenv() by hand and then test the result against null, which a
        // string|false can never be — dead code that static analysis would
        // have caught had it ever been pointed at this package.
        $override = $this->fromEnv('DEPMAN_ENABLED', $env);

        if ($override !== null) {
            return !in_array(strtolower($override), ['0', 'false', 'no', 'off'], true);
        }

        return (bool) ($this->raw['enabled'] ?? true);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function endpoint(array $env = []): string
    {
        $endpoint = $this->fromEnv('DEPMAN_ENDPOINT', $env)
            ?? (string) ($this->raw['endpoint'] ?? 'https://depman.io');

        return rtrim($endpoint, '/');
    }

    /**
     * @param  array<string, string>  $env
     */
    public function project(array $env = []): ?string
    {
        $project = $this->fromEnv('DEPMAN_PROJECT', $env) ?? ($this->raw['project'] ?? null);

        return is_string($project) && $project !== '' ? $project : null;
    }

    /**
     * Resolve the environment at run time.
     *
     * The same commit runs in local, CI, staging and production, so a literal
     * would be wrong. The fallback is deliberately "local" and never
     * "production": mislabelling a laptop as production produces alerts nobody
     * asked for and hides the ones that matter.
     *
     * @param  array<string, string>  $env
     * @return array{name: string, resolvedFrom: string}
     */
    public function environment(array $env = []): array
    {
        $sources = $this->raw['environment']['from'] ?? [
            'DEPMAN_ENV', 'APP_ENV', 'NODE_ENV', 'ENVIRONMENT', 'CI_ENVIRONMENT_NAME',
        ];

        foreach ((array) $sources as $variable) {
            $value = $this->fromEnv((string) $variable, $env);

            if ($value === null || $value === '') {
                continue;
            }

            return [
                'name' => $this->mapEnvironment($this->normalise($value)),
                'resolvedFrom' => (string) $variable,
            ];
        }

        return [
            'name' => $this->normalise((string) ($this->raw['environment']['fallback'] ?? 'local')),
            'resolvedFrom' => 'fallback',
        ];
    }

    private function mapEnvironment(string $value): string
    {
        $map = $this->raw['environment']['map'] ?? [];
        $mapped = is_array($map) ? ($map[$value] ?? $value) : $value;

        $allowed = $this->raw['environment']['allowed'] ?? null;

        if (is_array($allowed) && !in_array($mapped, $allowed, true)) {
            // Coerce rather than invent an environment. Typos would otherwise
            // proliferate into environments nobody meant to create.
            $fallback = $this->normalise((string) ($this->raw['environment']['fallback'] ?? 'local'));
            $this->logger->warn(sprintf('environment "%s" is not in the allowed list; using "%s".', $mapped, $fallback));

            return $fallback;
        }

        return (string) $mapped;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function includeDev(array $env = []): bool
    {
        $override = $this->fromEnv('DEPMAN_INCLUDE_DEV', $env);

        if ($override !== null) {
            return !in_array(strtolower($override), ['0', 'false', 'no', 'off'], true);
        }

        return (bool) ($this->raw['include']['dev'] ?? true);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function failOnError(array $env = []): bool
    {
        $override = $this->fromEnv('DEPMAN_FAIL_ON_ERROR', $env);

        if ($override !== null) {
            return in_array(strtolower($override), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) ($this->raw['failOnError'] ?? false);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function timeoutMs(array $env = []): int
    {
        $override = $this->fromEnv('DEPMAN_TIMEOUT_MS', $env);
        $value = $override !== null ? (int) $override : (int) ($this->raw['timeoutMs'] ?? 10000);

        return max(1000, min($value, 60000));
    }

    /**
     * @param  array<string, string>  $env
     */
    public function logLevel(array $env = []): string
    {
        return $this->fromEnv('DEPMAN_LOG_LEVEL', $env) ?? (string) ($this->raw['logLevel'] ?? 'warn');
    }

    public function runsOnPostinstall(): bool
    {
        return (bool) ($this->raw['runOn']['postinstall'] ?? true);
    }

    public function manifestPath(): string
    {
        return (string) ($this->raw['manifest']['path'] ?? '.');
    }

    /**
     * @param  array<string, string>  $env
     */
    public function spoolDirectory(array $env = []): string
    {
        $dir = $this->fromEnv('DEPMAN_SPOOL_DIR', $env);

        if ($dir === null && isset($this->raw['offline']['spoolDir']) && is_string($this->raw['offline']['spoolDir'])
            && $this->raw['offline']['spoolDir'] !== '') {
            $dir = $this->raw['offline']['spoolDir'];
        }

        if ($dir === null) {
            return $this->defaultSpoolDirectory($env);
        }

        // An explicit choice is honoured as given: absolute stays absolute, and
        // a relative path is the project's, as in every other client.
        if (self::isAbsolute($dir)) {
            return rtrim($dir, '/\\');
        }

        return rtrim($this->projectRoot, '/').'/'.ltrim($dir, '/');
    }

    /**
     * Where the spool goes when nobody said: **outside the WordPress root.**
     *
     * Every other client spools to `.depman/spool` in the project, which is
     * fine for a source tree and wrong here. A WordPress root is the document
     * root, and a spooled payload is a full inventory of the site's plugins and
     * versions -- exactly the map an attacker wants -- so it must never be
     * somewhere the web server can serve. The home directory of whoever runs
     * WP-CLI is not, and one directory per site keeps two sites on one account
     * from draining each other's reports.
     *
     * @param  array<string, string>  $env
     */
    private function defaultSpoolDirectory(array $env): string
    {
        $home = $this->processEnv('HOME', $env) ?? $this->processEnv('USERPROFILE', $env);
        $base = $home !== null
            ? rtrim($home, '/\\').'/.depman'
            : rtrim(sys_get_temp_dir(), '/\\').'/depman';

        return $base.'/spool/'.substr(sha1($this->projectRoot), 0, 12);
    }

    private static function isAbsolute(string $path): bool
    {
        return strpos($path, '/') === 0 || strpos($path, '\\') === 0 || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    /**
     * A process-environment variable only -- never `.env`, which is the
     * project's and has no business deciding where the home directory is.
     *
     * @param  array<string, string>  $env
     */
    private function processEnv(string $key, array $env): ?string
    {
        if (array_key_exists($key, $env)) {
            return $env[$key] === '' ? null : $env[$key];
        }

        $value = getenv($key);

        return ($value === false || $value === '') ? null : $value;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function offlineMode(array $env = []): string
    {
        $mode = $this->fromEnv('DEPMAN_OFFLINE_MODE', $env)
            ?? (string) ($this->raw['offline']['mode'] ?? 'spool');

        return in_array($mode, ['spool', 'skip'], true) ? $mode : 'spool';
    }

    /**
     * Where a setting's value came from, for `doctor`.
     *
     * Worth printing now that `.env` shadows the process environment for every
     * setting and not just the token: "the endpoint is wrong and I have
     * exported the right one" has three possible answers, and without this line
     * the first support ticket is unanswerable. `resolvedFrom` already does the
     * same job for the environment and `FoundToken::$source` for the token.
     *
     * @param  array<string, string>  $env
     */
    public function sourceOf(string $key, string $envKey, array $env = []): string
    {
        if ($this->fromDotEnv($envKey) !== null) {
            return '.env';
        }

        if (array_key_exists($envKey, $env) ? $env[$envKey] !== '' : (($v = getenv($envKey)) !== false && $v !== '')) {
            return $envKey;
        }

        return array_key_exists($key, $this->raw) ? 'depman.json' : 'the default';
    }

    /**
     * One setting, from `.env` first and then the process environment.
     *
     * @param  array<string, string>  $env
     */
    private function fromEnv(string $key, array $env): ?string
    {
        $fromDotEnv = $this->fromDotEnv($key);

        if ($fromDotEnv !== null) {
            return $fromDotEnv;
        }

        if (array_key_exists($key, $env)) {
            return $env[$key] === '' ? null : $env[$key];
        }

        $value = getenv($key);

        return ($value === false || $value === '') ? null : $value;
    }

    /**
     * The project's `.env`, for keys this class is allowed to read.
     */
    private function fromDotEnv(string $key): ?string
    {
        if (strpos($key, 'DEPMAN_') !== 0 && !in_array($key, self::DOTENV_READABLE, true)) {
            return null;
        }

        if (!array_key_exists($key, $this->dotEnvCache)) {
            $this->dotEnvCache[$key] = DotEnv::get($this->projectRoot, $key);
        }

        return $this->dotEnvCache[$key];
    }

    private function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
