@props(['user'])
<x-layouts.settings title="Profile">
    <h1 class="sr-only">Profile settings</h1>
    <div class="flex flex-col space-y-6">
        <header>
            <h2 class="mb-0.5 text-base font-medium">Profile</h2>
            <p class="text-sm text-muted-foreground">Update your name and email address</p>
        </header>
        <x-island name="settings/ProfileForm" :props="['name' => $user->name, 'email' => $user->email, 'emailVerified' => (bool) $user->email_verified]" />
    </div>
    <x-island name="DeleteUser" />
</x-layouts.settings>
