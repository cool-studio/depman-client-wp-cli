<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Logger;
use Depman\WpCli\Pusher;
use Depman\WpCli\Reporter;
use PHPUnit\Framework\TestCase;

/**
 * Draining the offline spool.
 *
 * The rule underneath every case: **an undelivered payload is not a failure,
 * and an undeliverable one is.** The spool exists so an air-gapped or
 * rate-limited build does not lose its inventory, so a push that cannot reach
 * the server must leave everything exactly where it found it — while a payload
 * the server will reject forever has to leave, because at a 20-file cap a slot
 * that can never empty evicts payloads that could.
 */
class PusherTest extends TestCase
{
    private string $root;

    private string $spool;

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/depman-push-'.bin2hex(random_bytes(6));
        $this->spool = $this->root.'/.depman/spool';
        mkdir($this->spool, 0775, true);

        // An explicit, project-relative spool: this suite is about the drain,
        // and the default's home-directory location is ConfigTest's subject.
        file_put_contents($this->root.'/depman.json', json_encode([
            'project' => 'acme/storefront',
            'offline' => ['spoolDir' => '.depman/spool'],
        ]));

        $this->stream = fopen('php://memory', 'rw+');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

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
     * A spooled payload, named so a string sort is chronological.
     */
    private function spooled(string $name, string $purl = 'pkg:composer/acme/widget@1.2.3'): string
    {
        $file = sprintf('%s/20260101-%s.json', $this->spool, $name);

        file_put_contents($file, (string) json_encode([
            'depmanWireVersion' => 1,
            'report' => ['manifestDigest' => 'sha256:'.str_repeat('a', 64), 'reason' => 'postinstall'],
            'project' => 'acme/storefront',
            'packages' => [['purl' => $purl]],
        ]));

        return $file;
    }

    /**
     * @return array<int, string>
     */
    private function pending(): array
    {
        return array_map('basename', glob($this->spool.'/*.json') ?: []);
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @param  array<int, array{status: int, body: string}|null>  $responses
     */
    private function pusher(array $responses): DrainReporter
    {
        return new DrainReporter(new Logger('debug', $this->stream), $responses);
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return ['DEPMAN_TOKEN' => 'dpm_live_test'];
    }

    public function test_it_sends_every_spooled_payload_and_clears_the_directory(): void
    {
        $this->spooled('a');
        $this->spooled('b');

        $reporter = $this->pusher([
            ['status' => 202, 'body' => '{}'],
            ['status' => 200, 'body' => '{}'],
        ]);

        $result = (new Pusher(new Logger('debug', $this->stream), $reporter))->push($this->root, $this->env());

        $this->assertSame('drained', $result->status);
        $this->assertSame(2, $result->sent);
        $this->assertSame(0, $result->discarded);
        $this->assertFalse($result->isFailure());
        $this->assertSame([], $this->pending());
    }

    public function test_it_sends_oldest_first_and_marks_each_a_replay(): void
    {
        $this->spooled('b', 'pkg:composer/acme/second@2.0.0');
        $this->spooled('a', 'pkg:composer/acme/first@1.0.0');

        $reporter = $this->pusher([
            ['status' => 202, 'body' => '{}'],
            ['status' => 202, 'body' => '{}'],
        ]);

        (new Pusher(new Logger('silent', $this->stream), $reporter))->push($this->root, $this->env());

        // Oldest first: a stale inventory is worth less than a recent one, so
        // if the drain is cut short it is the newest that survives.
        $this->assertSame(
            ['pkg:composer/acme/first@1.0.0', 'pkg:composer/acme/second@2.0.0'],
            array_map(fn (array $payload) => $payload['packages'][0]['purl'], $reporter->sent),
        );

        // `spool-replay` is the only way the server can tell a tree that arrived
        // late from one that arrived now. The digest never covers the reason, so
        // rewriting it cannot break deduplication.
        foreach ($reporter->sent as $payload) {
            $this->assertSame('spool-replay', $payload['report']['reason']);
        }
    }

    public function test_nothing_spooled_is_not_an_error(): void
    {
        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([])))
            ->push($this->root, $this->env());

