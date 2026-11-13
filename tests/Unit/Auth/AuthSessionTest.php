<?php

namespace Tests\Unit\Auth;

use App\Auth\AuthSession;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AuthSessionTest extends TestCase
{
    public function test_there_is_no_current_session_id_without_a_session(): void
    {
        $this->assertNull(AuthSession::currentSessionId(Request::create('/')));
    }
}
