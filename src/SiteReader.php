<?php

namespace Depman\WpCli;

/**
 * Something that can say what is installed on a WordPress site.
 *
 * WordPressSite reads a live one; tests hand the resolver a fixed Site.
 */
interface SiteReader
{
    /**
     * The installed state, or null when there is no WordPress to read.
     */
    public function read(): ?Site;
}
