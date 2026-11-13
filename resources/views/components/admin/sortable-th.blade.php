{{-- Sortable column header. Props: `list` (App\Admin\ListState), `field`; slot = label; extra classes via `class`. --}}
@props(['list', 'field'])
@php
    $sorted = $list->ariaSort($field);
    $icon = $sorted === 'ascending' ? 'chevron-up' : ($sorted === 'descending' ? 'chevron-down' : 'arrow-up-down');
@endphp
<th scope="col" class="{{ \App\Admin\Ui::TH }} {{ $attributes->get('class') }}" @if ($sorted) aria-sort="{{ $sorted }}" @endif>
    <a href="{{ $list->sortHref($field) }}" class="inline-flex items-center gap-1 hover:text-link {{ $sorted ? 'text-foreground' : '' }}">
        {{ $slot }} <x-icon :name="$icon" class="h-3.5 w-3.5" />
    </a>
</th>
