<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Client;
use Depman\WpCli\Config;
use Depman\WpCli\Logger;
use Depman\WpCli\Reporter;
use Depman\WpCli\Resolver;
use Depman\WpCli\Site;
use PHPUnit\Framework\TestCase;

/**
 * What a report attempt decides, given a server response.
 *
 * The rule underneath every case here: **only a 4xx is a failure.** No token,
 * no config, an unreachable server and a rate limit are all ordinary situations
 * in a tool that runs from somebody else's cron, and treating any of them as an
 * error would eventually get the client removed.
 *
 * The tree comes from a fixed Site rather than a live WordPress: the resolver's
 * identity rules are ResolverTest's subject, and this is about what happens to
 * whatever it returns.
 */
class ClientTest extends TestCase
{
    private string $root;

    /** The home directory the default spool lives under -- never the site root. */
    private string $home;

    private ?Site $site = null;

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/depman-client-'.bin2hex(random_bytes(6));
        $this->home = $this->root.'-home';
        mkdir($this->root, 0775, true);
        mkdir($this->home, 0775, true);

        $this->stream = fopen('php://memory', 'rw+');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        $this->deleteTree($this->home);

        parent::tearDown();
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;
            is_dir($full) ? $this->deleteTree($full) : unlink($full);
        }

        rmdir($path);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function project(array $config = [], bool $withTree = true): void
    {
        file_put_contents($this->root.'/depman.json', json_encode($config + ['project' => 'acme/storefront']));

        // One plugin wordpress.org has identified, and no core: one package.
        $this->site = $withTree
            ? new Site(
                null,
                ['akismet/akismet.php' => ['Name' => 'Akismet Anti-spam', 'Version' => '5.3.3']],
                ['akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet', 'slug' => 'akismet']]
            )
            : null;
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @param  array{status: int, body: string}|null  $response
     */
    private function client(?array $response): Client
    {
        $logger = new Logger('debug', $this->stream);

        return new Client($logger, new Resolver(new FixedSiteReader($this->site), $logger), new FakeReporter($logger, $response));
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return ['DEPMAN_TOKEN' => 'dpm_live_test', 'DEPMAN_ENV' => 'production', 'HOME' => $this->home];
    }

    /**
     * Spooled payloads, wherever the default put them.
     *
     * @return array<int, string>
     */
    private function spooled(): array
    {
        return glob($this->home.'/.depman/spool/*/*.json') ?: [];
    }

    public function test_a_missing_config_is_a_skip_not_a_failure(): void
    {
        $result = $this->client(['status' => 202, 'body' => '{}'])->report($this->root, 'manual', $this->env());

        $this->assertSame('skipped', $result->status);
        $this->assertSame('no depman.json', $result->detail);
        $this->assertFalse($result->isFailure());
    }

    public function test_a_missing_token_is_a_skip_and_says_how_to_fix_it(): void
    {
        $this->project();

        // The normal state for a contributor who just cloned the repository. It
        // must be harmless, and it must not be silent about the remedy.
        $result = $this->client(['status' => 202, 'body' => '{}'])
            ->report($this->root, 'manual', ['DEPMAN_HOME' => $this->root]);

        $this->assertSame('skipped', $result->status);
        $this->assertSame('no token', $result->detail);
        $this->assertStringContainsString('DEPMAN_TOKEN', $this->logged());
    }

    public function test_disabling_it_in_config_stops_the_report(): void
    {
        $this->project(['enabled' => false]);

        $this->assertSame('disabled', $this->client(null)->report($this->root, 'manual', $this->env())->detail);
    }

    public function test_run_on_postinstall_false_still_permits_a_manual_report(): void
    {
        $this->project(['runOn' => ['postinstall' => false]]);

        $this->assertSame(
            'post-install reporting is disabled',
            $this->client(null)->report($this->root, 'postinstall', $this->env())->detail,
        );

        $this->assertSame(
            'reported',
            $this->client(['status' => 202, 'body' => '{}'])->report($this->root, 'manual', $this->env())->status,
        );
    }

    public function test_a_missing_project_key_is_a_skip(): void
    {
        file_put_contents($this->root.'/depman.json', json_encode(['endpoint' => 'https://example.test']));

        $this->assertSame(
            'no project configured',
            $this->client(null)->report($this->root, 'manual', $this->env())->detail,
        );
    }

    public function test_a_missing_installed_tree_is_a_skip(): void
    {
        $this->project([], false);

        $this->assertSame(
            'no installed tree',
            $this->client(null)->report($this->root, 'manual', $this->env())->detail,
        );
    }

    public function test_a_202_reports_the_package_count(): void
    {
        $this->project();

        $result = $this->client([
            'status' => 202,
            'body' => json_encode(['deduplicated' => false, 'packagesAccepted' => 1]),
        ])->report($this->root, 'manual', $this->env());

        $this->assertSame('reported', $result->status);
        $this->assertSame(1, $result->packages);
        $this->assertFalse($result->deduplicated);
    }

    public function test_the_servers_status_url_is_carried_back_for_gating(): void
    {
        $this->project();

        // The only input CI gating has. Reconstructing it from the endpoint and
        // a report id would mean the client learning a URL shape the server
        // already told it.
        $result = $this->client([
            'status' => 202,
            'body' => json_encode(['statusUrl' => 'https://depman.example/api/v1/reports/01J8XQ']),
        ])->report($this->root, 'manual', $this->env());

        $this->assertSame('https://depman.example/api/v1/reports/01J8XQ', $result->statusUrl);
    }

    public function test_a_report_with_no_status_url_carries_null_rather_than_an_empty_string(): void
    {
        $this->project();

        // An unsupported ecosystem is accepted as inventory and creates no
        // Report, so there is nothing to poll. Null says that; '' does not.
        $result = $this->client(['status' => 202, 'body' => json_encode(['statusUrl' => null])])
            ->report($this->root, 'manual', $this->env());

        $this->assertNull($result->statusUrl);
    }

    public function test_a_200_marks_the_report_deduplicated(): void
    {
        $this->project();

        $result = $this->client([
            'status' => 200,
            'body' => json_encode(['deduplicated' => true]),
        ])->report($this->root, 'manual', $this->env());

        $this->assertSame('reported', $result->status);
        $this->assertTrue($result->deduplicated);
        $this->assertStringContainsString('unchanged since the last report', $this->logged());
    }

    public function test_server_warnings_are_surfaced(): void
    {
        $this->project();

        $this->client([
            'status' => 202,
            'body' => json_encode(['warnings' => [['code' => 'digest_mismatch', 'detail' => 'they differ']]]),
        ])->report($this->root, 'manual', $this->env());

        $this->assertStringContainsString('digest_mismatch: they differ', $this->logged());
    }

    public function test_a_rate_limit_spools_rather_than_failing(): void
    {
        $this->project();

        // A developer adding packages one at a time will hit this, and it must
        // never look like something broke.
        $result = $this->client(['status' => 429, 'body' => '{}'])->report($this->root, 'manual', $this->env());

        $this->assertSame('spooled', $result->status);
        $this->assertFalse($result->isFailure());
        $this->assertCount(1, $this->spooled());
        // A WordPress root is the document root, and a spooled payload is the
        // site's whole plugin inventory: nothing may land under it by default.
        $this->assertDirectoryDoesNotExist($this->root.'/.depman');
    }

    public function test_a_server_error_spools_rather_than_failing(): void
    {
        $this->project();

        $result = $this->client(['status' => 503, 'body' => ''])->report($this->root, 'manual', $this->env());

        $this->assertSame('spooled', $result->status);
        $this->assertCount(1, $this->spooled());
    }

    public function test_an_unreachable_server_spools_rather_than_failing(): void
    {
        $this->project();

        $result = $this->client(null)->report($this->root, 'manual', $this->env());

        $this->assertSame('spooled', $result->status);
        $this->assertSame('could not reach DepMan', $result->detail);
    }

    public function test_offline_mode_skip_does_not_spool(): void
    {
        $this->project(['offline' => ['mode' => 'skip']]);

        $result = $this->client(null)->report($this->root, 'manual', $this->env());

        $this->assertSame('skipped', $result->status);
        $this->assertSame([], $this->spooled());
    }

    public function test_a_4xx_is_a_failure_and_is_never_spooled(): void
    {
        $this->project();

        // A configuration problem the user has to see and fix. Spooling it
        // would queue something guaranteed to be rejected again.
        $result = $this->client([
            'status' => 403,
            'body' => json_encode(['error' => ['code' => 'environment_not_allowed', 'message' => 'Not permitted.']]),
        ])->report($this->root, 'manual', $this->env());

        $this->assertSame('failed', $result->status);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('environment_not_allowed', $result->detail);
        $this->assertSame([], $this->spooled());
    }

    public function test_the_token_value_is_never_logged(): void
    {
        $this->project();

        $this->client(['status' => 202, 'body' => '{}'])->report($this->root, 'manual', $this->env());

        $log = $this->logged();

        $this->assertStringNotContainsString('dpm_live_test', $log);
        // The source, though, is the whole point of the line.
        $this->assertStringContainsString('token found via DEPMAN_TOKEN', $log);
    }

    public function test_the_payload_carries_the_resolved_environment_and_tree(): void
    {
        $this->project();

        $reporter = new FakeReporter(new Logger('silent', $this->stream), ['status' => 202, 'body' => '{}']);
        $logger = new Logger('silent', $this->stream);

        (new Client($logger, new Resolver(new FixedSiteReader($this->site), $logger), $reporter))
            ->report($this->root, 'manual', $this->env());

        $payload = $reporter->sent;

        $this->assertNotNull($payload);
        $this->assertSame('acme/storefront', $payload['project']);
        $this->assertSame('production', $payload['environment']['name']);
        $this->assertSame('DEPMAN_ENV', $payload['environment']['resolvedFrom']);
        $this->assertSame('composer', $payload['ecosystem']);
        $this->assertSame('pkg:composer/wpackagist-plugin/akismet@5.3.3', $payload['packages'][0]['purl']);
        $this->assertSame('direct', $payload['packages'][0]['relationship']);
        $this->assertSame('wordpress-plugin', $payload['packages'][0]['type']);
        $this->assertSame('depman/wp-cli-client', $payload['client']['name']);
        $this->assertSame('wp-cli', $payload['client']['packageManager']['name']);
        $this->assertSame(['path' => '.'], $payload['manifest']);
    }
}

/**
 * Records what would have been sent and returns a canned response.
 *
 * Reporter is the client's only network seam, which is why it is not final.
 */
final class FakeReporter extends Reporter
{
    /** @var array<string, mixed>|null */
    public ?array $sent = null;

    /** @var array{status: int, body: string}|null */
    private $response;

    /**
     * @param  array{status: int, body: string}|null  $response
     */
    public function __construct(Logger $logger, ?array $response)
    {
        parent::__construct($logger);
        $this->response = $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}|null
     */
    public function send(string $endpoint, string $token, array $payload, int $timeoutMs): ?array
    {
        $this->sent = $payload;

        return $this->response;
    }
}
