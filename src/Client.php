<?php

namespace Depman\WpCli;

/**
 * Orchestrates a single report.
 *
 * Returns a result rather than throwing or exiting, so the caller decides what
 * failure means. From a post-install hook, failure means nothing at all.
 */
final class Client
{
    /** @var Logger */
    private $logger;

    /** @var Resolver */
    private $resolver;

    /** @var Reporter */
    private $reporter;

    public function __construct(Logger $logger, Resolver $resolver, Reporter $reporter)
    {
        $this->logger = $logger;
        $this->resolver = $resolver;
        $this->reporter = $reporter;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function report(string $projectRoot, string $reason = 'manual', array $env = []): Result
    {
        $config = Config::load($projectRoot, $this->logger, $env);

        if ($config === null) {
            return Result::skipped('no depman.json');
        }

        if (!$config->isEnabled($env)) {
            $this->logger->debug('disabled by configuration.');

            return Result::skipped('disabled');
        }

        if ($reason === 'postinstall' && !$config->runsOnPostinstall()) {
            return Result::skipped('post-install reporting is disabled');
        }

        $project = $config->project($env);

        if ($project === null) {
            $this->logger->warn('depman.json has no "project"; skipping.');

            return Result::skipped('no project configured');
        }

        $endpoint = $config->endpoint($env);
        $found = Token::locate($endpoint, $env, $projectRoot);

        if ($found === null) {
            // The normal state for an open-source contributor who just cloned
            // the repository. It must be harmless and near-silent.
            $this->logger->info('no token found, skipping. Set DEPMAN_TOKEN in .env or the environment to report.');

            return Result::skipped('no token');
        }

        // The source, never the value.
        $this->logger->debug('token found via '.$found->source.'.');
        $token = $found->value;

        $packages = $this->resolver->resolve($projectRoot, $config->includeDev($env));

        if ($packages === null) {
            return Result::skipped('no installed tree');
        }

        $environment = $config->environment($env);
        $payload = $this->reporter->buildPayload($config, $packages, $environment, $reason);

        $response = $this->reporter->send($endpoint, $token, $payload, $config->timeoutMs($env));

        if ($response === null) {
            return $this->handleUndelivered($config, $payload, $env, 'could not reach DepMan');
        }

        return $this->interpret($config, $payload, $response, $environment['name'], count($packages), $env);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{status: int, body: string}  $response
     * @param  array<string, string>  $env
     */
    private function interpret(Config $config, array $payload, array $response, string $environment, int $count, array $env): Result
    {
        $status = $response['status'];
        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status === 200 || $status === 202) {
            $deduplicated = (bool) ($decoded['deduplicated'] ?? false);

            $this->logger->info(sprintf(
                'reported %d packages (%s)%s',
                $count,
                $environment,
                $deduplicated ? ' — unchanged since the last report' : ''
            ));

            foreach ((array) ($decoded['warnings'] ?? []) as $warning) {
                if (is_array($warning) && isset($warning['code'])) {
                    $this->logger->warn((string) $warning['code'].': '.(string) ($warning['detail'] ?? ''));
                }
            }

            $statusUrl = $decoded['statusUrl'] ?? null;

            return Result::reported(
                $count,
                $deduplicated,
                is_string($statusUrl) && $statusUrl !== '' ? $statusUrl : null
            );
        }

        // A rate limit is not a failure. A developer adding packages one at a
        // time will hit it, and that must never look like something broke.
        if ($status === 429) {
            $this->logger->debug('rate limited; spooling for later.');

            return $this->handleUndelivered($config, $payload, $env, 'rate limited');
        }

        if ($status >= 500) {
            return $this->handleUndelivered($config, $payload, $env, 'DepMan is unavailable');
        }

        $code = (string) ($decoded['error']['code'] ?? 'http_'.$status);
        $message = (string) ($decoded['error']['message'] ?? 'The report was rejected.');

        // 4xx is a configuration problem the user needs to see and fix; there
        // is no point spooling something that will be rejected again.
        $this->logger->error($code.': '.$message);

        return Result::failed($code.': '.$message);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $env
     */
    private function handleUndelivered(Config $config, array $payload, array $env, string $reason): Result
    {
        if ($config->offlineMode($env) === 'skip') {
            return Result::skipped($reason);
        }

        $spooled = (new Spool($config->spoolDirectory($env), $this->logger, $config->projectRoot))->write($payload);

        $this->logger->debug($reason.($spooled ? '; spooled for later.' : '; dropped.'));

        return Result::spooled($reason);
    }
}
