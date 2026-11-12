<?php

namespace Tests\Unit\Auth;

use App\Auth\PasswordPolicy;
use App\Auth\SafeRedirect;
use PHPUnit\Framework\TestCase;

class SafeRedirectTest extends TestCase
{
    public function test_it_accepts_only_same_origin_relative_paths(): void
    {
        $this->assertSame('/admin/posts?page=2', SafeRedirect::safeRedirect('/admin/posts?page=2'));
        foreach ([null, '', 'admin', 'https://evil.test', '//evil.test', '/\\evil.test', "/a\nb", "/a\x00b", 'javascript:alert(1)'] as $bad) {
            $this->assertSame('/dashboard', SafeRedirect::safeRedirect($bad), var_export($bad, true));
        }
        $this->assertSame('/x', SafeRedirect::safeRedirect('nope', '/x'));
    }

    public function test_the_password_policy_wording(): void
    {
        $this->assertSame(8, PasswordPolicy::MIN_PASSWORD_LENGTH);
        $this->assertSame(12, PasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH);
        $this->assertSame('The password must be at least 8 characters.', PasswordPolicy::passwordTooShort());
        $this->assertSame('The password must be at least 12 characters.', PasswordPolicy::passwordTooShort(12));
        $this->assertSame('Minimum 8 characters.', PasswordPolicy::passwordMinHint());
    }
}
