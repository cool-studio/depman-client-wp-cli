<?php

namespace Depman\WpCli;

use Throwable;
use WP_CLI;

/**
 * Report this site's plugins, themes and WordPress core to DepMan.
 *
 * WordPress has no install lifecycle to hang a hook off, so this runs when it
 * is run: from the site's own cron, or a CI or deploy step (ADR-0056). A site
 * that stops running it goes stale in DepMan, and DepMan alerts on that.
 *
 * ## EXAMPLES
 *
 *     # Report now
 *     $ wp depman report
 *
 *     # Report every six hours, from the server's crontab
 *     0 *\/6 * * * cd /var/www/example.com && DEPMAN_TOKEN=... wp depman report --quiet
 */
final class Command
{
    /** @var SiteReader */
    private $reader;

    /** @var callable(Logger): Reporter */
    private $reporters;

    /** @var callable(string): void */
    private $out;

    /** @var string|null */
    private $root;

    /** @var array<string, string> */
    private $env;

    /**
     * WP-CLI constructs this with no arguments; tests pass every seam.
     *
     * @param  (callable(Logger): Reporter)|null  $reporters
     * @param  (callable(string): void)|null  $out
     * @param  array<string, string>  $env
     */
    public function __construct(
        ?SiteReader $reader = null,
        ?callable $reporters = null,
        ?callable $out = null,
        ?string $root = null,
        array $env = []
    ) {
        $this->reader = $reader ?? new WordPressSite;
        $this->reporters = $reporters ?? static function (Logger $logger): Reporter {
            return new Reporter($logger);
        };
        $this->out = $out ?? static function (string $line): void {
            // WP_CLI::log, not a raw write: it honours --quiet, and the
            // documented crontab line passes --quiet so that cron does not
            // mail the site owner a status line every six hours. Warnings and
            // errors go to stderr through Logger and still arrive.
            if (class_exists('WP_CLI', false)) {
                WP_CLI::log(rtrim($line, "\n"));

                return;
            }

            fwrite(STDOUT, $line);
        };
        $this->root = $root;
        $this->env = $env;
    }

    /**
     * Report the plugins, themes and core installed on this site.
     *
     * Exits 0 whether the report was delivered, skipped or spooled. Only an
     * explicit --fail-on-error turns a rejected report into a failure, and only
     * --wait or --fail-on can fail on what DepMan found.
     *
     * ## OPTIONS
     *
     * [--fail-on-error]
     * : Exit 1 when DepMan rejects the report.
     *
     * [--wait]
     * : Wait for DepMan to scan the report, then print the open findings.
     *
     * [--fail-on=<severity>]
     * : Exit 1 if any open finding in this environment is at this severity or worse: critical, high, medium, low or unknown. Exit 2 if DepMan could not say. Implies --wait.
     *
     * [--wait-timeout=<seconds>]
     * : How long --wait waits. Default 300, clamped to 10-3600.
     *
     * [--log-level=<level>]
     * : silent, error, warn, info or debug. Default warn.
     *
     * @param  array<int, string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function report(array $args, array $assoc): void
    {
        $this->halt($this->runReport($assoc));
    }

    /**
     * Send every report left in the offline spool, oldest first.
     *
     * ## OPTIONS
     *
     * [--fail-on-error]
     * : Exit 1 when DepMan rejects a spooled report.
     *
     * [--log-level=<level>]
     * : silent, error, warn, info or debug. Default warn.
     *
     * @param  array<int, string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function push(array $args, array $assoc): void
    {
        $this->halt($this->runPush($assoc));
    }

    /**
     * Show the resolved configuration, where each setting came from, and how
     * much of this site can be identified.
     *
     * @param  array<int, string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function doctor(array $args, array $assoc): void
    {
        $this->halt($this->runDoctor($assoc));
    }

    /**
     * @param  array<string, string|bool>  $assoc
     */
    public function runReport(array $assoc): int
    {
        $logger = $this->logger($assoc);
        $root = $this->root();
        $gating = isset($assoc['wait']) || isset($assoc['fail-on']);

        try {
            $reporter = ($this->reporters)($logger);
            $client = new Client($logger, new Resolver($this->reader, $logger), $reporter);
            $result = $client->report($root, $this->reason(), $this->env);

            $this->write($this->describeReport($result));

            if ($gating) {
                return $this->gate($root, $logger, $reporter, $assoc, $result);
            }

            return $result->isFailure() && $this->failOnError($assoc, $root, $logger) ? 1 : 0;
        } catch (Throwable $e) {
            // Nothing above should throw. If something does it is reported and
            // the command exits as a failure would: 0 unless asked otherwise,
            // and "could not find out" when gating, never a pass.
            $logger->error($e->getMessage());

            if ($gating) {
                return 2;
            }

            return $this->failOnError($assoc, $root, $logger) ? 1 : 0;
        }
    }

