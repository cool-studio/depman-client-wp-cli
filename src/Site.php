<?php

namespace Depman\WpCli;

/**
 * What is installed on one WordPress site, read once and held as plain data.
 *
 * Everything the resolver decides is decided from this, never from a live
 * WordPress call, so every identity rule is testable with an array and no
 * WordPress anywhere. WordPressSite is the only thing that fills it from a
 * running site.
 *
 * The shapes are WordPress's own, deliberately unreshaped: `plugins` is what
 * get_plugins() returns, keyed by plugin file; `pluginUpdates` is the
 * update_plugins transient's `response` and `no_update` merged, keyed the same
 * way; and so on. Reshaping at the boundary is where a field gets dropped on
 * the way in.
 */
final class Site
{
    /** @var string|null WordPress core's own version, as get_bloginfo('version') reports it. */
    public ?string $coreVersion;

    /** @var array<string, array<string, mixed>> plugin file => get_plugins() headers */
    public array $plugins;

    /** @var array<string, mixed> plugin file => update_plugins transient entry (object or array) */
    public array $pluginUpdates;

    /** @var array<string, array<string, mixed>> stylesheet => theme headers (Name, Version, UpdateURI) */
    public array $themes;

    /** @var array<string, mixed> stylesheet => update_themes transient entry */
    public array $themeUpdates;

    /** @var array<string, array<string, mixed>> file => get_mu_plugins() headers */
    public array $muPlugins;

    /** @var array<string, array<string, mixed>> file => get_dropins() headers */
    public array $dropins;

    /**
     * @param  array<string, array<string, mixed>>  $plugins
     * @param  array<string, mixed>  $pluginUpdates
     * @param  array<string, array<string, mixed>>  $themes
     * @param  array<string, mixed>  $themeUpdates
     * @param  array<string, array<string, mixed>>  $muPlugins
     * @param  array<string, array<string, mixed>>  $dropins
     */
    public function __construct(
        ?string $coreVersion,
        array $plugins = [],
        array $pluginUpdates = [],
        array $themes = [],
        array $themeUpdates = [],
        array $muPlugins = [],
        array $dropins = []
    ) {
        $this->coreVersion = $coreVersion;
        $this->plugins = $plugins;
        $this->pluginUpdates = $pluginUpdates;
        $this->themes = $themes;
        $this->themeUpdates = $themeUpdates;
        $this->muPlugins = $muPlugins;
        $this->dropins = $dropins;
    }
}
