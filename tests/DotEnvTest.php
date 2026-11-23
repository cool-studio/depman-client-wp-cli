<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\DotEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading one key out of a consumer's `.env`.
 *
 * This is the densest concentration of secrets in a typical project and the
 * client reads it during someone else's `composer install`, so the rules are
 * narrow on purpose: exactly one key, no interpolation, no `putenv`, and a size
 * cap so a pathological file cannot turn a post-install hook into a memory
 * problem.
 */
class DotEnvTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/depman-dotenv-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root.'/.env')) {
            unlink($this->root.'/.env');
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }

        parent::tearDown();
    }

    private function write(string $contents): void
    {
        file_put_contents($this->root.'/.env', $contents);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function lines(): array
    {
        return [
            'bare' => ['DEPMAN_TOKEN=dpm_live_abc', 'dpm_live_abc'],
            'double quoted' => ['DEPMAN_TOKEN="dpm_live_abc"', 'dpm_live_abc'],
            'single quoted' => ["DEPMAN_TOKEN='dpm_live_abc'", 'dpm_live_abc'],
            'exported' => ['export DEPMAN_TOKEN=dpm_live_abc', 'dpm_live_abc'],
            'exported and quoted' => ['export DEPMAN_TOKEN="dpm_live_abc"', 'dpm_live_abc'],
            'padded key' => ['DEPMAN_TOKEN =dpm_live_abc', 'dpm_live_abc'],
            'padded value' => ['DEPMAN_TOKEN=   dpm_live_abc', 'dpm_live_abc'],
            'trailing comment' => ['DEPMAN_TOKEN=dpm_live_abc # the CI token', 'dpm_live_abc'],
            // A hash is legal inside a token, so only ` #` starts a comment.
            'hash inside the value' => ['DEPMAN_TOKEN=dpm_live_a#bc', 'dpm_live_a#bc'],
            'commented out' => ['# DEPMAN_TOKEN=dpm_live_abc', null],
            'empty value' => ['DEPMAN_TOKEN=', null],
            // Guessing at what was meant is how a truncated token gets sent.
            'unterminated quote' => ['DEPMAN_TOKEN="dpm_live_abc', null],
            'different key' => ['OTHER_TOKEN=dpm_live_abc', null],
            // Prefix collisions must not match: DEPMAN_TOKEN_FILE is a
            // different mechanism pointing at a path, not a credential.
            'prefix collision' => ['DEPMAN_TOKEN_FILE=/run/secrets/depman', null],
        ];
    }

    /**
     * @dataProvider lines
     */
    #[DataProvider('lines')]
    public function test_it_reads_the_value_people_actually_write(string $line, ?string $expected): void
    {
        $this->write($line."\n");

        $this->assertSame($expected, DotEnv::get($this->root, 'DEPMAN_TOKEN'));
    }

    public function test_it_returns_only_the_requested_key(): void
    {
        $this->write("APP_KEY=base64:sensitive\nDEPMAN_TOKEN=dpm_live_abc\nDB_PASSWORD=hunter2\n");

        $this->assertSame('dpm_live_abc', DotEnv::get($this->root, 'DEPMAN_TOKEN'));
        $this->assertSame('hunter2', DotEnv::get($this->root, 'DB_PASSWORD'));
        $this->assertNull(DotEnv::get($this->root, 'NOT_PRESENT'));
    }

    public function test_the_first_assignment_wins(): void
    {
        $this->write("DEPMAN_TOKEN=first\nDEPMAN_TOKEN=second\n");

        $this->assertSame('first', DotEnv::get($this->root, 'DEPMAN_TOKEN'));
    }

    public function test_a_missing_file_is_not_an_error(): void
    {
        $this->assertNull(DotEnv::get($this->root, 'DEPMAN_TOKEN'));
    }

    public function test_it_does_not_leak_the_value_into_the_process_environment(): void
    {
        $this->write("DEPMAN_TOKEN=dpm_live_abc\n");

        DotEnv::get($this->root, 'DEPMAN_TOKEN');

        // No putenv: reading a consumer's .env must not change how their own
        // application later loads it.
        $this->assertFalse(getenv('DEPMAN_TOKEN'));
    }

    public function test_an_oversized_file_is_refused_rather_than_read(): void
    {
        // 256 KB is the cap. Larger than this is not a .env, and this runs
        // inside somebody else's install.
        $this->write("DEPMAN_TOKEN=dpm_live_abc\n".str_repeat('#padding', 40000)."\n");

        $this->assertGreaterThan(256 * 1024, (int) filesize($this->root.'/.env'));
        $this->assertNull(DotEnv::get($this->root, 'DEPMAN_TOKEN'));
    }
}
