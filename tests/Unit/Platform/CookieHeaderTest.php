<?php

namespace Tests\Unit\Platform;

use App\Http\RequestPolicyRules as Rules;
use PHPUnit\Framework\TestCase;

class CookieHeaderTest extends TestCase
{
    public function test_reads_one_cookie_among_others_decoded(): void
    {
        $this->assertSame('main', Rules::cookieValue('a=1; admin_scope=main; b=2', 'admin_scope'));
        $this->assertSame('a b', Rules::cookieValue('x=a%20b', 'x'));
    }

    public function test_keeps_an_undecodable_value_as_is_and_ignores_look_alikes_and_absent_cookies(): void
    {
        $this->assertSame('%E0%A4%A', Rules::cookieValue('x=%E0%A4%A', 'x'));
        $this->assertSame('%E0%A4%41', Rules::cookieValue('x=%E0%A4%41', 'x'));
        $this->assertNull(Rules::cookieValue('not_x=1; x_y=2', 'x'));
        $this->assertNull(Rules::cookieValue(null, 'x'));
        $this->assertNull(Rules::cookieValue('', 'x'));
    }
}
