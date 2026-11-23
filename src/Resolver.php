<?php

namespace Depman\WpCli;

/**
 * Turns what is installed on a WordPress site into report entries.
 *
 * **The one rule here is: never guess an identity.** An advisory names a
 * plugin by its wordpress.org slug, which DepMan matches as
 * `wpackagist-plugin/{slug}` (ADR-0056). A plugin's directory name is usually
 * that slug and sometimes is not -- renamed, installed from a zip, or a
 * premium plugin that was never on wordpress.org and happens to share a
 * directory name with one that was. A wrong identity produces confident
 * Findings about somebody else's plugin, which is worse than no Finding at
 * all. So a slug is used only where WordPress itself has been told it:
 *
 *   1. An `Update URI` header decides outright. One naming wordpress.org or
 *      w.org gives the slug; any other value -- another host, `false` -- says
 *      the plugin is not from wordpress.org, and WordPress itself then never
 *      asks wordpress.org about it.
 *   2. Otherwise, an update_plugins transient entry whose `id` is exactly
 *      `w.org/plugins/{slug}`. That is wordpress.org's own answer to "which of
 *      ours is this?", and it is what WordPress acts on when it updates the
 *      plugin. Third-party updaters inject entries too, under their own ids,
 *      and those are deliberately not trusted.
 *   3. Otherwise the plugin is reported as inventory under the `--local`
 *      namespace, which Packagist's naming rules can never issue, so no
 *      advisory can ever match it. The server counts it as a coverage gap.
 *
 * Themes follow the same rules with their transient's `url`. Core is reported
 * as `johnpbloch/wordpress-core`, which a curated Package Equivalence joins to
 * WordPress core's advisories. Must-use plugins and drop-ins have no slug and
 * no update channel at all, so they are always local inventory.
 */
class Resolver
{
    /** The Composer identity a curated equivalence maps to WordPress core. */
    public const CORE = 'johnpbloch/wordpress-core';

    /**
     * Vendor namespaces for what cannot be identified.
     *
     * Packagist requires a vendor name to match [a-z0-9]([_.-]?[a-z0-9]+)*, so
     * a double hyphen can never appear in one. That is the whole point: a real
     * package -- and so an advisory -- can never carry these names. A plain
     * `wordpress-plugin/` would not do; Packagist already has packages under it.
     */
    public const LOCAL_PLUGIN = 'wordpress-plugin--local';

    public const LOCAL_THEME = 'wordpress-theme--local';

    public const LOCAL_MUPLUGIN = 'wordpress-muplugin--local';

    public const LOCAL_DROPIN = 'wordpress-dropin--local';

    /** Sent for a package with no version header, so it lands as unparseable inventory. */
    public const UNKNOWN_VERSION = 'unknown';

    /** @var SiteReader */
    private $reader;

    /** @var Logger */
    private $logger;

    public function __construct(SiteReader $reader, Logger $logger)
    {
        $this->reader = $reader;
        $this->logger = $logger;
    }

    /**
     * The report entries for the site, or null when there is nothing to report.
     *
     * `$includeDev` is accepted for the shared Client's sake and has nothing to
     * act on: a WordPress site has no dev dependencies.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function resolve(string $projectRoot, bool $includeDev = true): ?array
    {
        if ($this->composerClientReportsHere($projectRoot)) {
            // Both clients would report ecosystem `composer` at manifest path
            // `.`, and each report replaces the other's tree. Refuse by name
            // rather than flap (docs/ingest-clients.md §5).
            $this->logger->warn(
                'depman/client is installed in this directory and already reports it. A Composer-managed '
                .'WordPress site is covered by that client; this command reports sites installed without '
                .'Composer. Skipping.'
            );

            return null;
        }

        $site = $this->reader->read();

        if ($site === null) {
            return null;
        }

        $packages = $this->entries($site);

        $local = count(array_filter($packages, static function (array $entry): bool {
            return strpos((string) $entry['name'], '--local/') !== false;
        }));

        if ($local > 0) {
            $this->logger->info(sprintf(
                '%d of %d installed packages could not be identified on wordpress.org; reported as inventory, never matched.',
                $local,
                count($packages)
            ));
        }

        return $packages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function entries(Site $site): array
    {
        $entries = [];

        if ($site->coreVersion !== null && trim($site->coreVersion) !== '') {
            $entries[] = $this->entry(self::CORE, trim($site->coreVersion), 'wordpress-core');
        }

        foreach ($site->plugins as $file => $headers) {
            $slug = $this->pluginSlug((string) $file, $headers, $site->pluginUpdates[$file] ?? null);

            $entries[] = $this->entry(
                $slug !== null ? 'wpackagist-plugin/'.$slug : self::LOCAL_PLUGIN.'/'.$this->localName($this->pluginBasename((string) $file)),
                $this->version($headers),
                'wordpress-plugin'
            );
        }

        foreach ($site->themes as $stylesheet => $headers) {
            $slug = $this->themeSlug((string) $stylesheet, $headers, $site->themeUpdates[$stylesheet] ?? null);

            $entries[] = $this->entry(
                $slug !== null ? 'wpackagist-theme/'.$slug : self::LOCAL_THEME.'/'.$this->localName((string) $stylesheet),
                $this->version($headers),
                'wordpress-theme'
            );
        }

        foreach ($site->muPlugins as $file => $headers) {
            $entries[] = $this->entry(
                self::LOCAL_MUPLUGIN.'/'.$this->localName($this->pluginBasename((string) $file)),
                $this->version($headers),
                'wordpress-muplugin'
            );
        }

        foreach ($site->dropins as $file => $headers) {
            $entries[] = $this->entry(
                self::LOCAL_DROPIN.'/'.$this->localName($this->pluginBasename((string) $file)),
                $this->version($headers),
                'wordpress-dropin'
            );
        }

        return $this->deduplicate($entries);
    }

    /**
     * The wordpress.org slug WordPress has been told this plugin has, or null.
     *
     * @param  array<string, mixed>  $headers
     * @param  mixed  $update  the transient entry: an object from core, an array from some filters
     */
    public function pluginSlug(string $file, array $headers, $update): ?string
    {
        $uri = $this->header($headers, 'UpdateURI');

        if ($uri !== null) {
            // An explicit Update URI decides, either way. WordPress itself
            // stops asking wordpress.org about a plugin that names another
            // host, so the transient cannot be trusted to say otherwise.
            return $this->slugFromUri($uri, 'plugins');
        }

        $id = $this->field($update, 'id');
        $slug = $this->field($update, 'slug');

        if ($id === null || $slug === null || !$this->isSlug($slug)) {
            return null;
        }

        return $id === 'w.org/plugins/'.$slug ? $slug : null;
    }

