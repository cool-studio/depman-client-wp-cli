<?php

namespace Depman\WpCli\Tests\Smoke;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The artefact a consumer installs, installed the way a consumer installs it.
 *
 * Every other test in this suite runs against the source tree, and the source
 * tree is not what anybody receives. Packagist serves GitHub's zipball of the
 * mirror's tag: the tracked files, minus whatever `.gitattributes` marks
 * `export-ignore`. That shaping is where a package diverges from its source,
 * and a mistake in it is invisible from inside the source -- an over-eager
 * `export-ignore` on `src/` or `bin/` passes every other test here and ships
 * a package that cannot load.
 *
 * So this builds that archive, installs it into a scratch project outside the
 * repository with Composer and no network -- which is what `wp package
 * install` does, into ~/.wp-cli/packages -- and asserts what a consumer
 * depends on: every class autoloads from the installed tree, the file that
 * registers `wp depman` loads without a sound outside WP-CLI, and the command
 * runs from the installed tree. It asserts behaviour, never a manifest field -- PackageTest
 * pins fields, and pinning fields is what let `@depman/client` 0.1.0-0.1.3
 * ship unresolvable (ADR-0048, ADR-0055).
 *
 * It proves the package installs and loads. It does **not** prove the client
 * reports a consumer's tree correctly; that is the live-resolve item in
 * docs/ingest-clients.md, and this does not close it.
 */
class ArtefactTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** What the dist archive holds, at its top level, and nothing else. */
    private const SHIPPED = ['LICENSE', 'README.md', 'command.php', 'composer.json', 'src'];

    private string $scratch;

    private string $package;

    private string $project;

    protected function setUp(): void
    {
        if (!$this->insideGitWorkTree()) {
            // The archive is defined by git -- tracked files, minus
            // export-ignore -- so without a checkout there is no artefact to
            // build. CI and the mirror always have one; a bare `cp -r` copy of
            // the client does not, and is told so rather than failed.
            $this->markTestSkipped('Not a git checkout: the dist archive is built from tracked files.');
        }

        // The system temp directory, never one inside the repository: Composer
        // would find our own vendor/ and composer.json walking up, and the
        // test would pass for the wrong reason.
        $this->scratch = sys_get_temp_dir().'/depman-composer-smoke-'.bin2hex(random_bytes(6));
        $this->package = $this->scratch.'/package';
        $this->project = $this->scratch.'/project';

        mkdir($this->package, 0777, true);
        mkdir($this->project, 0777, true);

        $this->buildArchive();
        $this->installIntoScratchProject();
    }

    protected function tearDown(): void
    {
        if (isset($this->scratch) && is_dir($this->scratch)) {
            $this->remove($this->scratch);
        }
    }

    public function test_the_archive_holds_what_a_consumer_needs_and_nothing_else(): void
    {
        // Moved here from the release checklist, where a human read it. The
        // test suite, the analyser and linter config stay out of other people's
        // vendor/; the LICENSE stays in, because MIT requires it (ADR-0047).
        $entries = array_values(array_diff((array) scandir($this->package), ['.', '..']));
        sort($entries);

        $this->assertSame(self::SHIPPED, $entries);
        $this->assertFileExists($this->package.'/command.php');
    }

    public function test_the_registration_file_is_autoloaded_and_silent_outside_wp_cli(): void
    {
        // Composer autoloads command.php on every load of WP-CLI's package
        // autoloader. Outside WP-CLI the class it registers with does not
        // exist, and it must neither fail nor print.
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            echo class_exists('WP_CLI', false) ? 'wp-cli' : 'loaded', "\n";
            PHP;

        [$code, $out, $err] = $this->execute([PHP_BINARY, '-r', $script], $this->project);

        $this->assertSame(0, $code, $out.$err);
        $this->assertSame("loaded\n", $out);
        $this->assertSame('', $err);
        $this->assertStringContainsString(
            'command.php',
            (string) file_get_contents($this->project.'/vendor/composer/autoload_files.php'),
            'command.php is what registers `wp depman`; Composer must autoload it.'
        );
    }

    public function test_doctor_runs_from_the_installed_tree(): void
    {
        // doctor loads depman.json, resolves the environment and looks for a
        // token -- the whole configuration path -- and makes no request. With
        // no WordPress loaded it says so rather than failing.
        file_put_contents($this->project.'/depman.json', json_encode([
            'configVersion' => 1,
            'endpoint' => 'https://depman.invalid',
            'project' => 'smoke/project',
        ]));

        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $command = new Depman\WpCli\Command(null, null, null, getcwd());
            exit($command->runDoctor([]));
            PHP;

        [$code, $out, $err] = $this->execute([PHP_BINARY, '-r', $script], $this->project);

        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('smoke/project (from depman.json)', $out);
        $this->assertStringContainsString('WordPress:    not loaded', $out);
    }

    public function test_every_class_autoloads_from_the_installed_tree(): void
    {
        // PSR-4 maps a namespace onto a directory, so a class whose file is
        // missing from the archive, or misnamed for its class, fails here and
        // nowhere else. Each one must also come from vendor/ -- never from
        // this checkout -- or the install proved nothing.
        $installed = $this->project.'/vendor/depman/wp-cli-client/src';
        $classes = array_map(
            fn (string $file): string => 'Depman\\WpCli\\'.basename($file, '.php'),
            (array) glob($installed.'/*.php'),
        );

        $this->assertNotEmpty($classes);

        $script = <<<'PHP'
            require 'vendor/autoload.php';
            foreach (json_decode($argv[1], true) as $class) {
                if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
                    echo "missing {$class}\n";
                    continue;
                }
                echo (new ReflectionClass($class))->getFileName(), "\n";
            }
            PHP;

        [$code, $out, $err] = $this->execute([PHP_BINARY, '-r', $script, json_encode($classes)], $this->project);

        $this->assertSame(0, $code, $out.$err);

        $lines = array_filter(explode("\n", trim($out)));
        $this->assertCount(count($classes), $lines, $out.$err);

        foreach ($lines as $line) {
            $this->assertStringStartsWith(realpath($installed).'/', realpath($line) ?: $line, $out);
        }
    }

    /**
     * Copy the tracked files git would archive into $this->package.
     *
     * `git archive` cannot read the working tree, so this is its rule applied
     * by hand: `git ls-files`, minus any path that is -- or sits under a
     * directory that is -- `export-ignore`. `check-attr` reports a directory
     * pattern such as `/tests` against the directory only, never against the
     * files inside it, which is why every ancestor is asked as well.
     *
     * `composer archive` is not a substitute: it packs untracked files too,
     * caches included, so it is not the artefact Packagist serves.
     */
    private function buildArchive(): void
    {
        [$code, $out, $err] = $this->execute(['git', 'ls-files', '-z'], self::ROOT);
        $this->assertSame(0, $code, $out.$err);

        $files = array_values(array_filter(explode("\0", $out)));
        $candidates = [];

        foreach ($files as $file) {
            for ($path = $file; $path !== '.'; $path = dirname($path)) {
                $candidates[$path] = true;
            }
        }

        [$code, $out, $err] = $this->execute(
            ['git', 'check-attr', '-z', '--stdin', 'export-ignore'],
            self::ROOT,
            implode("\0", array_keys($candidates))."\0",
        );
        $this->assertSame(0, $code, $out.$err);

        $parts = explode("\0", rtrim($out, "\0"));
        $ignored = [];

        for ($i = 0; $i + 2 < count($parts); $i += 3) {
            if ($parts[$i + 2] === 'set') {
                $ignored[$parts[$i]] = true;
            }
        }

        foreach ($files as $file) {
            for ($path = $file; $path !== '.'; $path = dirname($path)) {
                if (isset($ignored[$path])) {
                    continue 2;
                }
            }

            $target = $this->package.'/'.$file;

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }

            copy(self::ROOT.'/'.$file, $target);
            chmod($target, (int) fileperms(self::ROOT.'/'.$file) & 0777);
        }
    }

    /**
     * Require the package into a fresh project, from the archive, offline.
     *
     * A `path` repository with `symlink: false` mirrors the files in, exactly
     * as a dist install unpacks them. Packagist is switched off: the client
     * has no runtime dependency, so an install that needs the network is a
     * bug, and a release gate that needs it fails on a bad afternoon.
     */
    private function installIntoScratchProject(): void
    {
        file_put_contents($this->project.'/composer.json', json_encode([
            'name' => 'depman-smoke/project',
            'require' => ['depman/wp-cli-client' => '*'],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $this->package,
                    'options' => ['symlink' => false, 'versions' => ['depman/wp-cli-client' => '0.0.0']],
                ],
                ['packagist.org' => false],
            ],
        ], JSON_UNESCAPED_SLASHES));

        [$code, $out, $err] = $this->execute(
            [...$this->composer(), 'install', '--no-interaction', '--no-progress'],
            $this->project,
        );

        $this->assertSame(0, $code, "composer install from the archive failed:\n".$out.$err);
    }

    /**
     * The Composer that is running this check, when there is one.
     *
     * Under `composer check` that is COMPOSER_BINARY, so the install uses the
     * same Composer the developer or CI chose; otherwise whatever is on PATH.
     *
     * @return list<string>
     */
    private function composer(): array
    {
        $binary = getenv('COMPOSER_BINARY');

        return is_string($binary) && $binary !== '' ? [PHP_BINARY, $binary] : ['composer'];
    }

    /**
     * Run a command; return its exit code, its stdout and its stderr.
     *
     * The Composer variables that point at a project are dropped, because
     * `composer check` sets some of them for *this* package, and inheriting
     * them would aim the scratch install back at the source tree.
     *
     * @param  list<string>  $command
     * @return array{int, string, string}
     */
    private function execute(array $command, string $cwd, ?string $stdin = null): array
    {
        $env = getenv();

        foreach (['COMPOSER', 'COMPOSER_VENDOR_DIR', 'COMPOSER_BIN_DIR', 'COMPOSER_ROOT_VERSION'] as $key) {
            unset($env[$key]);
        }

        $env['COMPOSER_DISABLE_NETWORK'] = '1';
        $env['COMPOSER_NO_INTERACTION'] = '1';

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start '.implode(' ', $command));
        }

        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function insideGitWorkTree(): bool
    {
        [$code, $out, $err] = $this->execute(['git', 'rev-parse', '--is-inside-work-tree'], self::ROOT);

        return $code === 0 && trim($out) === 'true';
    }

    private function remove(string $directory): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
