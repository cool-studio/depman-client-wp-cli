<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Logger;
use Depman\WpCli\Resolver;
use Depman\WpCli\Site;
use Depman\WpCli\SiteReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The identity rules. Every test here is a way of naming somebody else's
 * plugin, and the resolver must refuse each one (ADR-0056).
 */
class ResolverTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            @unlink($dir.'/vendor/composer/installed.json');
            @rmdir($dir.'/vendor/composer');
            @rmdir($dir.'/vendor');
            @rmdir($dir);
        }
    }

    public function test_a_plugin_wordpress_org_recognised_is_reported_under_its_slug(): void
    {
        $site = new Site('6.8.3', [
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3.3'],
        ], [
            'akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet', 'slug' => 'akismet'],
        ]);

        $this->assertSame([
            'pkg:composer/johnpbloch/wordpress-core@6.8.3',
            'pkg:composer/wpackagist-plugin/akismet@5.3.3',
        ], $this->purls($site));
    }

    public function test_a_directory_name_is_never_taken_for_a_slug(): void
    {
        // The directory is `akismet`, which is a wordpress.org slug, but
        // nothing has told WordPress this IS that plugin. A premium plugin
        // shipped under a colliding directory name looks exactly like this.
        $site = new Site(null, [
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3.3'],
        ]);

        $this->assertSame(['pkg:composer/wordpress-plugin--local/akismet@5.3.3'], $this->purls($site));
    }

    public function test_an_entry_a_third_party_updater_injected_is_not_trusted(): void
    {
        // Commercial plugins push their own entries into update_plugins, under
        // their own ids. Only wordpress.org's id format names a wordpress.org slug.
        $site = new Site(null, [
            'acme-pro/acme-pro.php' => ['Version' => '2.1.0'],
        ], [
            'acme-pro/acme-pro.php' => (object) ['id' => 'acme-pro/acme-pro.php', 'slug' => 'acme-pro', 'url' => 'https://acme.example/'],
        ]);

        $this->assertSame(['pkg:composer/wordpress-plugin--local/acme-pro@2.1.0'], $this->purls($site));
    }

    public function test_an_id_that_names_a_different_slug_is_not_trusted(): void
    {
        $site = new Site(null, [
            'acme/acme.php' => ['Version' => '1.0.0'],
        ], [
            'acme/acme.php' => ['id' => 'w.org/plugins/akismet', 'slug' => 'acme'],
        ]);

        $this->assertSame(['pkg:composer/wordpress-plugin--local/acme@1.0.0'], $this->purls($site));
    }

    public function test_an_array_shaped_transient_entry_is_read_like_an_object(): void
    {
        $site = new Site(null, [
            'hello-dolly/hello.php' => ['Version' => '1.7.2'],
        ], [
            'hello-dolly/hello.php' => ['id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly'],
        ]);

        $this->assertSame(['pkg:composer/wpackagist-plugin/hello-dolly@1.7.2'], $this->purls($site));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function updateUris(): array
    {
        return [
            'the documented URL form' => ['https://wordpress.org/plugins/akismet/', 'akismet'],
            'without a trailing slash' => ['https://wordpress.org/plugins/akismet', 'akismet'],
            'the w.org shorthand' => ['w.org/plugin/akismet', 'akismet'],
            'www' => ['https://www.wordpress.org/plugins/akismet/', 'akismet'],
            'another host' => ['https://acme.example/plugins/akismet/', null],
            'updates disabled' => ['false', null],
            'a wordpress.org page that is not a plugin' => ['https://wordpress.org/themes/akismet/', null],
            'a lookalike host' => ['https://wordpress.org.acme.example/plugins/akismet/', null],
        ];
    }

    /**
     * @dataProvider updateUris
     */
    #[DataProvider('updateUris')]
    public function test_an_update_uri_header_decides_outright(string $uri, ?string $slug): void
    {
        // The transient below claims wordpress.org recognised it. The header
        // still wins: WordPress itself never asks wordpress.org about a plugin
        // whose Update URI names somewhere else.
        $site = new Site(null, [
            'akismet/akismet.php' => ['Version' => '5.3.3', 'UpdateURI' => $uri],
        ], [
            'akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet', 'slug' => 'akismet'],
        ]);

        $expected = $slug === null
            ? 'pkg:composer/wordpress-plugin--local/akismet@5.3.3'
            : 'pkg:composer/wpackagist-plugin/'.$slug.'@5.3.3';

        $this->assertSame([$expected], $this->purls($site));
    }

    public function test_an_update_uri_identifies_a_plugin_the_transient_has_not_seen_yet(): void
    {
        $site = new Site(null, [
            'renamed-dir/akismet.php' => ['Version' => '5.3.3', 'UpdateURI' => 'https://wordpress.org/plugins/akismet/'],
        ]);

        $this->assertSame(['pkg:composer/wpackagist-plugin/akismet@5.3.3'], $this->purls($site));
    }

    public function test_a_theme_is_identified_by_the_wordpress_org_page_its_transient_entry_names(): void
    {
        $site = new Site(null, [], [], [
            'twentytwentyfour' => ['Name' => 'Twenty Twenty-Four', 'Version' => '1.2'],
            'acme-theme' => ['Name' => 'Acme', 'Version' => '3.0'],
            'child-of-acme' => ['Name' => 'Child', 'Version' => '1.0'],
        ], [
            'twentytwentyfour' => ['theme' => 'twentytwentyfour', 'url' => 'https://wordpress.org/themes/twentytwentyfour/'],
            // A commercial theme's updater, answering with its vendor's page.
            'acme-theme' => ['theme' => 'acme-theme', 'url' => 'https://acme.example/themes/acme-theme/'],
        ]);

        $this->assertSame([
            'pkg:composer/wordpress-theme--local/acme-theme@3.0',
            'pkg:composer/wordpress-theme--local/child-of-acme@1.0',
            'pkg:composer/wpackagist-theme/twentytwentyfour@1.2',
        ], $this->purls($site));
    }

    public function test_core_is_reported_under_the_name_the_curated_equivalence_reaches(): void
    {
        $entries = $this->resolver(new Site('6.8.3'))->entries(new Site('6.8.3'));

        $this->assertSame([[
            'purl' => 'pkg:composer/johnpbloch/wordpress-core@6.8.3',
            'name' => 'johnpbloch/wordpress-core',
            'version' => '6.8.3',
            'scope' => 'runtime',
            'relationship' => 'direct',
            'type' => 'wordpress-core',
        ]], $entries);
    }

    public function test_must_use_plugins_and_drop_ins_are_always_local_inventory(): void
    {
        $site = new Site(null, [], [], [], [], [
            'loader.php' => ['Name' => 'Loader', 'Version' => '1.1'],
        ], [
            'object-cache.php' => ['Name' => 'Redis Object Cache Drop-In', 'Version' => ''],
        ]);

        $entries = $this->resolver($site)->entries($site);

        $this->assertSame([
            ['pkg:composer/wordpress-dropin--local/object-cache@unknown', 'wordpress-dropin'],
            ['pkg:composer/wordpress-muplugin--local/loader@1.1', 'wordpress-muplugin'],
        ], array_map(static function (array $e): array {
            return [$e['purl'], $e['type']];
        }, $entries));
    }

    public function test_every_entry_carries_its_installer_type(): void
    {
        $site = new Site('6.8.3', [
            'akismet/akismet.php' => ['Version' => '5.3.3'],
        ], [], [
            'twentytwentyfour' => ['Version' => '1.2'],
        ]);

        $types = array_column($this->resolver($site)->entries($site), 'type', 'name');

        $this->assertSame([
            'johnpbloch/wordpress-core' => 'wordpress-core',
            'wordpress-plugin--local/akismet' => 'wordpress-plugin',
            'wordpress-theme--local/twentytwentyfour' => 'wordpress-theme',
        ], $types);
    }

    public function test_a_single_file_plugin_is_labelled_by_its_file(): void
    {
        $site = new Site(null, ['hello.php' => ['Version' => '1.7.2']]);

        $this->assertSame(['pkg:composer/wordpress-plugin--local/hello@1.7.2'], $this->purls($site));
    }

    public function test_local_labels_are_normalised_to_what_a_purl_can_carry(): void
    {
        $site = new Site(null, [
            'My Plugin (Pro)/main.php' => ['Version' => '1.0'],
            '___/main.php' => ['Version' => '1.0'],
        ]);

        $this->assertSame([
            'pkg:composer/wordpress-plugin--local/my-plugin-pro@1.0',
            'pkg:composer/wordpress-plugin--local/unnamed@1.0',
        ], $this->purls($site));
    }

    public function test_a_version_is_percent_encoded_in_the_purl_and_verbatim_beside_it(): void
    {
        $site = new Site(null, ['beta/beta.php' => ['Version' => '2.0 beta+1']]);

        $entry = $this->resolver($site)->entries($site)[0];

        $this->assertSame('pkg:composer/wordpress-plugin--local/beta@2.0%20beta%2B1', $entry['purl']);
        $this->assertSame('2.0 beta+1', $entry['version']);
    }

    public function test_a_package_with_no_version_header_is_still_reported(): void
    {
        // Omitting it would make the inventory claim the site has less installed
        // than it has. `unknown` lands as unparseable inventory, never matched.
        $site = new Site(null, ['bare/bare.php' => ['Name' => 'Bare']]);

        $this->assertSame(['pkg:composer/wordpress-plugin--local/bare@unknown'], $this->purls($site));
    }

    public function test_entries_are_unique_and_in_byte_order(): void
    {
        $site = new Site('6.8.3', [
            'zeta/zeta.php' => ['Version' => '1.0'],
            'Zeta/other.php' => ['Version' => '1.0'],
            'alpha/alpha.php' => ['Version' => '1.0'],
        ]);

        $purls = $this->purls($site);
        $sorted = $purls;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $purls);
        $this->assertSame(array_values(array_unique($purls)), $purls);
        $this->assertCount(3, $purls);
    }

    public function test_the_local_namespaces_are_names_packagist_can_never_issue(): void
    {
        // Composer's own validation pattern for a package name. A vendor that
        // fails it can never exist on Packagist, so no advisory can name it.
        $packagist = '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}';

        foreach ([Resolver::LOCAL_PLUGIN, Resolver::LOCAL_THEME, Resolver::LOCAL_MUPLUGIN, Resolver::LOCAL_DROPIN] as $vendor) {
            $this->assertSame(0, preg_match($packagist, $vendor.'/anything'), $vendor.' would be a valid Packagist name.');
        }

        $this->assertSame(1, preg_match($packagist, 'wpackagist-plugin/akismet'), 'The pattern accepts real names.');
    }

    public function test_it_refuses_a_directory_the_composer_client_already_reports(): void
    {
        $root = $this->tempRoot();
        mkdir($root.'/vendor/composer', 0777, true);
        file_put_contents($root.'/vendor/composer/installed.json', json_encode([
            'packages' => [['name' => 'roots/wordpress'], ['name' => 'depman/client']],
        ]));

        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $resolver = new Resolver($this->reader(new Site('6.8.3')), new Logger('warn', $stream));

        $this->assertNull($resolver->resolve($root));

        rewind($stream);
        $this->assertStringContainsString('depman/client is installed in this directory', (string) stream_get_contents($stream));
    }

    public function test_a_composer_tree_without_the_composer_client_is_not_a_conflict(): void
    {
        $root = $this->tempRoot();
        mkdir($root.'/vendor/composer', 0777, true);
        file_put_contents($root.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'psr/log']]]));

        $this->assertNotNull($this->resolver(new Site('6.8.3'))->resolve($root));
    }

    public function test_no_wordpress_is_nothing_to_report(): void
    {
        $this->assertNull($this->resolver(null)->resolve($this->tempRoot()));
    }

    /**
     * @return list<string>
     */
    private function purls(Site $site): array
    {
        return array_column($this->resolver($site)->entries($site), 'purl');
    }

    private function resolver(?Site $site): Resolver
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        return new Resolver($this->reader($site), new Logger('silent', $stream));
    }

    private function reader(?Site $site): SiteReader
    {
        return new FixedSiteReader($site);
    }

    private function tempRoot(): string
    {
        $dir = sys_get_temp_dir().'/depman-wp-resolver-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
