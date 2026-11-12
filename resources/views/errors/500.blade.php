@php
    $seo = [
        'title' => 'Something went wrong',
        'description' => 'An unexpected error occurred on our side.',
        'noindex' => true,
    ];
@endphp
<x-layouts.public :seo="$seo" :minimal="true">
    <x-site.error-panel
        code="500"
        title="Something went wrong"
        message="An unexpected error occurred on our side. Please try again in a moment."
    >
        <button type="button" data-reload class="{{ \App\Site\Classes::BTN_PRIMARY }} mt-8">
            Try again
        </button>
    </x-site.error-panel>
</x-layouts.public>
<script nonce="{{ Vite::cspNonce() }}">
    document.querySelector('[data-reload]')?.addEventListener('click', () => location.reload());
</script>
