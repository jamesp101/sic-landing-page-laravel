<?php

namespace Tests\Unit\Site;

use App\Site\SiteNav;
use PHPUnit\Framework\TestCase;

/** Header, footer and href rules are pinned against the TS twin in `SiteNavParityTest`. */
class SiteNavTest extends TestCase
{
    public function test_sitemap_pages_are_valid_link_targets(): void
    {
        foreach (SiteNav::SITEMAP_PAGES as $path) {
            $this->assertContains($path, SiteNav::STATIC_PATHS);
        }
    }
}
