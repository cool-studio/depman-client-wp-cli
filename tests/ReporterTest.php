<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Config;
use Depman\WpCli\Logger;
use Depman\WpCli\Reporter;
use Depman\WpCli\Resolver;
use Depman\WpCli\Site;
use PHPUnit\Framework\TestCase;

/**
 * The wire payload, and the digest the server recomputes.
 *
 * **The digest is the one number that must match the server byte for byte.** If
 * the two computations drift, every report carries a spurious `digest_mismatch`
 * warning and semantic dedupe silently stops working — a post-install hook
 * fires on every CI install, so most reports are meant to cost nothing.
 * Agreement with the server is proved by the application's DogfoodTest; what is
 * proved here is that the client's half has the properties it claims.
 */
class ReporterTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stream = fopen('php://memory', 'rw+');
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/wire/v1/'.$name), true);
        $this->assertIsArray($fixture);

        return $fixture;
    }

    private function reporter(): Reporter
    {
        return new Reporter(new Logger('silent', $this->stream));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packages(): array
    {
        return [
            ['purl' => 'pkg:composer/acme/widget@1.2.3', 'scope' => 'runtime', 'relationship' => 'direct'],
            ['purl' => 'pkg:composer/acme/gadget@2.0.0', 'scope' => 'dev', 'relationship' => 'transitive'],
        ];
    }

    public function test_the_digest_is_order_independent(): void
    {
        $packages = $this->packages();

        $this->assertSame(
            $this->reporter()->digest($packages, 'production'),
            $this->reporter()->digest(array_reverse($packages), 'production'),
        );
    }

    public function test_the_digest_is_environment_specific(): void
    {
        // The same tree in staging and production are different facts, and
        // deduplicating across them would hide one of them entirely.
        $this->assertNotSame(
            $this->reporter()->digest($this->packages(), 'production'),
            $this->reporter()->digest($this->packages(), 'staging'),
        );
    }

    public function test_the_digest_changes_when_a_version_changes(): void
    {
        $changed = $this->packages();
        $changed[0]['purl'] = 'pkg:composer/acme/widget@1.2.4';

        $this->assertNotSame(
            $this->reporter()->digest($this->packages(), 'production'),
            $this->reporter()->digest($changed, 'production'),
        );
    }

    public function test_the_digest_changes_when_only_the_scope_changes(): void
    {
        // Moving a package from dev to runtime changes what is exposed in
        // production, so it must not deduplicate against the previous report.
        $changed = $this->packages();
        $changed[1]['scope'] = 'runtime';

        $this->assertNotSame(
            $this->reporter()->digest($this->packages(), 'production'),
            $this->reporter()->digest($changed, 'production'),
        );
    }

    public function test_the_digest_is_stable_across_calls(): void
    {
        // No timestamp, no client version, no nonce — semantic content only.
        $this->assertSame(
            $this->reporter()->digest($this->packages(), 'production'),
            $this->reporter()->digest($this->packages(), 'production'),
        );

        $this->assertStringStartsWith('sha256:', $this->reporter()->digest($this->packages(), 'production'));
    }

    public function test_the_payload_declares_the_wire_version_and_client_identity(): void
    {
        $config = Config::fromArray('/tmp/project', ['project' => 'acme/storefront'], new Logger('silent', $this->stream));

        $payload = $this->reporter()->buildPayload(
            $config,
            $this->packages(),
            ['name' => 'production', 'resolvedFrom' => 'DEPMAN_ENV'],
            'postinstall',
        );

        $this->assertSame(1, $payload['depmanWireVersion']);
        $this->assertSame('depman/wp-cli-client', $payload['client']['name']);
        $this->assertSame(Reporter::CLIENT_VERSION, $payload['client']['version']);
        // WP-CLI is what read the site. Outside a WP-CLI process -- this test --
        // there is no version to name, and null says so rather than guessing.
        $this->assertSame('wp-cli', $payload['client']['packageManager']['name']);
        $this->assertNull($payload['client']['packageManager']['version']);
        $this->assertSame('php', $payload['client']['runtime']['name']);
        // The identities are Composer ones -- wpackagist, and the core package
        // a curated equivalence maps -- so the ecosystem is too (ADR-0056).
        $this->assertSame('composer', $payload['ecosystem']);
        // A WordPress site has no lockfile, so none is named.
        $this->assertSame(['path' => '.'], $payload['manifest']);
        $this->assertSame('postinstall', $payload['report']['reason']);
        $this->assertSame(2, $payload['counts']['packages']);
        $this->assertSame(
            $this->reporter()->digest($this->packages(), 'production'),
            $payload['report']['manifestDigest'],
        );
    }

    public function test_it_reproduces_the_canonical_wire_fixture_digest(): void
    {
        // The cross-client conformance artefact. Every client ships a
        // byte-identical copy of this payload and must reproduce its digest
        // from its own implementation — which checks agreement with the server
        // without needing a server, and checks agreement between clients
        // without them ever meeting. WireFixtureParityTest proves the copies
        // have not drifted.
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/wire/v1/composer-report.json'),
            true,
        );

        $this->assertIsArray($fixture);
        $this->assertSame(Reporter::WIRE_VERSION, $fixture['depmanWireVersion']);

        $this->assertSame(
            $fixture['report']['manifestDigest'],
            $this->reporter()->digest($fixture['packages'], $fixture['environment']['name']),
        );
    }

    public function test_it_reproduces_its_own_wordpress_fixture_digest(): void
    {
        $fixture = $this->fixture('wp-cli-report.json');

        $this->assertSame(
            $fixture['report']['manifestDigest'],
            $this->reporter()->digest($fixture['packages'], $fixture['environment']['name']),
        );
    }

    public function test_the_resolver_produces_exactly_the_fixture_entries_for_the_site_it_describes(): void
    {
        // The third link, which a fixture alone cannot make: what this client
        // actually emits for a site IS what the fixture, the server's parser
        // and DogfoodTest agree on -- names, types, versions, key set and order.
        $site = new Site('6.8.3', [
            'akismet/akismet.php' => ['Version' => '5.3.3'],
            'acme-pro/acme-pro.php' => ['Version' => '2.1.0'],
        ], [
            'akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet', 'slug' => 'akismet'],
        ], [
            'twentytwentyfour' => ['Version' => '1.2'],
        ], [
            'twentytwentyfour' => ['theme' => 'twentytwentyfour', 'url' => 'https://wordpress.org/themes/twentytwentyfour/'],
        ], [], [
            'object-cache.php' => ['Name' => 'Object cache'],
        ]);

        $entries = (new Resolver(new FixedSiteReader($site), new Logger('silent', $this->stream)))->entries($site);

        $this->assertSame($this->fixture('wp-cli-report.json')['packages'], $entries);
    }

    public function test_its_payload_has_exactly_the_shape_of_its_own_fixture(): void
    {
        $fixture = $this->fixture('wp-cli-report.json');

        $built = $this->reporter()->buildPayload(
            Config::fromArray('/tmp/project', ['project' => 'example-blog'], new Logger('silent', $this->stream)),
            $fixture['packages'],
            ['name' => 'production', 'resolvedFrom' => 'DEPMAN_ENV'],
            'manual',
        );

        $this->assertSame(array_keys($fixture), array_keys($built));

        foreach (['report', 'environment', 'client', 'manifest', 'counts'] as $section) {
            $this->assertSame(array_keys($fixture[$section]), array_keys($built[$section]), "[{$section}] has drifted.");
        }

        $this->assertSame($fixture['client']['name'], $built['client']['name']);
        $this->assertSame($fixture['client']['packageManager']['name'], $built['client']['packageManager']['name']);
        $this->assertSame($fixture['ecosystem'], $built['ecosystem']);
        $this->assertSame($fixture['report']['manifestDigest'], $built['report']['manifestDigest']);
    }

    public function test_its_payload_has_the_same_shape_as_the_canonical_fixture(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/wire/v1/composer-report.json'),
            true,
        );

        $built = $this->reporter()->buildPayload(
            Config::fromArray('/tmp/project', ['project' => 'storefront'], new Logger('silent', $this->stream)),
            $this->packages(),
            ['name' => 'production', 'resolvedFrom' => 'APP_ENV'],
            'postinstall',
        );

        // Keys, not values: the values are environment-specific, but a missing
        // or renamed key is a wire break and the server would only tell us by
        // rejecting reports in production.
        $this->assertSame(array_keys($fixture), array_keys($built));

        foreach (['report', 'environment', 'client', 'counts'] as $section) {
            $this->assertSame(
                array_keys($fixture[$section]),
                array_keys($built[$section]),
                "The [{$section}] section has drifted from the canonical payload.",
            );
        }

        // The manifest section is the one deliberate difference: `lockfileName`
        // is optional on the wire, and a WordPress site has no lockfile to name.
        $this->assertSame(['path'], array_keys($built['manifest']));
        $this->assertArrayHasKey('path', $fixture['manifest']);

        // The envelope only. The fixture's three package entries deliberately
        // differ from one another — one carries requestedConstraint, one
        // carries paths, one carries neither — because its job is to pin down
        // which per-package fields are optional. Asserting a single key list
        // against it would assert the opposite of what it documents. The shape
        // this client emits is ResolverTest's subject.
    }

    public function test_the_payload_never_contains_the_token(): void
    {
        $config = Config::fromArray('/tmp/project', [
            'project' => 'acme/storefront',
            // Even if somebody has wrongly committed one, it must not travel in
            // the body: it belongs in the Authorization header and nowhere else.
            'token' => 'dpm_live_leaked',
        ], new Logger('silent', $this->stream));

        $payload = $this->reporter()->buildPayload(
            $config,
            $this->packages(),
            ['name' => 'production', 'resolvedFrom' => 'DEPMAN_ENV'],
            'manual',
        );

        $this->assertStringNotContainsString('dpm_live_leaked', (string) json_encode($payload));
    }
}
