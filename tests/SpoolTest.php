<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Logger;
use Depman\WpCli\Spool;
use PHPUnit\Framework\TestCase;

/**
 * The offline queue.
 *
 * An air-gapped build or a flaky runner should not lose its inventory, but a
 * permanently offline project must not fill a disk either — so the directory is
 * capped and the oldest go first: a stale inventory is worth less than a recent
 * one.
 */
class SpoolTest extends TestCase
{
    private string $root;

    private string $directory;

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/depman-spool-'.bin2hex(random_bytes(6));
        // Two levels deep: Spool looks for .gitignore at dirname($dir, 2),
        // which mirrors the real "<project>/.depman/spool" layout.
        $this->directory = $this->root.'/.depman/spool';
        mkdir($this->root, 0755, true);

        $this->stream = fopen('php://memory', 'rw+');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        foreach ([$this->directory, $this->root.'/.depman'] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        if (is_file($this->root.'/.gitignore')) {
            unlink($this->root.'/.gitignore');
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }

        parent::tearDown();
    }

    private function spool(): Spool
    {
        return new Spool($this->directory, new Logger('debug', $this->stream));
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    public function test_it_creates_the_directory_and_writes_the_payload(): void
    {
        $this->assertTrue($this->spool()->write(['depmanWireVersion' => 1, 'packages' => []]));

        $pending = $this->spool()->pending();

        $this->assertCount(1, $pending);
        $this->assertSame(
            ['depmanWireVersion' => 1, 'packages' => []],
            json_decode((string) file_get_contents($pending[0]), true),
        );
    }

    public function test_it_keeps_at_most_twenty_and_discards_the_oldest(): void
    {
        mkdir($this->directory, 0775, true);

        // Named so that a string sort is chronological, which is what pending()
        // relies on and therefore what prune() relies on.
        for ($i = 1; $i <= 25; $i++) {
            file_put_contents(sprintf('%s/20260101-%06d.json', $this->directory, $i), '{}');
        }

        $this->spool()->write(['packages' => []]);

        $pending = $this->spool()->pending();

        $this->assertCount(20, $pending);

        // The five oldest are gone; the newest write survived.
        $names = array_map('basename', $pending);
        $this->assertNotContains('20260101-000001.json', $names);
        $this->assertContains('20260101-000025.json', $names);
    }

    public function test_pending_is_sorted_and_forget_removes_one(): void
    {
        mkdir($this->directory, 0775, true);

        foreach (['20260103-c.json', '20260101-a.json', '20260102-b.json'] as $name) {
            file_put_contents($this->directory.'/'.$name, '{}');
        }

        $spool = $this->spool();

        $this->assertSame(
            ['20260101-a.json', '20260102-b.json', '20260103-c.json'],
            array_map('basename', $spool->pending()),
        );

        $spool->forget($this->directory.'/20260102-b.json');

        $this->assertSame(
            ['20260101-a.json', '20260103-c.json'],
            array_map('basename', $spool->pending()),
        );
    }

    public function test_it_warns_when_the_spool_is_not_gitignored(): void
    {
        // Spooled payloads are an inventory of the project's dependencies.
        // Committing them is not a catastrophe, but nobody means to.
        file_put_contents($this->root.'/.gitignore', "/vendor\n/node_modules\n");

        $this->spool()->write(['packages' => []]);

        $this->assertStringContainsString('not in .gitignore', $this->logged());
    }

    public function test_it_stays_quiet_when_the_spool_is_gitignored(): void
    {
        file_put_contents($this->root.'/.gitignore', "/vendor\n.depman/\n");

        $this->spool()->write(['packages' => []]);

        $this->assertStringNotContainsString('not in .gitignore', $this->logged());
    }

    public function test_it_stays_quiet_when_there_is_no_gitignore_at_all(): void
    {
        $this->spool()->write(['packages' => []]);

        $this->assertStringNotContainsString('not in .gitignore', $this->logged());
    }

    public function test_it_still_warns_for_a_spool_inside_the_project_it_was_told_about(): void
    {
        file_put_contents($this->root.'/.gitignore', "/vendor\n");

        (new Spool($this->directory, new Logger('debug', $this->stream), $this->root))->write(['packages' => []]);

        $this->assertStringContainsString('not in .gitignore', $this->logged());
    }

    public function test_it_says_nothing_about_gitignore_for_a_spool_outside_the_project(): void
    {
        // The default spool lives in the home directory (Config), and whatever
        // .gitignore sits two levels above it there is not the project's.
        $outside = $this->root.'-home/.depman/spool/0123456789ab';
        mkdir(dirname($outside, 2), 0755, true);
        file_put_contents(dirname($outside, 2).'/.gitignore', "/vendor\n");

        (new Spool($outside, new Logger('debug', $this->stream), $this->root))->write(['packages' => []]);

        $this->assertStringNotContainsString('not in .gitignore', $this->logged());

        foreach (glob($outside.'/*.json') ?: [] as $file) {
            unlink($file);
        }
        rmdir($outside);
        unlink(dirname($outside, 2).'/.gitignore');
        rmdir(dirname($outside));
        rmdir(dirname($outside, 2));
        rmdir(dirname($outside, 3));
    }

    public function test_an_unwritable_directory_is_reported_not_thrown(): void
    {
        // The spool is a best effort. Failing to write one must never become an
        // exception on a path that runs inside `composer install`.
        $blocked = $this->root.'/blocked';
        file_put_contents($blocked, 'not a directory');

        $spool = new Spool($blocked.'/spool', new Logger('debug', $this->stream));

        $this->assertFalse($spool->write(['packages' => []]));
        $this->assertStringContainsString('Could not create the spool directory', $this->logged());

        unlink($blocked);
    }
}
