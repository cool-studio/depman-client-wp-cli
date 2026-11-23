<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the client looks for its token, and in what order.
 *
 * **`.env` first is the whole point of this file.** The hook fires during
 * `composer install`, before any Laravel application has booted, so nothing has
 * loaded `.env` into the process environment. A developer who put
 * `DEPMAN_TOKEN` in the obvious place — beside every other secret in their
 * project — used to get "no token found, skipping" and no explanation, which is
 * silence that looks exactly like success.
 */
class TokenTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/depman-token-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->root.'/.env', $contents);
    }

    public function test_it_reads_the_token_from_dot_env(): void
    {
        $this->writeEnv("APP_ENV=local\nDEPMAN_TOKEN=dpm_live_from_dotenv\n");

        $found = Token::locate('https://depman.test', [], $this->root);

        $this->assertNotNull($found);
        $this->assertSame('dpm_live_from_dotenv', $found->value);
        $this->assertSame('.env', $found->source);
    }

    public function test_dot_env_wins_over_the_process_environment(): void
    {
        $this->writeEnv("DEPMAN_TOKEN=dpm_live_from_dotenv\n");

        $found = Token::locate('https://depman.test', ['DEPMAN_TOKEN' => 'dpm_live_from_env'], $this->root);

        $this->assertSame('dpm_live_from_dotenv', ($found === null ? null : $found->value));
    }

    public function test_it_falls_back_to_the_process_environment(): void
    {
        $this->writeEnv("APP_ENV=local\n");

        $found = Token::locate('https://depman.test', ['DEPMAN_TOKEN' => 'dpm_live_from_env'], $this->root);

        $this->assertNotNull($found);
        $this->assertSame('dpm_live_from_env', $found->value);
        $this->assertSame('DEPMAN_TOKEN', $found->source);
    }

    public function test_it_falls_back_to_a_token_file(): void
    {
        $path = $this->root.'/secret';
        file_put_contents($path, "dpm_live_from_file\n");

        $found = Token::locate('https://depman.test', ['DEPMAN_TOKEN_FILE' => $path], $this->root);

        $this->assertNotNull($found);
        $this->assertSame('dpm_live_from_file', $found->value);
        $this->assertStringContainsString('DEPMAN_TOKEN_FILE', $found->source);
    }

    public function test_the_token_file_path_may_itself_come_from_dot_env(): void
    {
        $path = $this->root.'/secret';
        file_put_contents($path, 'dpm_live_from_file');
        $this->writeEnv("DEPMAN_TOKEN_FILE={$path}\n");

        // Both mechanisms look in the same two places, in the same order, so
        // they cannot disagree about where a token lives.
        $this->assertSame('dpm_live_from_file', Token::find('https://depman.test', [], $this->root));
    }

    public function test_it_falls_back_to_the_credentials_file(): void
    {
        mkdir($this->root.'/.depman', 0700, true);
        file_put_contents(
            $this->root.'/.depman/credentials',
            json_encode(['depman.test' => 'dpm_live_from_credentials']),
        );

        $found = Token::locate('https://depman.test', ['DEPMAN_HOME' => $this->root], $this->root);

        $this->assertSame('dpm_live_from_credentials', ($found === null ? null : $found->value));

        @unlink($this->root.'/.depman/credentials');
        @rmdir($this->root.'/.depman');
    }

    public function test_no_token_anywhere_is_not_an_error(): void
    {
        // The normal state for an open-source contributor who just cloned the
        // repository. It must be harmless.
        $this->assertNull(Token::locate('https://depman.test', ['HOME' => $this->root], $this->root));
    }

    public function test_it_still_works_without_a_project_root(): void
    {
        // bin/depman and the hook both pass one, but the signature keeps it
        // optional so no existing caller breaks.
        $this->assertSame(
            'dpm_live_from_env',
            Token::find('https://depman.test', ['DEPMAN_TOKEN' => 'dpm_live_from_env']),
        );
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function dotEnvLines(): array
    {
        return [
            'plain' => ['DEPMAN_TOKEN=dpm_live_abc', 'dpm_live_abc'],
            'double quoted' => ['DEPMAN_TOKEN="dpm_live_abc"', 'dpm_live_abc'],
            'single quoted' => ["DEPMAN_TOKEN='dpm_live_abc'", 'dpm_live_abc'],
            'exported' => ['export DEPMAN_TOKEN=dpm_live_abc', 'dpm_live_abc'],
            'spaced around the equals' => ['DEPMAN_TOKEN = dpm_live_abc', 'dpm_live_abc'],
            'trailing comment' => ['DEPMAN_TOKEN=dpm_live_abc # ci token', 'dpm_live_abc'],
            'hash inside a quoted value' => ['DEPMAN_TOKEN="dpm_live_a#b"', 'dpm_live_a#b'],
            'hash inside an unquoted value' => ['DEPMAN_TOKEN=dpm_live_a#b', 'dpm_live_a#b'],
            'commented out' => ['# DEPMAN_TOKEN=dpm_live_abc', null],
            'empty' => ['DEPMAN_TOKEN=', null],
            'empty quoted' => ['DEPMAN_TOKEN=""', null],
            'unterminated quote' => ['DEPMAN_TOKEN="dpm_live_abc', null],
            'a different key' => ['DEPMAN_TOKEN_OTHER=dpm_live_abc', null],
            'a key that merely ends with ours' => ['MY_DEPMAN_TOKEN=dpm_live_abc', null],
        ];
    }

    /**
     * @dataProvider dotEnvLines
     */
    #[DataProvider('dotEnvLines')]
    public function test_it_parses_the_shapes_people_actually_write(string $line, ?string $expected): void
    {
        $this->writeEnv("APP_NAME=Acme\n{$line}\nAPP_DEBUG=true\n");

        $this->assertSame($expected, Token::find('https://depman.test', ['HOME' => $this->root], $this->root));
    }

    public function test_an_unterminated_quote_yields_nothing_rather_than_a_truncated_token(): void
    {
        $this->writeEnv("DEPMAN_TOKEN=\"dpm_live_abc\n");

        // Guessing at what was meant is how a truncated credential gets sent,
        // and the resulting 401 blames the server.
        $this->assertNull(Token::find('https://depman.test', ['HOME' => $this->root], $this->root));
    }

    public function test_a_missing_dot_env_is_fine(): void
    {
        $this->assertSame(
            'dpm_live_from_env',
            Token::find('https://depman.test', ['DEPMAN_TOKEN' => 'dpm_live_from_env'], $this->root),
        );
    }

    public function test_it_refuses_an_implausibly_large_dot_env(): void
    {
        $this->writeEnv(str_repeat("PADDING=x\n", 40000)."DEPMAN_TOKEN=dpm_live_abc\n");

        // This runs inside somebody else's composer install; a pathological
        // file must not turn a post-install hook into a memory problem.
        $this->assertGreaterThan(256 * 1024, filesize($this->root.'/.env'));
        $this->assertNull(Token::find('https://depman.test', ['HOME' => $this->root], $this->root));
    }

    public function test_reading_dot_env_does_not_leak_into_the_process_environment(): void
    {
        $this->writeEnv("APP_SECRET=should-not-escape\nDEPMAN_TOKEN=dpm_live_abc\n");

        Token::find('https://depman.test', [], $this->root);

        // No putenv, no $_ENV writes. Reading a consumer's .env must not change
        // how their own application later loads it.
        $this->assertFalse(getenv('APP_SECRET'));
        $this->assertArrayNotHasKey('APP_SECRET', $_ENV);
    }
}
