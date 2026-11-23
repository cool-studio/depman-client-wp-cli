<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Config;
use Depman\WpCli\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /** @var resource */
    private $stream;

    /** @var array<int, string> */
    private array $putenv = [];

    /** @var array<int, string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stream = fopen('php://memory', 'rw+');
    }

    protected function tearDown(): void
    {
        foreach ($this->putenv as $key) {
            putenv($key);
        }

        $this->putenv = [];

        foreach ($this->cleanup as $path) {
            foreach (glob($path.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @unlink($path.'/.env');
            @rmdir($path);
        }

        $this->cleanup = [];

        parent::tearDown();
    }

    /**
     * Set a real process environment variable for one test, and undo it after.
     */
    private function putenv(string $key, string $value): void
    {
        $this->putenv[] = $key;
        putenv($key.'='.$value);
    }

    /**
     * A throwaway project directory, optionally with a `.env` in it.
     */
    private function project(?string $dotEnv = null): string
    {
        $path = sys_get_temp_dir().'/depman-'.bin2hex(random_bytes(4));
        mkdir($path);
        $this->cleanup[] = $path;

        if ($dotEnv !== null) {
            file_put_contents($path.'/.env', $dotEnv);
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function configIn(string $projectRoot, array $raw = []): Config
    {
        return Config::fromArray($projectRoot, $raw, $this->logger());
    }

    public function test_every_setting_is_read_from_dot_env(): void
    {
        // The hook fires during `composer install`, before the consumer's
        // framework has booted, so nothing has loaded `.env` into the process
        // environment. Until step 21 only DEPMAN_TOKEN was read from there and
        // every other setting beside it was silently ignored.
        $config = $this->configIn($this->project(<<<'ENV'
            DEPMAN_ENDPOINT=https://depman.acme.test
            DEPMAN_PROJECT=storefront
            DEPMAN_ENV=staging
            DEPMAN_TIMEOUT_MS=25000
            DEPMAN_LOG_LEVEL=debug
            DEPMAN_INCLUDE_DEV=false
            DEPMAN_FAIL_ON_ERROR=true
            DEPMAN_OFFLINE_MODE=skip
            DEPMAN_SPOOL_DIR=.cache/depman
            ENV));

        $this->assertSame('https://depman.acme.test', $config->endpoint());
        $this->assertSame('storefront', $config->project());
        $this->assertSame('staging', $config->environment()['name']);
        $this->assertSame('DEPMAN_ENV', $config->environment()['resolvedFrom']);
        $this->assertSame(25000, $config->timeoutMs());
        $this->assertSame('debug', $config->logLevel());
        $this->assertFalse($config->includeDev());
        $this->assertTrue($config->failOnError());
        $this->assertSame('skip', $config->offlineMode());
        $this->assertStringEndsWith('/.cache/depman', $config->spoolDirectory());
    }

    public function test_dot_env_wins_over_the_process_environment(): void
    {
        // The same precedence the token has had since step 08. One answer to
        // "where does the client look?", not one per setting.
        $config = $this->configIn($this->project('DEPMAN_ENDPOINT=https://from-dot-env.test
'));

        $this->assertSame(
            'https://from-dot-env.test',
            $config->endpoint(['DEPMAN_ENDPOINT' => 'https://from-process.test']),
        );
    }

    public function test_the_built_in_endpoint_default_is_the_platform_host(): void
    {
        // Nothing configures the endpoint at all — no .env, no depman.json key.
        // The fallback is the host the platform is actually served from, and it
        // is the apex domain: there is no `api.` subdomain. This shipped wrong
        // once, which is cheap to do and invisible until somebody's reports
        // vanish into DNS that does not resolve.
        $this->assertSame('https://depman.io', $this->configIn($this->project())->endpoint());
    }

    public function test_the_process_environment_still_wins_over_depman_json(): void
    {
        // No .env at all: the documented precedence below it is unchanged.
        $config = $this->configIn($this->project(), ['endpoint' => 'https://from-json.test']);

        $this->assertSame(
            'https://from-process.test',
            $config->endpoint(['DEPMAN_ENDPOINT' => 'https://from-process.test']),
        );
        $this->assertSame('https://from-json.test', $config->endpoint());
    }

    public function test_an_empty_value_in_dot_env_falls_through_rather_than_blanking_the_setting(): void
    {
        $config = $this->configIn($this->project('DEPMAN_ENDPOINT=
'), ['endpoint' => 'https://from-json.test']);

        $this->assertSame('https://from-json.test', $config->endpoint());
    }

    public function test_app_env_is_read_from_dot_env(): void
    {
        // The bug this is really about. APP_ENV is the default second source
        // and it lives in .env, so before this every Laravel consumer reported
        // `local` no matter what they were actually running.
        $config = $this->configIn($this->project('APP_ENV=production
'));

        $this->assertSame(
            ['name' => 'production', 'resolvedFrom' => 'APP_ENV'],
            $config->environment(),
        );
    }

    public function test_environment_from_cannot_pull_an_arbitrary_secret_out_of_dot_env(): void
    {
        // `environment.from` is an arbitrary list of variable names in a
        // COMMITTED file, and its value is sent to the server as the
        // environment name. Without an allowlist, a one-line change to
        // depman.json exfiltrates whatever it points at.
        $root = $this->project('DB_PASSWORD=hunter2
APP_ENV=staging
');

        $config = $this->configIn($root, ['environment' => ['from' => ['DB_PASSWORD', 'APP_ENV']]]);

        $resolved = $config->environment();

        $this->assertSame('staging', $resolved['name']);
        $this->assertSame('APP_ENV', $resolved['resolvedFrom']);
        $this->assertStringNotContainsString('hunter2', json_encode($resolved) ?: '');
    }

    public function test_a_non_allowlisted_variable_is_still_read_from_the_process_environment(): void
    {
        // The allowlist bounds what may be read out of the SECRET STORE. It is
        // not a restriction on the process environment, which the consumer sets
        // deliberately and which carries no such concentration of secrets.
        $config = $this->configIn($this->project(), ['environment' => ['from' => ['MY_OWN_ENV']]]);

        $this->assertSame(
            ['name' => 'staging', 'resolvedFrom' => 'MY_OWN_ENV'],
            $config->environment(['MY_OWN_ENV' => 'staging']),
        );
    }

    public function test_it_reports_where_a_setting_came_from(): void
    {
        // doctor prints this. With .env shadowing the process environment for
        // every setting, "I exported the right endpoint and it is still wrong"
        // has three possible answers and this is the one line that separates
        // them.
        $config = $this->configIn($this->project("DEPMAN_ENDPOINT=https://from-dot-env.test\n"), [
            'project' => 'storefront',
        ]);

        $this->assertSame('.env', $config->sourceOf('endpoint', 'DEPMAN_ENDPOINT'));
        $this->assertSame('depman.json', $config->sourceOf('project', 'DEPMAN_PROJECT'));
        $this->assertSame(
            'DEPMAN_PROJECT',
            $config->sourceOf('project', 'DEPMAN_PROJECT', ['DEPMAN_PROJECT' => 'other']),
        );
        $this->assertSame('the default', $config->sourceOf('timeoutMs', 'DEPMAN_TIMEOUT_MS'));
    }

    private function logger(string $level = 'debug'): Logger
    {
        return new Logger($level, $this->stream);
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function config(array $raw, string $level = 'debug'): Config
    {
        return Config::fromArray('/tmp/project', $raw, $this->logger($level));
    }

    /**
     * @dataProvider tokenKeys
     */
    #[DataProvider('tokenKeys')]
    public function test_a_token_in_the_committed_config_is_refused_loudly(string $key): void
    {
        // depman.json is committed, so a token there is already leaked. The
        // warning prints regardless of log level — staying quiet helps nobody.
        $path = sys_get_temp_dir().'/depman-'.bin2hex(random_bytes(4));
        mkdir($path);
        file_put_contents($path.'/depman.json', json_encode(['project' => 'x', $key => 'dpm_live_secret']));

        Config::load($path, new Logger('silent', $this->stream));

        $this->assertStringContainsString($key, $this->logged());
        $this->assertStringContainsString('Rotate', $this->logged());

        unlink($path.'/depman.json');
        rmdir($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tokenKeys(): array
    {
        return [
            'token' => ['token'],
            'apiToken' => ['apiToken'],
            'secret' => ['secret'],
        ];
    }

    public function test_the_environment_falls_back_to_local_and_never_production(): void
    {
        // Mislabelling a laptop as production produces alerts nobody asked for
        // and buries the ones that matter.
        //
        // Named variables that are definitely unset: the resolver deliberately
        // falls through to the real process environment when a key is absent
        // from the supplied array, and APP_ENV is set while tests run.
        $resolved = $this->config([
            'environment' => ['from' => ['DEPMAN_TEST_UNSET_A', 'DEPMAN_TEST_UNSET_B']],
        ])->environment([]);

        $this->assertSame('local', $resolved['name']);
        $this->assertSame('fallback', $resolved['resolvedFrom']);
    }

    public function test_the_resolver_reads_the_real_process_environment_too(): void
    {
        // A client running inside a Laravel app inherits APP_ENV, which is the
        // whole point: the environment is resolved at run time, not committed.
        //
        // The variable is set here rather than borrowed from the runner. This
        // test used to assert 'testing' and pass only because the parent
        // application's phpunit.xml exported APP_ENV=testing — a dependency on
        // the host that is invisible until the suite is run anywhere else, and
        // precisely what a client suite must not have.
        $this->putenv('APP_ENV', 'staging');

        $resolved = $this->config(['environment' => ['from' => ['APP_ENV']]])->environment([]);

        $this->assertSame('staging', $resolved['name']);
        $this->assertSame('APP_ENV', $resolved['resolvedFrom']);
    }

    public function test_the_environment_resolves_from_the_first_matching_variable(): void
    {
        $resolved = $this->config(['environment' => ['from' => ['DEPMAN_ENV', 'APP_ENV']]])
            ->environment(['APP_ENV' => 'staging']);

        $this->assertSame('staging', $resolved['name']);
        $this->assertSame('APP_ENV', $resolved['resolvedFrom']);
    }

    public function test_the_first_source_wins(): void
    {
        $resolved = $this->config(['environment' => ['from' => ['DEPMAN_ENV', 'APP_ENV']]])
            ->environment(['DEPMAN_ENV' => 'production', 'APP_ENV' => 'local']);

        $this->assertSame('production', $resolved['name']);
    }

    public function test_environment_names_are_mapped(): void
    {
        $resolved = $this->config([
            'environment' => ['from' => ['APP_ENV'], 'map' => ['prod' => 'production']],
        ])->environment(['APP_ENV' => 'prod']);

        $this->assertSame('production', $resolved['name']);
    }

    public function test_a_disallowed_environment_is_coerced_rather_than_invented(): void
    {
        // Otherwise a typo silently creates a new environment nobody meant.
        $resolved = $this->config([
            'environment' => [
                'from' => ['APP_ENV'],
                'allowed' => ['local', 'production'],
                'fallback' => 'local',
            ],
        ])->environment(['APP_ENV' => 'prodcution']);

        $this->assertSame('local', $resolved['name']);
        $this->assertStringContainsString('not in the allowed list', $this->logged());
    }

    public function test_environment_variables_override_the_file(): void
    {
        $config = $this->config(['endpoint' => 'https://from-file.test', 'project' => 'from-file']);

        $this->assertSame('https://from-env.test', $config->endpoint(['DEPMAN_ENDPOINT' => 'https://from-env.test']));
        $this->assertSame('from-env', $config->project(['DEPMAN_PROJECT' => 'from-env']));
    }

    public function test_the_kill_switch_works_without_a_code_change(): void
    {
        $config = $this->config(['project' => 'x']);

        $this->assertTrue($config->isEnabled([]));
        $this->assertFalse($config->isEnabled(['DEPMAN_ENABLED' => 'false']));
        $this->assertFalse($config->isEnabled(['DEPMAN_ENABLED' => '0']));
    }

    public function test_the_timeout_is_clamped_to_something_sane(): void
    {
        $config = $this->config([]);

        $this->assertSame(10000, $config->timeoutMs([]));
        $this->assertSame(1000, $config->timeoutMs(['DEPMAN_TIMEOUT_MS' => '10']));
        $this->assertSame(60000, $config->timeoutMs(['DEPMAN_TIMEOUT_MS' => '999999']));
    }

    public function test_missing_config_is_not_an_error(): void
    {
        $this->assertNull(Config::load('/nonexistent/path', $this->logger()));
    }

    public function test_malformed_config_is_not_an_error(): void
    {
        $path = sys_get_temp_dir().'/depman-'.bin2hex(random_bytes(4));
        mkdir($path);
        file_put_contents($path.'/depman.json', '{ not json');

        $this->assertNull(Config::load($path, $this->logger()));

        unlink($path.'/depman.json');
        rmdir($path);
    }

    public function test_the_default_spool_lives_outside_the_wordpress_root(): void
    {
        // A WordPress root is the web server's document root, and a spooled
        // payload is the site's whole plugin inventory. The default every other
        // client uses -- .depman/spool in the project -- would be served.
        $config = $this->configIn('/var/www/example.com/public');

        $directory = $config->spoolDirectory(['HOME' => '/home/deploy']);

        $this->assertStringStartsWith('/home/deploy/.depman/spool/', $directory);
        $this->assertStringStartsNotWith('/var/www/example.com/public', $directory);
    }

    public function test_each_site_gets_its_own_default_spool(): void
    {
        // Two sites run by one account must not drain each other's reports.
        $env = ['HOME' => '/home/deploy'];

        $this->assertNotSame(
            $this->configIn('/var/www/one')->spoolDirectory($env),
            $this->configIn('/var/www/two')->spoolDirectory($env)
        );
        $this->assertSame(
            $this->configIn('/var/www/one')->spoolDirectory($env),
            $this->configIn('/var/www/one')->spoolDirectory($env)
        );
    }

    public function test_the_default_spool_falls_back_to_userprofile_then_the_temp_directory(): void
    {
        $config = $this->configIn('/var/www/site');

        $this->assertStringStartsWith('C:/Users/deploy/.depman/spool/', $config->spoolDirectory(['HOME' => '', 'USERPROFILE' => 'C:/Users/deploy']));
        $this->assertStringStartsWith(
            rtrim(sys_get_temp_dir(), '/').'/depman/spool/',
            $config->spoolDirectory(['HOME' => '', 'USERPROFILE' => ''])
        );
    }

    public function test_an_explicit_relative_spool_directory_still_resolves_inside_the_project(): void
    {
        // The user's explicit choice is honoured, exactly as in every other
        // client -- including choosing somewhere inside the site.
        $config = $this->configIn('/var/www/site', ['offline' => ['spoolDir' => '.depman/spool']]);

        $this->assertSame('/var/www/site/.depman/spool', $config->spoolDirectory(['HOME' => '/home/deploy']));
    }

    public function test_an_explicit_absolute_spool_directory_stays_absolute(): void
    {
        $config = $this->configIn('/var/www/site', ['offline' => ['spoolDir' => '/srv/depman-spool/']]);

        $this->assertSame('/srv/depman-spool', $config->spoolDirectory(['HOME' => '/home/deploy']));
        $this->assertSame('/tmp/elsewhere', $config->spoolDirectory(['DEPMAN_SPOOL_DIR' => '/tmp/elsewhere']));
    }
}