    /**
     * @param  array<string, string|bool>  $assoc
     */
    public function runPush(array $assoc): int
    {
        $logger = $this->logger($assoc);
        $root = $this->root();

        try {
            $push = (new Pusher($logger, ($this->reporters)($logger)))->push($root, $this->env);

            if ($push->status === 'drained') {
                $this->write($push->sent === 0 && $push->discarded === 0
                    ? 'Nothing spooled.'
                    : sprintf(
                        'Pushed %d spooled report%s.%s',
                        $push->sent,
                        $push->sent === 1 ? '' : 's',
                        $push->discarded === 0 ? '' : sprintf(' Discarded %d that will never be accepted.', $push->discarded)
                    ));
            } elseif ($push->status === 'stopped') {
                $this->write(sprintf(
                    'Pushed %d spooled report%s; %d still queued (%s).',
                    $push->sent,
                    $push->sent === 1 ? '' : 's',
                    $push->remaining,
                    $push->detail
                ));
            } else {
                $this->write('Skipped: '.$push->detail);
            }

            return $push->isFailure() && $this->failOnError($assoc, $root, $logger) ? 1 : 0;
        } catch (Throwable $e) {
            $logger->error($e->getMessage());

            return $this->failOnError($assoc, $root, $logger) ? 1 : 0;
        }
    }

    /**
     * @param  array<string, string|bool>  $assoc
     */
    public function runDoctor(array $assoc): int
    {
        $logger = $this->logger($assoc);
        $root = $this->root();
        $config = Config::load($root, $logger, $this->env);

        if ($config === null) {
            $this->write("No depman.json found in {$root}.");

            return 0;
        }

        $endpoint = $config->endpoint($this->env);
        $environment = $config->environment($this->env);
        $token = Token::locate($endpoint, $this->env, $root);

        $this->write(sprintf('Project:      %s (from %s)', $config->project($this->env) ?? '(not set)', $config->sourceOf('project', 'DEPMAN_PROJECT', $this->env)));
        $this->write(sprintf('Endpoint:     %s (from %s)', $endpoint, $config->sourceOf('endpoint', 'DEPMAN_ENDPOINT', $this->env)));
        $this->write(sprintf('Environment:  %s (from %s)', $environment['name'], $environment['resolvedFrom']));
        $this->write('Token:        '.($token === null
            ? 'not found — set DEPMAN_TOKEN in the environment, or DEPMAN_TOKEN_FILE'
            : 'found via '.$token->source));

        if ($token !== null && $token->source === '.env') {
            // The WordPress root is the web server's document root on most
            // hosts, and a .env there is a file it will serve unless told not
            // to. Say so where somebody is already looking.
            $this->write('              ! .env is inside the WordPress root; make sure the web server refuses to serve it.');
        }

        $this->write('Spool:        '.$config->spoolDirectory($this->env));

        $site = $this->reader->read();

        if ($site === null) {
            $this->write('WordPress:    not loaded');

            return 0;
        }

        $entries = (new Resolver($this->reader, $logger))->entries($site);
        $local = count(array_filter($entries, static function (array $entry): bool {
            return strpos((string) $entry['name'], '--local/') !== false;
        }));

        $this->write(sprintf('WordPress:    %s', $site->coreVersion ?? 'version unknown'));
        $this->write(sprintf(
            'Identified:   %d of %d packages on wordpress.org; %d reported as inventory only',
            count($entries) - $local,
            count($entries),
            $local
        ));

        return 0;
    }

