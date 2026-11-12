<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Files;
use App\Support\Format;
use App\Support\Html;
use App\Support\Initials;
use App\Support\Pagination;
use App\Support\Site;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function test_html_escape_covers_the_five_characters_with_the_ts_entities(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;T&amp;C&#39;s&lt;/a&gt;', Html::escape('<a href="x">T&C\'s</a>'));
        $this->assertSame('&amp;amp;', Html::escapeHtml('&amp;'));
        $this->assertSame('é 😀', Html::escape('é 😀'));
    }

    public function test_parse_page_accepts_positive_integers_only(): void
    {
        $this->assertSame(1, Pagination::parsePage(null));
        $this->assertSame(1, Pagination::parsePage(''));
        $this->assertSame(1, Pagination::parsePage('0'));
        $this->assertSame(1, Pagination::parsePage('-2'));
        $this->assertSame(1, Pagination::parsePage('abc'));
        $this->assertSame(1, Pagination::parsePage('1.5'));
        $this->assertSame(1, Pagination::parsePage("3\n"));
        $this->assertSame(1, Pagination::parsePage('1234567'));
        $this->assertSame(3, Pagination::parsePage('3'));
        $this->assertSame(123456, Pagination::parsePage('123456'));
    }

    public function test_site_facts(): void
    {
        $this->assertSame('San Isidro College', Site::SITE['name']);
        $this->assertSame('Malaybalay City', Site::SITE['address']['addressLocality']);
        $this->assertCount(2, Site::SITE['sameAs']);
    }

    public function test_format_accepts_dates_and_falls_back_to_nothing(): void
    {
        $this->assertSame('August 15, 2026', Format::formatDate(new \DateTimeImmutable('2026-08-15 00:00:00', new \DateTimeZone('Asia/Manila'))));
        $this->assertSame('', Format::formatDate(null));
        $this->assertSame('', Format::formatDate('not a date'));
        $this->assertSame('August 15, 2026', Format::formatDateRange(new \DateTimeImmutable('2026-08-15 08:00:00', new \DateTimeZone('Asia/Manila')), null));
    }

    public function test_initials_skip_titles(): void
    {
        $this->assertSame('AT', Initials::getInitials('Dr. Alan Turing'));
        $this->assertSame('', Initials::getInitials(null));
        $this->assertSame('M', Initials::getInitials('Mr.'));
    }

    public function test_file_kinds(): void
    {
        $this->assertSame('pdf', Files::fileKind(Files::extensionOf('Report.PDF')));
        $this->assertSame('other', Files::fileKind('mp4'));
        $this->assertSame('', Files::extensionOf('.hidden'));
    }
}
