<?php

namespace Depman\WpCli;

/**
 * Holds payloads that could not be delivered, for a later `depman push`.
 *
 * An air-gapped build or a flaky runner should not lose its inventory. The
 * directory is capped so a permanently offline project cannot fill a disk.
 */
final class Spool
{
    private const MAX_FILES = 20;

    /** @var string */
    private $directory;

    /** @var Logger */
    private $logger;

    /** @var string|null */
    private $projectRoot;

    /**
     * `$projectRoot`, when given, limits the not-ignored warning to a spool
     * that actually sits inside the project: the default lives in the home
     * directory (Config::spoolDirectory), where no .gitignore applies.
     */
    public function __construct(string $directory, Logger $logger, ?string $projectRoot = null)
    {
        $this->directory = $directory;
        $this->logger = $logger;
        $this->projectRoot = $projectRoot;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(array $payload): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->logger->debug('Could not create the spool directory; dropping this report.');

            return false;
        }

        $file = sprintf('%s/%s-%s.json', $this->directory, date('Ymd-His'), bin2hex(random_bytes(4)));

        if (@file_put_contents($file, json_encode($payload)) === false) {
            return false;
        }

        $this->prune();
        $this->warnIfTracked();

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function pending(): array
    {
        $files = glob($this->directory.'/*.json') ?: [];
        sort($files);

        return $files;
    }

    /**
     * One spooled payload, or null when the file cannot be read as one.
     *
     * A truncated write — the disk filled, or the build was killed mid-install —
     * leaves a file that will never decode. The caller discards those rather
     * than retrying them forever: at a 20-file cap, a payload that can never
     * leave is a slot that evicts good ones.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $file): ?array
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function forget(string $file): void
    {
        @unlink($file);
    }

    private function prune(): void
    {
        $files = $this->pending();

        // Oldest first: a stale inventory is less useful than a recent one.
        foreach (array_slice($files, 0, max(0, count($files) - self::MAX_FILES)) as $stale) {
            @unlink($stale);
        }
    }

    private function warnIfTracked(): void
    {
        if ($this->projectRoot !== null && strpos($this->directory, rtrim($this->projectRoot, '/').'/') !== 0) {
            return;
        }

        $gitignore = dirname($this->directory, 2).'/.gitignore';

        if (!is_file($gitignore)) {
            return;
        }

        $contents = (string) file_get_contents($gitignore);

        if (strpos($contents, '.depman') === false) {
            $this->logger->warn('the spool directory is not in .gitignore; add ".depman/" to avoid committing inventory files.');
        }
    }
}
