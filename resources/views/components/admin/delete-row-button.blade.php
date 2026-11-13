{{-- Delete trigger for one list row; the page's single `admin/shared/RowDeleteDialog` island handles the click.
     Rendered disabled: the island enables the buttons once it listens, so a click is never silently lost. --}}
@props(['kind', 'id', 'name'])
@php
    $label = match ($kind) {
        'post' => "Delete post {$name}",
        'heroSection' => "Delete hero section {$name}",
        default => "Delete {$name}", // department, user
    };
@endphp
<button
    type="button"
    disabled
    data-row-delete="{{ $kind }}"
    data-id="{{ $id }}"
    data-name="{{ $name }}"
    aria-haspopup="dialog"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    class="{{ \App\Admin\Ui::DANGER_ICON_BUTTON }}"
>
    <x-icon name="trash" class="h-4 w-4" />
</button>
