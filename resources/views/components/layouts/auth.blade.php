{{-- Centred card for sign-in related pages. Always noindex; one <h1> per page. Props: title, description. --}}
@props(['title', 'description'])
<x-layouts.base :seo="['title' => $title, 'description' => $description, 'noindex' => true]" entry="admin" bodyClass="bg-background text-foreground antialiased">
    <main class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8 rounded-2xl border border-border bg-card p-8 text-card-foreground shadow-brand-md">
                <div class="flex flex-col items-center gap-4">
                    <a href="/" class="flex flex-col items-center gap-2 font-medium" aria-label="{{ \App\Support\Site::SITE['name'] }} home">
                        <img src="{{ \App\Support\Site::SITE['logo'] }}" alt="" width="64" height="64" class="size-16" />
                        <span class="font-serif text-lg text-brand-800 dark:text-foreground">{{ \App\Support\Site::SITE['name'] }}</span>
                    </a>
                    <div class="space-y-2 text-center">
                        <p class="flex items-center justify-center gap-2 text-xs font-semibold tracking-widest text-link uppercase">
                            <span class="h-0.5 w-6 bg-gold-500" aria-hidden="true"></span>
                            Staff portal
                        </p>
                        <h1 class="font-serif text-2xl font-semibold text-brand-800 dark:text-foreground">{{ $title }}</h1>
                        <p class="text-sm text-muted-foreground">{{ $description }}</p>
                    </div>
                </div>
                {{ $slot }}
            </div>
        </div>
    </main>
</x-layouts.base>