    /**
     * `report --wait [--fail-on=<severity>]`, printed and turned into an exit
     * code. Everything that decides anything is in Gate (§10).
     *
     * @param  array<string, string|bool>  $assoc
     */
    private function gate(string $root, Logger $logger, Reporter $reporter, array $assoc, Result $result): int
    {
        $threshold = isset($assoc['fail-on']) && is_string($assoc['fail-on']) ? strtolower($assoc['fail-on']) : null;

        // Clamped rather than honoured: an absurd value is a typo, and a gate
        // that waits an hour has stopped being a gate.
        $requested = isset($assoc['wait-timeout']) ? (int) $assoc['wait-timeout'] : 300;
        $timeout = max(10, min($requested, 3600));

        $verdict = (new Gate($logger, $reporter))->run($root, $result, $threshold, $timeout, $this->env);

        $summary = $verdict->summary();

        if ($summary !== null) {
            $this->write(sprintf('Open findings: %s.', $summary));
        }

        if ($verdict->status === GateResult::PASSED) {
            $this->write($threshold === null ? 'Scan complete.' : sprintf('Gate passed: nothing at %s or above.', $threshold));
        } elseif ($verdict->status === GateResult::BREACHED) {
            $this->write(sprintf('Gate FAILED: %s.', $verdict->detail));
        } else {
            $this->write(sprintf('Gate could not decide: %s', $verdict->detail));
        }

        return $verdict->exitCode();
    }

    private function describeReport(Result $result): string
    {
        switch ($result->status) {
            case 'reported':
                return sprintf(
                    'Reported %d packages.%s',
                    $result->packages,
                    $result->deduplicated ? ' (unchanged since the last report)' : ''
                );
            case 'spooled':
                return sprintf('Could not reach DepMan (%s); spooled for later.', $result->detail);
            case 'failed':
                return sprintf('Report rejected: %s', $result->detail);
            default:
                return sprintf('Skipped: %s', $result->detail);
        }
    }

    /**
     * @param  array<string, string|bool>  $assoc
     */
    private function failOnError(array $assoc, string $root, Logger $logger): bool
    {
        if (isset($assoc['fail-on-error'])) {
            return true;
        }

        $config = Config::load($root, $logger, $this->env);

        return $config !== null && $config->failOnError($this->env);
    }

    /**
     * @param  array<string, string|bool>  $assoc
     */
    private function logger(array $assoc): Logger
    {
        $level = isset($assoc['log-level']) && is_string($assoc['log-level'])
            ? $assoc['log-level']
            : ($this->env['DEPMAN_LOG_LEVEL'] ?? (getenv('DEPMAN_LOG_LEVEL') ?: 'warn'));

        return new Logger($level);
    }

    /**
     * The WordPress root: where wp-load.php is, and where depman.json goes.
     */
    private function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        return defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/\\') : (string) getcwd();
    }

    /**
     * `ci` when a CI system is driving, `manual` otherwise. There is no
     * `postinstall` here: WordPress has no install hook to report from.
     */
    private function reason(): string
    {
        foreach (['CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE', 'CIRCLECI', 'JENKINS_URL'] as $key) {
            $value = $this->env[$key] ?? getenv($key);

            if (is_string($value) && $value !== '' && $value !== 'false' && $value !== '0') {
                return 'ci';
            }
        }

        return 'manual';
    }

    private function write(string $line): void
    {
        ($this->out)($line."\n");
    }

    private function halt(int $code): void
    {
        WP_CLI::halt($code);
    }
}
