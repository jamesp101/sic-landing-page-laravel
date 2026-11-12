<x-layouts.auth title="Forgot password" description="Enter your email to receive a password reset link">
    <div class="space-y-6">
        <x-island name="auth/ForgotPasswordForm" />
        <p class="text-center text-sm text-muted-foreground">
            Or, return to
            <a href="/login" class="text-link underline decoration-border-strong underline-offset-4 hover:text-brand-700">log in</a>
        </p>
    </div>
</x-layouts.auth>
