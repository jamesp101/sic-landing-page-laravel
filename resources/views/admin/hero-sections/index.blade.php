@php
    use App\Admin\Ui;

    $columns = [
        ['title', 'Section'],
        ...($list->showDepartment ? [['department', 'Page location']] : []),
        ['order', 'Order'],
        ['active', 'Status'],
    ];
@endphp
<x-layouts.app title="Hero sections" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Hero sections']]">
    <div class="{{ Ui::WIDE_PAGE }}">
        <x-admin.page-header
            :scoped="true"
            title="Hero sections"
            description="Rich content panels for the Main site and department pages."
        >
            <x-admin.add-link href="/admin/hero-sections/create">New hero section</x-admin.add-link>
        </x-admin.page-header>

        <x-admin.list-filter-bar :list="$list" placeholder="Search content…" label="Search hero sections" />

        <div class="{{ Ui::CARD }} overflow-hidden">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Hero sections table">
                <table class="w-full min-w-3xl border-collapse text-left">
                    <thead>
                        <tr class="border-b border-border bg-surface-alt">
                            @foreach ($columns as [$field, $label])
                                <x-admin.sortable-th :list="$list" :field="$field">{{ $label }}</x-admin.sortable-th>
                            @endforeach
                            <th scope="col" class="{{ Ui::TH }} text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-sm">
                        @foreach ($sections as $hs)
                            @php $searchText = (string) $hs->search_text; @endphp
                            <tr class="hover:bg-surface-alt">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gold-500/10 text-gold-600">
                                            <x-icon name="sparkles" class="h-4 w-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <span
                                                class="block max-w-xs truncate font-semibold text-foreground"
                                                @if ($hs->title) title="{{ $hs->title }}" @endif
                                            >
                                                {{ $hs->title === null || $hs->title === '' ? 'Untitled section' : $hs->title }}
                                            </span>
                                            <span
                                                class="mt-0.5 block max-w-xs truncate text-xs text-muted-foreground"
                                                @if ($searchText !== '') title="{{ $searchText }}" @endif
                                            >
                                                {{ mb_substr($searchText, 0, 75) }}
                                                {{ mb_strlen($searchText) > 75 ? '…' : '' }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                @if ($list->showDepartment)
                                    <td class="px-4 py-3">
                                        @if ($hs->department)
                                            <span
                                                class="{{ Ui::PILL }} max-w-48 truncate bg-secondary text-secondary-foreground"
                                                title="{{ $hs->department->name }}"
                                            >
                                                {{ $hs->department->name }}
                                            </span>
                                        @else
                                            <span class="{{ Ui::PILL }} bg-file-archive-bg text-file-archive-fg">Main site</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-4 py-3 font-mono text-xs text-muted-foreground">#{{ $hs->order }}</td>
                                <td class="px-4 py-3">
                                    @if ($hs->is_active)
                                        <span class="{{ Ui::PILL }} bg-status-article-bg text-status-article-fg">
                                            <x-icon name="eye" class="h-3 w-3" /> Active
                                        </span>
                                    @else
                                        <span class="{{ Ui::PILL }} bg-secondary text-muted-foreground">
                                            <x-icon name="eye-off" class="h-3 w-3" /> Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a
                                            href="/admin/hero-sections/{{ $hs->id }}/edit"
                                            class="{{ Ui::ICON_LINK }}"
                                            aria-label="Edit hero section {{ $hs->title ?? $hs->id }}"
                                            title="Edit section"
                                        >
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        <x-admin.delete-row-button kind="heroSection" :id="$hs->id" :name="$hs->title ?? '#'.$hs->id" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if ($sections->isEmpty())
                            <x-admin.empty-row
                                :colspan="count($columns) + 1"
                                noun="hero sections"
                                :filtered="$list->filtered"
                                createHref="/admin/hero-sections/create"
                                createLabel="New hero section"
                            />
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        <x-admin.list-pagination :list="$list" />
    </div>
    <x-island name="admin/shared/RowDeleteDialog" />
</x-layouts.app>
