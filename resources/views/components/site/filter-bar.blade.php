{{-- GET filter form. `hidden`: extra query params preserved on submit (e.g. type, directory); `sort`: provide to show the sort control. --}}
@props([
    'action',
    'search' => '',
    'searchLabel' => 'Search',
    'searchPlaceholder' => 'Search…',
    'departmentId' => null,
    'departments',
    'sort' => null,
    'hidden' => [],
    'active' => false,
    'clearHref',
])
@php
    // Nothing navigates while typing or picking an option: the form is submitted with Enter or Apply.
    $label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wider text-muted-foreground';
    $control = \App\Site\Classes::FIELD;
@endphp
<form method="get" action="{{ $action }}" role="search" aria-label="{{ $searchLabel }}" class="flex flex-col gap-4 rounded-2xl border border-border bg-surface p-4 shadow-brand-sm md:flex-row md:items-end">
    @foreach ($hidden as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
    @endforeach

    <div class="flex-1">
        <label for="filter-search" class="{{ $label }}">
            {{ $searchLabel }}
        </label>
        <input id="filter-search" type="search" name="search" value="{{ $search }}" placeholder="{{ $searchPlaceholder }}" autocomplete="off" class="{{ $control }}" />
    </div>

    <div class="md:w-64">
        <label for="filter-department" class="{{ $label }}">
            Department
        </label>
        <select id="filter-department" name="department_id" class="{{ $control }}">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department['id'] }}" @selected($department['id'] === $departmentId)>
                    {{ $department['name'] }}
                </option>
            @endforeach
        </select>
    </div>

    @if ($sort)
        <div class="md:w-44">
            <label for="filter-sort" class="{{ $label }}">
                Sort by
            </label>
            <select id="filter-sort" name="sort" class="{{ $control }}">
                <option value="newest" @selected($sort === 'newest')>
                    Newest first
                </option>
                <option value="oldest" @selected($sort === 'oldest')>
                    Oldest first
                </option>
            </select>
        </div>
    @endif

    <div class="flex items-center gap-3">
        <button type="submit" class="{{ \App\Site\Classes::BTN_PRIMARY }}">
            Apply
        </button>
        @if ($active)
            <a href="{{ $clearHref }}" class="{{ \App\Site\Classes::TEXT_LINK }}">
                Clear filters
            </a>
        @endif
    </div>
</form>
