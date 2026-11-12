{{-- Props: redirect (safe path), status, email (no-JS POST result), error. The island's slot is the no-JS form. --}}
@props(['redirect', 'status' => '', 'email' => '', 'error' => ''])
<x-layouts.auth title="Log in to your account" description="Enter your email and password below to log in">
    <x-island name="auth/LoginForm" :props="['redirect' => $redirect, 'status' => $status, 'initialEmail' => $email, 'initialError' => $error]">
        <form method="post" action="/login?redirect={{ urlencode($redirect) }}" class="grid gap-6">
            @csrf
            @if ($status)
                <p role="status" class="rounded-md border border-border-strong bg-status-article-bg px-3 py-2 text-sm text-status-article-fg">{{ $status }}</p>
            @endif
            @if ($error)
                <p role="alert" class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">{{ $error }}</p>
            @endif
            <div class="grid gap-2">
                <label for="email" class="text-sm leading-none font-medium">Email address</label>
                <input id="email" type="email" name="email" value="{{ $email }}" required autocomplete="email" placeholder="email@example.com" class="flex h-11 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none" />
            </div>
            <div class="grid gap-2">
                <div class="flex items-center justify-between gap-2">
                    <label for="password" class="text-sm leading-none font-medium">Password</label>
                    <a href="/forgot-password" class="-my-2 inline-block py-2 text-sm text-link underline decoration-border-strong underline-offset-4 hover:text-brand-700">Forgot your password?</a>
                </div>
                <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Password" class="flex h-11 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none" />
            </div>
            <label class="flex min-h-11 items-center gap-3 text-sm font-medium">
                <input type="checkbox" name="remember" class="size-5 rounded border-input accent-primary" />
                <span>Remember me</span>
            </label>
            <button type="submit" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none" data-test="login-button">Log in</button>
        </form>
    </x-island>
</x-layouts.auth>
