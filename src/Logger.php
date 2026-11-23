<?php

namespace Depman\WpCli;

/**
 * Minimal leveled logger writing to stderr.
 *
 * At the default level a normal run prints at most two lines. Nothing this
 * client does is important enough to clutter someone else's cron mail.
 */
final class Logger
{
    public const SILENT = 0;

    public const ERROR = 1;

    public const WARN = 2;

    public const INFO = 3;

    public const DEBUG = 4;

    /** @var array<string, int> */
    private const LEVELS = [
        'silent' => self::SILENT,
        'error' => self::ERROR,
        'warn' => self::WARN,
        'info' => self::INFO,
        'debug' => self::DEBUG,
    ];

    /** @var int */
    private $level;

    /** @var resource */
    private $stream;

    /**
     * @param  resource|null  $stream
     */
    public function __construct(string $level = 'warn', $stream = null)
    {
        $this->level = self::LEVELS[strtolower($level)] ?? self::WARN;
        $this->stream = $stream ?? STDERR;
    }

    public function error(string $message): void
    {
        $this->write(self::ERROR, $message);
    }

    public function warn(string $message): void
    {
        $this->write(self::WARN, $message);
    }

    public function info(string $message): void
    {
        $this->write(self::INFO, $message);
    }

    public function debug(string $message): void
    {
        $this->write(self::DEBUG, $message);
    }

    /**
     * Printed regardless of level. Reserved for the one case where staying
     * quiet would be irresponsible: a credential committed to source control.
     */
    public function always(string $message): void
    {
        fwrite($this->stream, 'DepMan: '.$message.PHP_EOL);
    }

    private function write(int $level, string $message): void
    {
        if ($level > $this->level) {
            return;
        }

        fwrite($this->stream, 'DepMan: '.$message.PHP_EOL);
    }
}