        $this->assertSame('drained', $result->status);
        $this->assertSame(0, $result->sent);
        $this->assertFalse($result->isFailure());
    }

    public function test_an_unreachable_server_stops_the_drain_and_keeps_the_rest(): void
    {
        $this->spooled('a');
        $this->spooled('b');
        $this->spooled('c');

        // One delivered, then the network goes away again.
        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 202, 'body' => '{}'],
            null,
        ])))->push($this->root, $this->env());

        $this->assertSame('stopped', $result->status);
        $this->assertSame(1, $result->sent);
        $this->assertSame(2, $result->remaining);
        // Being offline is what the spool is for. It must never be an error.
        $this->assertFalse($result->isFailure());
        $this->assertSame(['20260101-b.json', '20260101-c.json'], $this->pending());
    }

    public function test_a_rate_limit_stops_the_drain_without_discarding_anything(): void
    {
        $this->spooled('a');
        $this->spooled('b');

        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 429, 'body' => '{}'],
        ])))->push($this->root, $this->env());

        $this->assertSame('stopped', $result->status);
        $this->assertSame('rate limited', $result->detail);
        $this->assertFalse($result->isFailure());
        $this->assertCount(2, $this->pending());
    }

    public function test_a_server_error_stops_the_drain_without_discarding_anything(): void
    {
        $this->spooled('a');
        $this->spooled('b');

        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 503, 'body' => ''],
        ])))->push($this->root, $this->env());

        $this->assertSame('DepMan is unavailable', $result->detail);
        $this->assertFalse($result->isFailure());
        $this->assertCount(2, $this->pending());
    }

    public function test_a_rejected_token_keeps_every_payload_and_is_a_failure(): void
    {
        $this->spooled('a');
        $this->spooled('b');

        // Fixable, and fixable once for the whole queue. Discarding here would
        // lose an entire inventory to a rotated token.
        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 401, 'body' => json_encode(['error' => ['code' => 'invalid_token', 'message' => 'No.']])],
        ])))->push($this->root, $this->env());

        $this->assertSame('stopped', $result->status);
        $this->assertSame('the token was rejected', $result->detail);
        $this->assertTrue($result->isFailure());
        $this->assertCount(2, $this->pending());
        $this->assertStringContainsString('invalid_token', $this->logged());
        $this->assertStringContainsString('Nothing was discarded', $this->logged());
    }

    public function test_any_other_4xx_discards_that_payload_and_carries_on(): void
    {
        $this->spooled('a');
        $this->spooled('b');

        // A wire version we retired, a project that was renamed: permanent
        // properties of that one payload. Retrying it forever would hide the
        // misconfiguration and hold a slot the next report needs.
        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 409, 'body' => json_encode(['error' => ['code' => 'project_mismatch', 'message' => 'Wrong project.']])],
            ['status' => 202, 'body' => '{}'],
        ])))->push($this->root, $this->env());

        $this->assertSame('drained', $result->status);
        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->discarded);
        $this->assertTrue($result->isFailure());
        $this->assertSame([], $this->pending());
        $this->assertStringContainsString('project_mismatch', $this->logged());
    }

    public function test_a_redirect_stops_the_drain_and_discards_nothing(): void
    {
        $this->spooled('a');
        $this->spooled('b');

        // We never follow redirects, so a proxy answering 302 is a
        // configuration problem rather than a property of this payload.
        // Treating it as one would let that proxy eat the spool a file at a
        // time, and the user would only ever see "pushed 0".
        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 302, 'body' => ''],
        ])))->push($this->root, $this->env());

        $this->assertSame('stopped', $result->status);
        $this->assertSame(0, $result->discarded);
        $this->assertTrue($result->isFailure());
        $this->assertCount(2, $this->pending());
        $this->assertStringContainsString('redirecting', $this->logged());
    }

    public function test_an_unreadable_payload_is_discarded_rather_than_retried_forever(): void
    {
        file_put_contents($this->spool.'/20260101-a.json', '{"truncated": ');
        $this->spooled('b');

        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([
            ['status' => 202, 'body' => '{}'],
        ])))->push($this->root, $this->env());

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->discarded);
        $this->assertSame([], $this->pending());
        $this->assertStringContainsString('could not be read', $this->logged());
    }

    public function test_no_token_leaves_the_spool_exactly_as_it_was(): void
    {
        $this->spooled('a');

        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([])))
            ->push($this->root, ['DEPMAN_HOME' => $this->root]);

        $this->assertSame('skipped', $result->status);
        $this->assertSame('no token', $result->detail);
        $this->assertFalse($result->isFailure());
        $this->assertCount(1, $this->pending());
    }

    public function test_a_missing_config_is_a_skip(): void
    {
        unlink($this->root.'/depman.json');

        $result = (new Pusher(new Logger('debug', $this->stream), $this->pusher([])))
            ->push($this->root, $this->env());

        $this->assertSame('skipped', $result->status);
        $this->assertSame('no depman.json', $result->detail);
    }

    public function test_the_token_value_is_never_logged(): void
    {
        $this->spooled('a');

        (new Pusher(new Logger('debug', $this->stream), $this->pusher([['status' => 202, 'body' => '{}']])))
            ->push($this->root, $this->env());

        $this->assertStringNotContainsString('dpm_live_test', $this->logged());
        $this->assertStringContainsString('token found via DEPMAN_TOKEN', $this->logged());
    }
}

/**
 * Replays a queue of canned responses and records what it was asked to send.
 *
 * Reporter is the client's only network seam, which is why it is not final.
 */
final class DrainReporter extends Reporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    /** @var array<int, array{status: int, body: string}|null> */
    private $responses;

    /**
     * @param  array<int, array{status: int, body: string}|null>  $responses
     */
    public function __construct(Logger $logger, array $responses)
    {
        parent::__construct($logger);
        $this->responses = $responses;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}|null
     */
    public function send(string $endpoint, string $token, array $payload, int $timeoutMs): ?array
    {
        $this->sent[] = $payload;

        if ($this->responses === []) {
            throw new \RuntimeException('The pusher sent more payloads than the test queued responses for.');
        }

        return array_shift($this->responses);
    }
}
