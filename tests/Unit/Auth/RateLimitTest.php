<?php

namespace Tests\Unit\Auth;

use App\Auth\RateLimit;
use App\Support\RequestError;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    private const RULE = ['max' => 3, 'window' => 60];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(int $seconds): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000 + $seconds));
    }

    public function test_it_allows_exactly_max_hits_within_the_window_and_refuses_the_next(): void
    {
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $this->at($i);
            $results[] = RateLimit::consumeRateLimit('window-basic', self::RULE);
        }
        $this->assertSame([true, true, true, false, false], $results);
    }

    public function test_it_allows_again_once_the_window_has_elapsed(): void
    {
        for ($i = 0; $i < 4; $i++) {
            RateLimit::consumeRateLimit('window-reset', self::RULE);
        }
        $this->at(59);
        $this->assertFalse(RateLimit::consumeRateLimit('window-reset', self::RULE));
        $this->at(60);
        $this->assertTrue(RateLimit::consumeRateLimit('window-reset', self::RULE));
        $this->at(61);
        $this->assertTrue(RateLimit::consumeRateLimit('window-reset', self::RULE));
        $this->at(62);
        $this->assertTrue(RateLimit::consumeRateLimit('window-reset', self::RULE));
        $this->at(63);
        $this->assertFalse(RateLimit::consumeRateLimit('window-reset', self::RULE));
    }

    public function test_it_counts_separate_keys_independently(): void
    {
        for ($i = 0; $i < 4; $i++) {
            RateLimit::consumeRateLimit('key-a', self::RULE);
        }
        $this->assertFalse(RateLimit::consumeRateLimit('key-a', self::RULE));
        $this->assertTrue(RateLimit::consumeRateLimit('key-b', self::RULE));
    }

    public function test_sign_in_allows_10_attempts_per_email_and_address_whatever_the_spelling_and_no_other_address_or_email(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(RateLimit::claimSignInAttempt($i % 2 === 0 ? ' A@SIC.edu.ph ' : 'a@sic.edu.ph', '198.51.100.1'));
        }
        $this->assertFalse(RateLimit::claimSignInAttempt('A@sic.edu.ph', '198.51.100.1'));
        $this->assertTrue(RateLimit::claimSignInAttempt('a@sic.edu.ph', '198.51.100.2'));
        $this->assertTrue(RateLimit::claimSignInAttempt('b@sic.edu.ph', '198.51.100.1'));
    }

    public function test_sign_in_allows_100_attempts_per_email_over_all_addresses_then_refuses_every_address(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue(RateLimit::claimSignInAttempt('c@sic.edu.ph', "192.0.2.{$i}"));
        }
        $this->assertFalse(RateLimit::claimSignInAttempt('c@sic.edu.ph', '192.0.2.200'));
        $this->assertTrue(RateLimit::claimSignInAttempt('d@sic.edu.ph', '192.0.2.200'));
    }

    public function test_an_attempt_refused_by_the_per_email_limit_keeps_no_slot_for_the_address(): void
    {
        for ($i = 0; $i < 100; $i++) {
            RateLimit::claimSignInAttempt('f@sic.edu.ph', "192.0.2.{$i}");
        }
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse(RateLimit::claimSignInAttempt('f@sic.edu.ph', '192.0.2.250'));
        }
        // The 10 per-address slots of 192.0.2.250 are all still free once the email ceiling is out of the way.
        $this->at(15 * 60);
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(RateLimit::claimSignInAttempt('f@sic.edu.ph', '192.0.2.250'));
        }
        $this->assertFalse(RateLimit::claimSignInAttempt('f@sic.edu.ph', '192.0.2.250'));
    }

    public function test_it_gives_the_slots_back_on_success_so_only_failures_count(): void
    {
        for ($i = 0; $i < 9; $i++) {
            RateLimit::claimSignInAttempt('h@sic.edu.ph', '198.51.100.8');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(RateLimit::claimSignInAttempt('h@sic.edu.ph', '198.51.100.8'));
            RateLimit::refundSignInAttempt('h@sic.edu.ph', '198.51.100.8');
        }
        $this->assertTrue(RateLimit::claimSignInAttempt('h@sic.edu.ph', '198.51.100.8'));
        $this->assertFalse(RateLimit::claimSignInAttempt('h@sic.edu.ph', '198.51.100.8'));
    }

    public function test_a_refund_never_goes_below_zero_or_into_a_new_window(): void
    {
        RateLimit::refundSignInAttempt('r@sic.edu.ph', '198.51.100.20');
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(RateLimit::claimSignInAttempt('r@sic.edu.ph', '198.51.100.20'));
        }
        $this->assertFalse(RateLimit::claimSignInAttempt('r@sic.edu.ph', '198.51.100.20'));
    }

    public function test_it_lets_an_address_in_again_15_minutes_after_the_window_began_however_often_it_was_refused(): void
    {
        for ($i = 0; $i < 10; $i++) {
            RateLimit::claimSignInAttempt('e@sic.edu.ph', '198.51.100.9');
        }
        $this->at(14 * 60);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(RateLimit::claimSignInAttempt('e@sic.edu.ph', '198.51.100.9'));
        }
        $this->at(15 * 60);
        $this->assertTrue(RateLimit::claimSignInAttempt('e@sic.edu.ph', '198.51.100.9'));
    }

    public function test_upload_quota_throws_too_many_requests_once_the_user_has_used_it_and_only_for_that_user(): void
    {
        for ($i = 0; $i < RateLimit::RATE_LIMITS['upload']['max']; $i++) {
            RateLimit::assertUploadQuota(101);
        }
        try {
            RateLimit::assertUploadQuota(101);
            $this->fail('Expected TOO_MANY_REQUESTS.');
        } catch (RequestError $error) {
            $this->assertSame(RequestError::TOO_MANY_REQUESTS, $error->errorCode);
        }
        RateLimit::assertUploadQuota(102);
        $this->addToAssertionCount(1);
    }
}
