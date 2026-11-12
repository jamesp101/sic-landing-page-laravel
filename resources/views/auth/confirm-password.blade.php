@props(['redirect'])
<x-layouts.auth title="Confirm password" description="This is a secure area of the application. Please confirm your password before continuing.">
    <x-island name="auth/ConfirmPasswordForm" :props="['redirect' => $redirect]" />
</x-layouts.auth>
