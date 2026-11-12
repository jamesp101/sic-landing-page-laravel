@props(['email'])
<x-layouts.auth title="Email verification" description="Please verify your email address by clicking on the link we emailed to you.">
    <x-island name="auth/VerifyEmailForm" :props="['email' => $email]" />
</x-layouts.auth>
