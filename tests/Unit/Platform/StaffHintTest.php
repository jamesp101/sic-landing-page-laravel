<?php

namespace Tests\Unit\Platform;

use App\Http\RequestPolicyRules as Rules;
use PHPUnit\Framework\TestCase;

class StaffHintTest extends TestCase
{
    public function test_has_staff_hint_finds_the_cookie_among_others(): void
    {
        $this->assertTrue(Rules::hasStaffHint('theme=dark; sic_staff=1; x=y'));
        $this->assertTrue(Rules::hasStaffHint('sic_staff=1'));
    }

    public function test_has_staff_hint_ignores_absent_or_look_alike_cookies(): void
    {
        $this->assertFalse(Rules::hasStaffHint(''));
        $this->assertFalse(Rules::hasStaffHint('theme=dark'));
        $this->assertFalse(Rules::hasStaffHint('not_sic_staff=1; sic_staff_x=1'));
    }

    private function action(bool $signedIn, bool $hinted, ?string $cacheControl): ?string
    {
        return Rules::staffHintAction(['signedIn' => $signedIn, 'hinted' => $hinted, 'cacheControl' => $cacheControl]);
    }

    public function test_sets_the_hint_for_a_signed_in_person_without_it_only_on_a_no_store_response(): void
    {
        $this->assertSame('set', $this->action(true, false, 'private, no-store'));
        $this->assertNull($this->action(true, false, 'public, max-age=60, s-maxage=60'));
        $this->assertNull($this->action(true, false, null));
    }

    public function test_deletes_the_hint_for_a_signed_out_person_who_has_it_only_on_a_no_store_response(): void
    {
        $this->assertSame('delete', $this->action(false, true, 'no-store'));
        $this->assertNull($this->action(false, true, 'public, max-age=60, s-maxage=60'));
    }

    public function test_does_nothing_when_the_hint_already_matches_the_session(): void
    {
        $this->assertNull($this->action(true, true, 'private, no-store'));
        $this->assertNull($this->action(false, false, 'private, no-store'));
    }
}
