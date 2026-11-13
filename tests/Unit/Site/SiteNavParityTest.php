<?php

namespace Tests\Unit\Site;

use App\Site\SiteNav;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `fixtures/site-nav.json` is read by `resources/js/lib/parity/site-nav.parity.test.ts` too: one list of Navigation link / Department inputs and their header and footer items. */
final class SiteNavParityTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/site-nav.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $named = [];
        foreach (self::fixture()['cases'] as $case) {
            $named[$case['name']] = [$case];
        }

        return $named;
    }

    #[DataProvider('cases')]
    public function test_header_and_footer_items_match_the_fixture(array $case): void
    {
        $this->assertSame($case['header'], SiteNav::headerItems($case['navLinks'], $case['departments']));
        $this->assertSame($case['footer'], SiteNav::footerItems($case['departments']));
    }

    public function test_default_menu_and_hrefs_match_the_fixture(): void
    {
        $fixture = self::fixture();
        $this->assertSame($fixture['defaultNavLinks'], SiteNav::DEFAULT_NAV_LINKS);
        foreach ($fixture['links']['departmentHref'] as [$slug, $href]) {
            $this->assertSame($href, SiteNav::departmentHref($slug));
        }
        foreach ($fixture['links']['postHref'] as [$slug, $href]) {
            $this->assertSame($href, SiteNav::postHref($slug));
        }
    }
}
