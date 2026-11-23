<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Result;
use PHPUnit\Framework\TestCase;

/**
 * Only one of these four outcomes is a failure.
 *
 * `skipped` and `spooled` are ordinary: no token, no depman.json and an
 * unreachable server are all normal states for a tool running inside somebody
 * else's build, and none may be allowed to fail it.
 */
class ResultTest extends TestCase
{
    public function test_a_report_carries_its_count_and_dedupe_flag(): void
    {
        $result = Result::reported(164, true);

        $this->assertSame('reported', $result->status);
        $this->assertSame(164, $result->packages);
        $this->assertTrue($result->deduplicated);
        $this->assertFalse($result->isFailure());
    }

    public function test_skipped_and_spooled_are_not_failures(): void
    {
        $this->assertFalse(Result::skipped('no token')->isFailure());
        $this->assertFalse(Result::spooled('rate limited')->isFailure());

        $this->assertSame('no token', Result::skipped('no token')->detail);
        $this->assertSame('rate limited', Result::spooled('rate limited')->detail);
    }

    public function test_only_failed_is_a_failure(): void
    {
        $result = Result::failed('environment_not_allowed: Not permitted.');

        $this->assertTrue($result->isFailure());
        $this->assertSame('environment_not_allowed: Not permitted.', $result->detail);
        $this->assertSame(0, $result->packages);
    }
}
