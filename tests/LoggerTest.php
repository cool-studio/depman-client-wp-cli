<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How much of somebody else's build output this client is entitled to.
 *
 * At the default level a normal install prints at most two lines. Nothing this
 * client does is important enough to clutter a build log — with exactly one
 * exception, which is a credential committed to source control.
 */
class LoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stream = fopen('php://memory', 'rw+');
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @return array<string, array{string, array<int, string>, array<int, string>}>
     */
    public static function levels(): array
    {
        return [
            'silent' => ['silent', [], ['error', 'warn', 'info', 'debug']],
            'error' => ['error', ['error'], ['warn', 'info', 'debug']],
            'warn' => ['warn', ['error', 'warn'], ['info', 'debug']],
            'info' => ['info', ['error', 'warn', 'info'], ['debug']],
            'debug' => ['debug', ['error', 'warn', 'info', 'debug'], []],
        ];
    }

    /**
     * @param  array<int, string>  $shown
     * @param  array<int, string>  $hidden
     *
     * @dataProvider levels
     */
    #[DataProvider('levels')]
    public function test_each_level_shows_itself_and_everything_above_it(string $level, array $shown, array $hidden): void
    {
        $logger = new Logger($level, $this->stream);

        foreach (['error', 'warn', 'info', 'debug'] as $method) {
            $logger->{$method}('this-is-'.$method);
        }

        $output = $this->logged();

        foreach ($shown as $method) {
            $this->assertStringContainsString('this-is-'.$method, $output);
        }

        foreach ($hidden as $method) {
            $this->assertStringNotContainsString('this-is-'.$method, $output);
        }
    }

    public function test_always_prints_even_when_silent(): void
    {
        // Reserved for the one case where staying quiet would be
        // irresponsible: a credential in a committed file.
        (new Logger('silent', $this->stream))->always('a token was found in depman.json');

        $this->assertStringContainsString('a token was found in depman.json', $this->logged());
    }

    public function test_an_unknown_level_falls_back_to_warn(): void
    {
        $logger = new Logger('nonsense', $this->stream);

        $logger->warn('shown');
        $logger->info('hidden');

        $this->assertStringContainsString('shown', $this->logged());
        $this->assertStringNotContainsString('hidden', $this->logged());
    }

    public function test_every_line_is_prefixed_so_it_is_attributable(): void
    {
        // This output lands in the middle of someone else's composer install.
        (new Logger('debug', $this->stream))->info('reported 164 packages');

        $this->assertStringStartsWith('DepMan: ', $this->logged());
    }
}
