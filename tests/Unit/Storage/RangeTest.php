<?php

namespace Tests\Unit\Storage;

use App\Storage\Range;
use PHPUnit\Framework\TestCase;

class RangeTest extends TestCase
{
    public function test_it_reads_a_single_bytes_range(): void
    {
        $this->assertSame(['start' => 0, 'end' => 99], Range::parseRangeHeader('bytes=0-99'));
        $this->assertSame(['start' => 100], Range::parseRangeHeader('bytes=100-'));
        $this->assertSame(['suffix' => 500], Range::parseRangeHeader('bytes=-500'));
        $this->assertSame(['start' => 7, 'end' => 7], Range::parseRangeHeader(' bytes=7-7 '));
    }

    public function test_it_ignores_what_it_does_not_serve_as_a_range(): void
    {
        foreach ([null, '', 'bytes=', 'bytes=-', 'bytes=5-2', 'bytes=0-1,5-6', 'bytes=0-'.str_repeat('9', 16),
            'bytes='.str_repeat('9', 16).'-', 'bytes=-'.str_repeat('9', 16), 'items=0-1', 'bytes=a-b'] as $header) {
            $this->assertNull(Range::parseRangeHeader($header), (string) $header);
        }
    }

    public function test_resolve_clamps_the_end_to_the_object(): void
    {
        $this->assertSame(['start' => 0, 'end' => 99], Range::resolveRange(['start' => 0, 'end' => 99], 1000));
        $this->assertSame(['start' => 900, 'end' => 999], Range::resolveRange(['start' => 900, 'end' => 5000], 1000));
        $this->assertSame(['start' => 900, 'end' => 999], Range::resolveRange(['start' => 900], 1000));
        $this->assertSame(['start' => 900, 'end' => 999], Range::resolveRange(['suffix' => 100], 1000));
        $this->assertSame(['start' => 0, 'end' => 999], Range::resolveRange(['suffix' => 5000], 1000));
    }

    public function test_resolve_returns_null_when_no_byte_is_selected(): void
    {
        $this->assertNull(Range::resolveRange(['start' => 1000], 1000));
        $this->assertNull(Range::resolveRange(['start' => 0, 'end' => 5], 0));
        $this->assertNull(Range::resolveRange(['suffix' => 0], 1000));
    }

    public function test_it_round_trips_into_the_header_s3_expects(): void
    {
        $this->assertSame('bytes=0-99', Range::rangeHeaderValue(['start' => 0, 'end' => 99]));
        $this->assertSame('bytes=5-', Range::rangeHeaderValue(['start' => 5]));
        $this->assertSame('bytes=-9', Range::rangeHeaderValue(['suffix' => 9]));
    }
}