    /**
     * The wordpress.org slug WordPress has been told this theme has, or null.
     *
     * @param  array<string, mixed>  $headers
     * @param  mixed  $update
     */
    public function themeSlug(string $stylesheet, array $headers, $update): ?string
    {
        $uri = $this->header($headers, 'UpdateURI');

        if ($uri !== null) {
            return $this->slugFromUri($uri, 'themes');
        }

        $slug = $this->field($update, 'theme');
        $url = $this->field($update, 'url');

        if ($slug === null || $url === null || !$this->isSlug($slug)) {
            return null;
        }

        // wordpress.org's themes API answers with the theme's own directory
        // page. An updater for a commercial theme answers with its vendor's.
        return $this->slugFromUri($url, 'themes') === $slug ? $slug : null;
    }

    /**
     * The slug in a wordpress.org URI, accepting the forms WordPress documents
     * for `Update URI`: `https://wordpress.org/plugins/{slug}/` and
     * `w.org/plugin/{slug}`. Anything else names a different update channel.
     */
    private function slugFromUri(string $uri, string $kind): ?string
    {
        $uri = strtolower(trim($uri));
        $uri = (string) preg_replace('#^https?://#', '', $uri);
        $singular = substr($kind, 0, -1);

        $pattern = '#^(?:www\.)?(?:wordpress\.org|w\.org)/(?:'.$kind.'|'.$singular.')/([a-z0-9._-]+)/?$#';

        if (preg_match($pattern, $uri, $match) !== 1) {
            return null;
        }

        return $this->isSlug($match[1]) ? $match[1] : null;
    }

    /**
     * A wordpress.org slug: lowercase letters, digits and hyphens. Anything
     * else did not come from wordpress.org, whatever the entry claims.
     */
    private function isSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function header(array $headers, string $key): ?string
    {
        $value = $headers[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function version(array $headers): string
    {
        return $this->header($headers, 'Version') ?? self::UNKNOWN_VERSION;
    }

    /**
     * One field from a transient entry, which core stores as an object and
     * some plugins' filters push as an array.
     *
     * @param  mixed  $entry
     */
    private function field($entry, string $key): ?string
    {
        if (is_object($entry)) {
            $entry = get_object_vars($entry);
        }

        if (!is_array($entry) || !isset($entry[$key]) || !is_string($entry[$key])) {
            return null;
        }

        $value = trim($entry[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * `akismet/akismet.php` is reported under `akismet`, and a single-file
     * plugin `hello.php` under `hello`. A label inside the local namespace,
     * never a claim of identity.
     */
    private function pluginBasename(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $slash = strpos($file, '/');

        if ($slash !== false) {
            return substr($file, 0, $slash);
        }

        return (string) preg_replace('/\.php$/i', '', $file);
    }

    /**
     * A name Composer's purl form can carry: lowercase, and anything outside
     * [a-z0-9._-] collapsed to a hyphen.
     */
    private function localName(string $raw): string
    {
        $name = strtolower($raw);
        $name = (string) preg_replace('/[^a-z0-9._-]+/', '-', $name);
        $name = trim((string) preg_replace('/-{2,}/', '-', $name), '-._');

        return $name === '' ? 'unnamed' : $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $name, string $version, string $type): array
    {
        return [
            'purl' => 'pkg:composer/'.$name.'@'.rawurlencode($version),
            'name' => $name,
            'version' => $version,
            'scope' => 'runtime',
            'relationship' => 'direct',
            // No requestedConstraint: nothing on a WordPress site requests a
            // version range, and a sent null would claim we checked.
            'type' => $type,
        ];
    }

    /**
     * One entry per purl, in byte order, as the digest requires (§5, §6).
     *
     * Two entries can share a purl only when two local labels collapse to the
     * same name at the same version. Every entry here is runtime and direct,
     * so there is nothing to merge but the type: the first one in byte order
     * of its source wins, deterministically.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function deduplicate(array $entries): array
    {
        $byPurl = [];

        foreach ($entries as $entry) {
            $byPurl[$entry['purl']] = $byPurl[$entry['purl']] ?? $entry;
        }

        ksort($byPurl, SORT_STRING);

        return array_values($byPurl);
    }

    /**
     * Whether the Composer client already reports this directory.
     */
    private function composerClientReportsHere(string $projectRoot): bool
    {
        $installed = rtrim($projectRoot, '/\\').'/vendor/composer/installed.json';

        if (!is_file($installed) || !is_readable($installed)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($installed), true);

        if (!is_array($decoded)) {
            return false;
        }

        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 did not.
        $packages = isset($decoded['packages']) && is_array($decoded['packages']) ? $decoded['packages'] : $decoded;

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'depman/client') {
                return true;
            }
        }

        return false;
    }
}
