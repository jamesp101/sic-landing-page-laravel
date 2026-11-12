{{-- Props: token, email (both null/empty when the link is incomplete), invalid. --}}
@props(['token' => null, 'email' => '', 'invalid' => false])
<x-layouts.auth title="Reset password" description="Please enter your new password below">
    @if ($invalid)
        <div class="space-y-6 text-center">
            <p role="alert" class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                This password reset link is invalid or has expired.
            </p>
            <a href="/forgot-password" class="text-sm text-link underline decoration-border-strong underline-offset-4 hover:text-brand-700">
                Request a new link
            </a>
        </div>
    @else
        <x-island name="auth/ResetPasswordForm" :props="['token' => $token, 'email' => $email]" />
    @endif
</x-layouts.auth>
