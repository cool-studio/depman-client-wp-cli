<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Command;
use Depman\WpCli\Logger;
use Depman\WpCli\Reporter;
use Depman\WpCli\Site;
use Depman\WpCli\SiteReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `wp depman`, driven without WP-CLI.
 *
 * Nothing here is a post-install hook, but §0 still applies: the command runs
 * unattended from a site's cron, and an exception or a non-zero exit there is
 * noise in somebody's mailbox at 3am for a tool that is meant to be invisible.
 * Every outcome exits 0 unless the user asked otherwise.
 */
class CommandTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var list<string> */
    private $output = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/depman-wp-command-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home', 0777, true);
        $this->output = [];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_no_depman_json_is_a_silent_skip(): void
    {
        $this->assertSame(0, $this->command(new RecordingReporter(null))->runReport([]));
        $this->assertSame(["Skipped: no depman.json\n"], $this->output);
    }

    public function test_a_malformed_depman_json_is_not_a_failure(): void
    {
        file_put_contents($this->root.'/depman.json', '{"project": ');

        $this->assertSame(0, $this->command(new RecordingReporter(null))->runReport([]));
    }

    public function test_it_reports_the_site_as_a_composer_ecosystem_report(): void
    {
        $this->configure();
        $reporter = new RecordingReporter(['status' => 202, 'body' => '{"status":"accepted"}']);

        $this->assertSame(0, $this->command($reporter)->runReport([]));

        $payload = $reporter->sent;
        $this->assertIsArray($payload);
        $this->assertSame('composer', $payload['ecosystem']);
        $this->assertSame('depman/wp-cli-client', $payload['client']['name']);
        $this->assertSame('wp-cli', $payload['client']['packageManager']['name']);
        $this->assertSame(['path' => '.'], $payload['manifest']);
        $this->assertSame('manual', $payload['report']['reason']);
        $this->assertSame([
            'pkg:composer/johnpbloch/wordpress-core@6.8.3',
            'pkg:composer/wordpress-plugin--local/acme-pro@2.1.0',
            'pkg:composer/wpackagist-plugin/akismet@5.3.3',
        ], array_column($payload['packages'], 'purl'));
        $this->assertSame("Reported 3 packages.\n", $this->output[0]);
    }

    public function test_the_token_never_reaches_the_payload(): void
    {
        $this->configure();
        $reporter = new RecordingReporter(['status' => 202, 'body' => '{}']);

        $this->command($reporter)->runReport([]);

        $this->assertStringNotContainsString('dpm_test_secret', (string) json_encode($reporter->sent));
    }

    public function test_a_report_from_ci_says_so(): void
    {
        $this->configure();
        $reporter = new RecordingReporter(['status' => 202, 'body' => '{}']);

        $this->command($reporter, ['CI' => 'true'])->runReport([]);

        $this->assertSame('ci', $reporter->sent['report']['reason'] ?? null);
    }

    public function test_an_unreachable_server_is_spooled_and_exits_zero(): void
    {
        $this->configure();

        $this->assertSame(0, $this->command(new RecordingReporter(null))->runReport([]));
        $this->assertStringStartsWith('Could not reach DepMan', $this->output[0]);
    }

    public function test_a_rejected_report_exits_zero_unless_asked_otherwise(): void
    {
        $this->configure();
        $rejected = ['status' => 422, 'body' => '{"message":"no"}'];

        $this->assertSame(0, $this->command(new RecordingReporter($rejected))->runReport([]));
        $this->assertSame(1, $this->command(new RecordingReporter($rejected))->runReport(['fail-on-error' => true]));
    }

    public function test_an_exception_while_reading_the_site_does_not_escape(): void
    {
        $this->configure();
        $command = new Command(new ExplodingReader, $this->reporters(new RecordingReporter(null)), $this->out(), $this->root, $this->env());

        $this->assertSame(0, $command->runReport([]));
        $this->assertSame(1, $command->runReport(['fail-on-error' => true]));
        // Gating asked a question; an exception is not an answer, and never a pass.
        $this->assertSame(2, $command->runReport(['fail-on' => 'high']));
    }

    public function test_gating_on_a_report_that_was_never_accepted_is_not_a_pass(): void
    {
        $this->configure();

        $this->assertSame(2, $this->command(new RecordingReporter(null))->runReport(['fail-on' => 'high']));
    }

    public function test_it_refuses_to_report_where_the_composer_client_already_does(): void
    {
        $this->configure();
        mkdir($this->root.'/vendor/composer', 0777, true);
        file_put_contents($this->root.'/vendor/composer/installed.json', '{"packages":[{"name":"depman/client"}]}');
        $reporter = new RecordingReporter(['status' => 202, 'body' => '{}']);

        $this->assertSame(0, $this->command($reporter)->runReport([]));
        $this->assertNull($reporter->sent);
        $this->assertSame(["Skipped: no installed tree\n"], $this->output);
    }

    public function test_push_with_an_empty_spool_says_so(): void
    {
        $this->configure();

        $this->assertSame(0, $this->command(new RecordingReporter(null))->runPush([]));
        $this->assertSame(["Nothing spooled.\n"], $this->output);
    }

    public function test_doctor_shows_where_everything_came_from_and_what_could_be_identified(): void
    {
        $this->configure();

        $this->assertSame(0, $this->command(new RecordingReporter(null))->runDoctor([]));

        $text = implode('', $this->output);
        $this->assertStringContainsString('Project:      example-blog (from depman.json)', $text);
        $this->assertStringContainsString('Token:        found via DEPMAN_TOKEN', $text);
        $this->assertStringContainsString('WordPress:    6.8.3', $text);
        $this->assertStringContainsString('Identified:   2 of 3 packages on wordpress.org; 1 reported as inventory only', $text);
        $this->assertStringNotContainsString('dpm_test_secret', $text);
    }

    public function test_doctor_warns_about_a_token_in_a_dotenv_inside_the_wordpress_root(): void
    {
        $this->configure();
        file_put_contents($this->root.'/.env', "DEPMAN_TOKEN=dpm_test_secret\n");
        $env = $this->env();
        unset($env['DEPMAN_TOKEN']);

        $command = new Command($this->reader(), $this->reporters(new RecordingReporter(null)), $this->out(), $this->root, $env);
        $command->runDoctor([]);

        $this->assertStringContainsString('.env is inside the WordPress root', implode('', $this->output));
    }

    public function test_doctor_with_no_depman_json_says_where_it_looked(): void
    {
        $this->assertSame(0, $this->command(new RecordingReporter(null))->runDoctor([]));
        $this->assertSame(["No depman.json found in {$this->root}.\n"], $this->output);
    }

    private function configure(): void
    {
        file_put_contents($this->root.'/depman.json', json_encode([
            'configVersion' => 1,
            'endpoint' => 'https://depman.example',
            'project' => 'example-blog',
        ]));
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function command(RecordingReporter $reporter, array $extra = []): Command
    {
        return new Command($this->reader(), $this->reporters($reporter), $this->out(), $this->root, $this->env() + $extra);
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return [
            'DEPMAN_TOKEN' => 'dpm_test_secret',
            'DEPMAN_LOG_LEVEL' => 'silent',
            // Keeps the default spool, which lives under the home directory,
            // inside this test's own scratch directory.
            'HOME' => $this->root.'/home',
        ];
    }

    private function reader(): SiteReader
    {
        return new FixedSiteReader(new Site('6.8.3', [
            'akismet/akismet.php' => ['Version' => '5.3.3'],
            'acme-pro/acme-pro.php' => ['Version' => '2.1.0'],
        ], [
            'akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet', 'slug' => 'akismet'],
        ]));
    }

    /**
     * @return callable(Logger): Reporter
     */
    private function reporters(RecordingReporter $reporter): callable
    {
        return static function (Logger $logger) use ($reporter): Reporter {
            return $reporter;
        };
    }

    /**
     * @return callable(string): void
     */
    private function out(): callable
    {
        return function (string $line): void {
            $this->output[] = $line;
        };
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }

        rmdir($path);
    }
}

/**
 * A transport that records what it was given and answers with a fixed response.
 */
final class RecordingReporter extends Reporter
{
    /** @var array<string, mixed>|null */
    public $sent;

    /** @var array{status: int, body: string}|null */
    private $response;

    /**
     * @param  array{status: int, body: string}|null  $response
     */
    public function __construct(?array $response)
    {
        $stream = fopen('php://memory', 'w+');
        parent::__construct(new Logger('silent', $stream === false ? null : $stream));
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

    /**
     * @return array{status: int, body: string}|null
     */
    public function fetch(string $url, string $token, int $timeoutMs): ?array
    {
        return null;
    }
}

final class ExplodingReader implements SiteReader
{
    public function read(): ?Site
    {
        throw new RuntimeException('get_plugins() is not available');
    }
}
