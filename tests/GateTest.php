<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Gate;
use Depman\WpCli\GateResult;
use Depman\WpCli\Logger;
use Depman\WpCli\Reporter;
use Depman\WpCli\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CI gating — the one mode allowed to fail a build.
 *
 * Every other decision in this client leans towards "carry on"; this one leans
 * the other way, and the tests exist to hold it there. A gate that cannot get an
 * answer and exits 0 is worse than no gate: it is a green tick over an
 * unanswered question, and nobody looks at it again.
 */
class GateTest extends TestCase
{
    private const ENDPOINT = 'https://depman.example';

    private const STATUS_URL = 'https://depman.example/api/v1/reports/01J8XQ';

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stream = fopen('php://memory', 'rw+');
    }

    /**
     * @param  array<int, array{status: int, body: string}|null>  $responses
     */
    private function gate(array $responses): FakeClock
    {
        return new FakeClock(new Logger('debug', $this->stream), new PollingReporter(
            new Logger('silent', $this->stream),
            $responses,
        ));
    }

    /**
     * A scanned status body with the given open-finding counts.
     *
     * @param  array<string, int>|null  $findings
     * @return array{status: int, body: string}
     */
    private function body(?array $findings, string $reportStatus = 'processed'): array
    {
        $summary = null;

        if ($findings !== null) {
            $summary = array_merge(
                ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'none' => 0],
                $findings,
            );

            $summary['total'] = array_sum(array_values($findings));
        }

        return ['status' => 200, 'body' => (string) json_encode([
            'reportId' => '01J8XQ',
            'status' => $reportStatus,
            'scanned' => $findings !== null,
            'findings' => $summary,
        ])];
    }

    private function await(Gate $gate, ?string $threshold, int $timeout = 300): GateResult
    {
        return $gate->await(self::ENDPOINT, self::STATUS_URL, 'dpm_live_test', $threshold, $timeout, 10000);
    }

    public function test_it_passes_when_nothing_reaches_the_threshold(): void
    {
        $verdict = $this->await($this->gate([$this->body(['medium' => 4, 'low' => 9])]), 'high');

        $this->assertSame(GateResult::PASSED, $verdict->status);
        $this->assertFalse($verdict->isFailure());
        $this->assertSame(0, $verdict->exitCode());
        // Every band, zeros included: "0 critical" is a fact somebody wanted
        // confirmed, and omitting it leaves the reader guessing.
        $this->assertSame('0 critical, 0 high, 4 medium, 9 low, 0 unknown, 0 none', $verdict->summary());
    }

    public function test_it_fails_when_something_is_at_or_above_the_threshold(): void
    {
        $verdict = $this->await($this->gate([$this->body(['critical' => 1, 'high' => 2, 'low' => 5])]), 'high');

        $this->assertSame(GateResult::BREACHED, $verdict->status);
        $this->assertTrue($verdict->isFailure());
        // 1 exit code, not 2: we looked, and the answer was no.
        $this->assertSame(1, $verdict->exitCode());
        $this->assertSame(3, $verdict->breaching);
        $this->assertStringContainsString('3 open findings at high or above', $verdict->detail);
    }

    /**
     * @return array<string, array{string, array<string, int>, bool}>
     */
    public static function thresholds(): array
    {
        return [
            'critical catches only critical' => ['critical', ['high' => 3], false],
            'high catches critical' => ['high', ['critical' => 1], true],
            'medium catches high' => ['medium', ['high' => 1], true],
            'low ignores unknown' => ['low', ['unknown' => 2], false],
            // The one threshold that catches an advisory nobody could band.
            'unknown catches unknown' => ['unknown', ['unknown' => 2], true],
            'unknown ignores none' => ['unknown', ['none' => 7], false],
        ];
    }

    /**
     * @param  array<string, int>  $findings
     *
     * @dataProvider thresholds
     */
    #[DataProvider('thresholds')]
    public function test_the_threshold_uses_the_servers_own_severity_ordering(string $threshold, array $findings, bool $breaches): void
    {
        $verdict = $this->await($this->gate([$this->body($findings)]), $threshold);

        $this->assertSame($breaches, $verdict->isFailure(), "threshold [{$threshold}]");
    }

    public function test_wait_without_a_threshold_reports_and_gates_on_nothing(): void
    {
        $verdict = $this->await($this->gate([$this->body(['critical' => 9])]), null);

        $this->assertSame(GateResult::PASSED, $verdict->status);
        $this->assertStringContainsString('9 critical', (string) $verdict->summary());
    }

    public function test_a_misspelled_threshold_errors_rather_than_gating_on_nothing(): void
    {
        // The alternative is a pipeline that goes green forever, which is the
        // exact failure this mode exists to prevent.
        $verdict = $this->await($this->gate([]), 'sever');

        $this->assertSame(GateResult::ERRORED, $verdict->status);
        $this->assertSame(2, $verdict->exitCode());
        $this->assertStringContainsString('unknown severity threshold', $verdict->detail);
    }

    public function test_it_waits_for_the_scan_rather_than_reading_an_unscanned_report(): void
    {
        // Null findings is the server saying "accepted, not scanned yet". An
        // empty summary would be the server saying "clean", and passing a build
        // on the first is how a gate silently stops gating.
        $gate = $this->gate([
            $this->body(null),
            $this->body(null),
            $this->body(['high' => 1]),
        ]);

        $verdict = $this->await($gate, 'high');

        $this->assertSame(GateResult::BREACHED, $verdict->status);
        $this->assertSame([2, 4], $gate->slept, 'the backoff should widen between polls');
    }

    public function test_the_backoff_widens_but_is_capped(): void
    {
        $gate = $this->gate(array_fill(0, 7, $this->body(null)) + [7 => $this->body([])]);

        $this->await($gate, 'high');

        $this->assertSame([2, 4, 8, 15, 15, 15, 15], $gate->slept);
    }

    public function test_it_times_out_rather_than_waiting_forever(): void
    {
        $gate = $this->gate(array_fill(0, 20, $this->body(null)));

        $verdict = $this->await($gate, 'high', 10);

        // Fail-closed. "We could not find out" must not read as "all clear".
        $this->assertSame(GateResult::TIMED_OUT, $verdict->status);
        $this->assertTrue($verdict->isFailure());
        $this->assertSame(2, $verdict->exitCode());
        $this->assertNull($verdict->summary());
        // Never overshoots the deadline: the last wait is trimmed to what is left.
        $this->assertSame(10, array_sum($gate->slept));
    }

    public function test_a_transient_failure_is_retried_rather_than_failing_the_build(): void
    {
        $gate = $this->gate([
            null,                                   // the runner's network blipped
            ['status' => 503, 'body' => ''],        // a deploy was in progress
            ['status' => 429, 'body' => ''],        // another job got there first
            $this->body(['low' => 1]),
        ]);

        $verdict = $this->await($gate, 'high');

        $this->assertSame(GateResult::PASSED, $verdict->status);
    }

    public function test_a_report_that_could_not_be_processed_is_never_a_pass(): void
    {
        // "No vulnerabilities" and "we never processed your tree" are the two
        // answers a gate must never confuse.
        $verdict = $this->await($this->gate([[
            'status' => 200,
            'body' => (string) json_encode(['status' => 'failed', 'error' => 'The stored payload is missing.']),
        ]]), 'high');

        $this->assertSame(GateResult::ERRORED, $verdict->status);
        $this->assertStringContainsString('The stored payload is missing.', $verdict->detail);
    }

    public function test_a_refused_poll_errors_and_names_the_code(): void
    {
        $verdict = $this->await($this->gate([[
            'status' => 404,
            'body' => (string) json_encode(['error' => ['code' => 'not_found']]),
        ]]), 'high');

        $this->assertSame(GateResult::ERRORED, $verdict->status);
        $this->assertStringContainsString('not_found', $verdict->detail);
    }

    public function test_a_status_url_on_another_host_never_receives_the_token(): void
    {
        // The URL arrives in a response body and the next thing we would do is
        // put a bearer token on it. Same reason §7 forbids following redirects.
        $reporter = new PollingReporter(new Logger('silent', $this->stream), []);
        $gate = new FakeClock(new Logger('silent', $this->stream), $reporter);

        $verdict = $gate->await(self::ENDPOINT, 'https://evil.test/api/v1/reports/01J8XQ', 'dpm_live_test', 'high', 300, 10000);

        $this->assertSame(GateResult::ERRORED, $verdict->status);
        $this->assertStringContainsString('another host', $verdict->detail);
        $this->assertSame(0, $reporter->polls, 'the token must not have been sent anywhere');
    }

    public function test_an_explicit_default_port_is_the_same_origin(): void
    {
        // A deployment behind a proxy may spell the same origin either way, and
        // refusing to poll would break gating for exactly those instances.
        $gate = $this->gate([$this->body([])]);

        $verdict = $gate->await(
            'https://depman.example',
            'https://depman.example:443/api/v1/reports/01J8XQ',
            'dpm_live_test', 'high', 300, 10000,
        );

        $this->assertSame(GateResult::PASSED, $verdict->status);
    }

    public function test_a_report_that_was_not_accepted_cannot_be_gated_on(): void
    {
        // A gate that shrugs when the report never landed goes green for every
        // build with a rotated token.
        $verdict = $this->gate([])->run(
            sys_get_temp_dir(),
            Result::skipped('no token'),
            'high',
            300,
        );

        $this->assertSame(GateResult::ERRORED, $verdict->status);
        $this->assertStringContainsString('no token', $verdict->detail);
    }

    public function test_a_report_with_no_status_url_cannot_be_gated_on(): void
    {
        // An unsupported ecosystem is accepted as inventory and creates no
        // Report, so there is nothing to poll and nothing to gate.
        $verdict = $this->gate([])->run(sys_get_temp_dir(), Result::reported(3, false, null), 'high', 300);

        $this->assertSame(GateResult::ERRORED, $verdict->status);
        $this->assertStringContainsString('no status URL', $verdict->detail);
    }
}

/**
 * A Gate whose clock and sleep are bookkeeping rather than wall-clock time.
 *
 * Polling is a loop around those two, and a suite that exercises it for real is
 * a suite nobody runs.
 */
final class FakeClock extends Gate
{
    /** @var array<int, int> */
    public array $slept = [];

    private int $now = 1_000_000;

    protected function now(): int
    {
        return $this->now;
    }

    protected function pause(int $seconds): void
    {
        $this->slept[] = $seconds;
        $this->now += $seconds;
    }
}

/**
 * Replays a queue of canned polls.
 */
final class PollingReporter extends Reporter
{
    public int $polls = 0;

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
     * @return array{status: int, body: string}|null
     */
    public function fetch(string $url, string $token, int $timeoutMs): ?array
    {
        $this->polls++;

        if ($this->responses === []) {
            throw new \RuntimeException('The gate polled more times than the test queued responses for.');
        }

        return array_shift($this->responses);
    }
}
