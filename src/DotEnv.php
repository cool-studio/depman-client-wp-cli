<?php

namespace Depman\WpCli;

/**
 * A deliberately tiny `.env` reader.
 *
 * The client cannot depend on `vlucas/phpdotenv` — it is a zero-dependency
 * package by design, and pulling a parser into every consumer's production
 * install to read one key would be a poor trade.
 *
 * It also does not need one. This reads a single named key and understands the
 * subset of the format people actually write: `KEY=value`, optional `export`,
 * quotes, comments. It performs **no variable interpolation** and no `putenv`,
 * so reading a consumer's `.env` cannot change how their own application later
 * loads it.
 */
final class DotEnv
{
    /**
     * A `.env` larger than this is not a `.env`.
     *
     * The guard matters because this runs inside somebody else's
     * WP-CLI run on somebody else's server: a pathological file must not turn a
     * scheduled report into a memory problem.
     */
    private const MAX_BYTES = 256 * 1024;

    /**
     * One key's value, or null.
     *
     * **Only the requested key is ever returned.** A `.env` is the densest
     * concentration of secrets in a typical project, and this class exists to
     * take exactly one thing out of it.
     */
    public static function get(string $projectRoot, string $key): ?string
    {
        $path = rtrim($projectRoot, '/').'/.env';

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $size = @filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $value = self::match($line, $key);

                if ($value !== null) {
                    return $value;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * The value on this line if it assigns `$key`, otherwise null.
     */
    private static function match(string $line, string $key): ?string
    {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            return null;
        }

        // `export FOO=bar` is common in files people also source from a shell.
        if (strpos($line, 'export ') === 0) {
            $line = ltrim(substr($line, 7));
        }

        $separator = strpos($line, '=');

        if ($separator === false || rtrim(substr($line, 0, $separator)) !== $key) {
            return null;
        }

        return self::clean(ltrim(substr($line, $separator + 1)));
    }

    /**
     * Strip surrounding quotes, or an inline comment from an unquoted value.
     */
    private static function clean(string $value): ?string
    {
        foreach (['"', "'"] as $quote) {
            if (strpos($value, $quote) === 0) {
                $closing = strpos($value, $quote, 1);

                // An unterminated quote is a broken line, not a value. Guessing
                // at what was meant is how a truncated token gets sent.
                return $closing === false ? null : self::orNull(substr($value, 1, $closing - 1));
            }
        }

        // ` #` and not `#`: a token could legitimately contain a hash, and only
        // a hash preceded by whitespace starts a comment.
        $comment = strpos($value, ' #');

        if ($comment !== false) {
            $value = substr($value, 0, $comment);
        }

        return self::orNull(trim($value));
    }

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
