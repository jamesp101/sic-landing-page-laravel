{{--
    Search form of a list. Props: `list` (App\Admin\ListState), placeholder, label (accessible name of the input),
    submitLabel. The page's own filter controls go in the default slot.
--}}
@props(['list', 'placeholder', 'label', 'submitLabel' => 'Filter'])
<form method="get" action="{{ explode('?', $list->resetHref())[0] }}" class="{{ \App\Admin\Ui::CARD }} flex flex-col items-center justify-between gap-4 p-4 md:flex-row">
    <div class="relative w-full md:max-w-xs">
        <x-admin.icon name="search" class="absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <input
            type="search"
            name="q"
            value="{{ $list->q }}"
            placeholder="{{ $placeholder }}"
            aria-label="{{ $label }}"
            class="{{ \App\Admin\Ui::SEARCH_INPUT }}"
        />
    </div>
    <div class="flex w-full flex-col items-stretch gap-3 sm:flex-row sm:items-center md:w-auto">
        {{ $slot }}
        <input type="hidden" name="sort" value="{{ $list->sort }}" />
        <input type="hidden" name="dir" value="{{ $list->dir }}" />
        <button type="submit" class="{{ \App\Admin\Ui::PRIMARY_BUTTON }}">{{ $submitLabel }}</button>
        @if ($list->filtered)
            <a href="{{ $list->resetHref() }}" class="{{ \App\Admin\Ui::SECONDARY_BUTTON }}">Reset</a>
        @endif
    </div>
</form>
