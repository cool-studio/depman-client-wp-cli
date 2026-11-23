<?php

namespace Depman\WpCli\Tests;

use Depman\WpCli\Site;
use Depman\WpCli\SiteReader;

/**
 * A site that is whatever the test says it is.
 *
 * Null means "no WordPress here", which the client treats as nothing to report.
 */
final class FixedSiteReader implements SiteReader
{
    /** @var Site|null */
    private $site;

    public function __construct(?Site $site)
    {
        $this->site = $site;
    }

    public function read(): ?Site
    {
        return $this->site;
    }
}
