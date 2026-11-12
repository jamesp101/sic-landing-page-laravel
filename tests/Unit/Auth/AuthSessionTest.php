<?php

namespace Tests\Unit\Auth;

use App\Auth\AuthSession;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AuthSessionTest extends TestCase
{
    public function test_it_answers_429_when_the_sign_in_is_rate_limited(): void
    {
        $failure = AuthSession::signInFailure(429);
        $this->assertSame(429, $failure['status']);
        $this->assertStringContainsString('Too many attempts', $failure['message']);
    }

    public function test_it_answers_422_with_one_message_for_any_other_failure(): void
    {
        foreach ([400, 401, 403, 500] as $status) {
            $this->assertSame(
                ['status' => 422, 'message' => 'These credentials do not match our records.'],
                AuthSession::signInFailure($status),
            );
        }
    }

    public function test_there_is_no_current_session_id_without_a_session(): void
    {
        $this->assertNull(AuthSession::currentSessionId(Request::create('/')));
    }
}
