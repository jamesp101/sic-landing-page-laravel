@php
    $seo = [
        'title' => 'Page not found',
        'description' => 'The page you are looking for does not exist or has moved.',
        'noindex' => true,
        'follow' => true,
    ];
@endphp
<x-layouts.public :seo="$seo">
    <x-site.error-panel
        code="404"
        title="Page not found"
        message="The page you are looking for does not exist or may have moved. Try one of these instead."
    >
        <form method="get" action="/posts" role="search" aria-label="Search posts" class="mt-8 flex w-full max-w-md gap-2">
            <label for="not-found-search" class="sr-only">
                Search news &amp; events
            </label>
            <input id="not-found-search" type="search" name="search" placeholder="Search news &amp; events…" autocomplete="off" class="{{ \App\Site\Classes::FIELD }}" />
            <button type="submit" class="{{ \App\Site\Classes::BTN_PRIMARY }} shrink-0">
                Search
            </button>
        </form>
    </x-site.error-panel>
</x-layouts.public>
