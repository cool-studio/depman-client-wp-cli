<?php

namespace Depman\WpCli;

/**
 * The outcome of a report attempt.
 *
 * Note that `skipped` and `spooled` are not failures: no token, no depman.json
 * and an unreachable server are all ordinary situations that must not fail
 * whatever scheduled the run.
 */
final class Result
{
    /** @var string */
    public $status;

    /** @var string */
    public $detail;

    /** @var int */
    public $packages;

    /** @var bool */
    public $deduplicated;

    /** @var string|null */
    public $statusUrl;

    private function __construct(
        string $status,
        string $detail,
        int $packages = 0,
        bool $deduplicated = false,
        ?string $statusUrl = null
    ) {
        $this->status = $status;
        $this->detail = $detail;
        $this->packages = $packages;
        $this->deduplicated = $deduplicated;
        $this->statusUrl = $statusUrl;
    }

    /**
     * `$statusUrl` is the server's own link to this report's status, and it is
     * carried here because it is the only input CI gating has: `--wait` polls
     * it rather than reconstructing a URL from the endpoint and an id it would
     * have to learn to parse. Null when the server did not send one — an
     * unsupported ecosystem creates no Report to poll.
     */
    public static function reported(int $packages, bool $deduplicated, ?string $statusUrl = null): self
    {
        return new self('reported', 'ok', $packages, $deduplicated, $statusUrl);
    }

    public static function skipped(string $detail): self
    {
        return new self('skipped', $detail);
    }

    public static function spooled(string $detail): self
    {
        return new self('spooled', $detail);
    }

    public static function failed(string $detail): self
    {
        return new self('failed', $detail);
    }

    public function isFailure(): bool
    {
        return $this->status === 'failed';
    }
}
