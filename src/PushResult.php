<?php

namespace Depman\WpCli;

/**
 * The outcome of draining the spool.
 *
 * Counts rather than a single status, because a drain is not one thing: some
 * payloads land, one can be rejected outright, and the run can stop partway
 * because the server is still unreachable. Collapsing that into "ok" or "not
 * ok" throws away the number the operator actually wants, which is how much
 * inventory is still sitting on disk.
 */
final class PushResult
{
    /** @var string */
    public $status;

    /** @var int Payloads the server accepted and that have been deleted. */
    public $sent;

    /** @var int Payloads dropped unsent because they can never succeed. */
    public $discarded;

    /** @var int Payloads still on disk, to be retried by a later push. */
    public $remaining;

    /** @var string Why the drain stopped, or 'ok' when it ran to the end. */
    public $detail;

    /** @var bool Whether the user has something to fix. */
    public $failed;

    private function __construct(string $status, int $sent, int $discarded, int $remaining, string $detail, bool $failed)
    {
        $this->status = $status;
        $this->sent = $sent;
        $this->discarded = $discarded;
        $this->remaining = $remaining;
        $this->detail = $detail;
        $this->failed = $failed;
    }

    public static function skipped(string $detail): self
    {
        return new self('skipped', 0, 0, 0, $detail, false);
    }

    /**
     * The drain reached the end of the queue.
     *
     * Still a failure when anything was discarded: a rejected payload is a
     * configuration problem, and it is the one outcome here that a person has
     * to act on.
     */
    public static function drained(int $sent, int $discarded): self
    {
        return new self('drained', $sent, $discarded, 0, 'ok', $discarded > 0);
    }

    /**
     * The drain stopped early, leaving the rest for next time.
     *
     * `$failed` is the caller's judgement, not this class's: an unreachable
     * server is exactly what the spool is for and must not be an error, while a
     * rejected token is a thing somebody has to fix.
     */
    public static function stopped(int $sent, int $discarded, int $remaining, string $detail, bool $failed): self
    {
        return new self('stopped', $sent, $discarded, $remaining, $detail, $failed);
    }

    public function isFailure(): bool
    {
        return $this->failed;
    }
}
