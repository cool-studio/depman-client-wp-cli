<?php

namespace Depman\WpCli;

/**
 * Reads the installed state of the WordPress site WP-CLI has loaded.
 *
 * The only class in the package that calls WordPress, and it does nothing but
 * copy WordPress's own answers into a Site. Every decision about what those
 * answers mean is the Resolver's, where it can be tested without WordPress.
 *
 * It reads the update transients and never refreshes them. Refreshing would
 * send the site's plugin list to api.wordpress.org from inside our command,
 * which is WordPress's decision to make on its own schedule and not ours; a
 * plugin WordPress has not yet asked about is reported as inventory until it
 * has, which is the honest answer in the meantime.
 */
final class WordPressSite implements SiteReader
{
    public function read(): ?Site
    {
        if (!defined('ABSPATH') || !function_exists('get_bloginfo')) {
            return null;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $version = (string) get_bloginfo('version');

        return new Site(
            $version !== '' ? $version : null,
            get_plugins(),
            $this->transientEntries('update_plugins'),
            $this->themes(),
            $this->transientEntries('update_themes'),
            get_mu_plugins(),
            get_dropins()
        );
    }

    /**
     * Every theme WordPress can load, with the headers the Resolver reads.
     *
     * @return array<string, array<string, mixed>>
     */
    private function themes(): array
    {
        $themes = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[(string) $stylesheet] = [
                'Name' => $this->text($theme->get('Name')),
                'Version' => $this->text($theme->get('Version')),
                // Not a header every WordPress version registers for themes;
                // get() answers false for an unknown one, which reads as unset.
                'UpdateURI' => $this->text($theme->get('UpdateURI')),
            ];
        }

        return $themes;
    }

    /**
     * The transient's `response` and `no_update` entries, keyed as WordPress
     * keys them -- plugin file or theme stylesheet.
     *
     * Both lists matter: `response` holds what has an update waiting,
     * `no_update` what wordpress.org recognised and found current. Reading
     * only the first would leave every up-to-date plugin unidentified.
     *
     * @return array<string, mixed>
     */
    private function transientEntries(string $name): array
    {
        $transient = get_site_transient($name);

        if (!is_object($transient)) {
            return [];
        }

        $entries = [];

        foreach (['no_update', 'response'] as $list) {
            $items = $transient->{$list} ?? [];

            if (is_array($items)) {
                foreach ($items as $key => $entry) {
                    $entries[(string) $key] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param  mixed  $value
     */
    private function text($value): string
    {
        return is_string($value) ? $value : '';
    }
}
