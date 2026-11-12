@php
    use App\Admin\Ui;
    use App\Storage\Storage;
    use App\Support\Initials;

    $rows = $list->rows;
    $columns = [
        ['name', 'Department', ''],
        ['members', 'Members', 'text-right'],
        ['posts', 'Posts', 'text-right'],
        ['downloads', 'Downloads', 'text-right'],
        ['updated', 'Updated', ''],
    ];
    $filterControls = [
        ['name' => 'logo', 'label' => 'Logo', 'yes' => 'With logo', 'no' => 'Without logo'],
        ['name' => 'members', 'label' => 'Org chart', 'yes' => 'With members', 'no' => 'No members'],
        ['name' => 'posts', 'label' => 'Posts', 'yes' => 'With posts', 'no' => 'No posts'],
    ];
@endphp
<x-layouts.app title="Departments" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Departments']]">
    <div class="{{ Ui::WIDE_PAGE }}">
        <x-admin.page-header
            title="Departments"
            description="Edit department descriptions, hero sections and logos, and manage organizational charts."
        >
            @if ($superadmin)
                <x-admin.add-link href="/admin/departments/create">New department</x-admin.add-link>
            @endif
        </x-admin.page-header>

        <x-admin.list-filter-bar :list="$list" placeholder="Search name, slug or description…" label="Search departments">
            @foreach ($filterControls as $f)
                <select name="{{ $f['name'] }}" aria-label="Filter by {{ strtolower($f['label']) }}" class="{{ Ui::SELECT }}">
                    <option value="">Any {{ strtolower($f['label']) }}</option>
                    <option value="yes" @selected($list->values[$f['name']] === true)>{{ $f['yes'] }}</option>
                    <option value="no" @selected($list->values[$f['name']] === false)>{{ $f['no'] }}</option>
                </select>
            @endforeach
        </x-admin.list-filter-bar>

        <div class="{{ Ui::CARD }} overflow-hidden">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Departments table">
                <table class="w-full min-w-3xl border-collapse text-left">
                    <caption class="sr-only">Departments, sorted by {{ $list->sort }} {{ $list->dir === 'asc' ? 'ascending' : 'descending' }}</caption>
                    <thead>
                        <tr class="border-b border-border bg-surface-alt">
                            @foreach ($columns as [$field, $label, $align])
                                <x-admin.sortable-th :list="$list" :field="$field" :class="$align.' '.($field === 'name' ? '' : 'px-4! whitespace-nowrap')">{{ $label }}</x-admin.sortable-th>
                            @endforeach
                            <th scope="col" class="{{ Ui::TH }} px-4! text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-sm">
                        @foreach ($rows as $dept)
                            @php $logo = Storage::publicUrl($dept->logo_path); @endphp
                            <tr class="hover:bg-surface-alt">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-surface-alt">
                                            @if ($logo)
                                                <img src="{{ $logo }}" alt="" loading="lazy" class="h-full w-full object-contain p-0.5" />
                                            @else
                                                <span class="text-xs font-bold text-brand-800 dark:text-gold-400">{{ Initials::getInitials($dept->name) }}</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <a
                                                href="/departments/{{ $dept->slug }}"
                                                class="block max-w-64 truncate font-semibold text-foreground hover:text-link"
                                                title="{{ $dept->name }} · View public page"
                                            >{{ $dept->name }}</a>
                                            <span
                                                class="mt-0.5 block max-w-64 truncate text-xs text-muted-foreground"
                                                @if ($dept->description) title="{{ $dept->description }}" @endif
                                            >/{{ $dept->slug }}@if ($dept->description) · {{ $dept->description }}@endif</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-foreground">{{ $dept->members_count }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-foreground">{{ $dept->posts_count }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-foreground">{{ $dept->downloads_count }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">{{ Ui::formatDate($dept->updated_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a
                                            href="/admin/departments/{{ $dept->id }}/edit"
                                            class="{{ Ui::ICON_LINK }}"
                                            aria-label="Edit {{ $dept->name }}"
                                            title="Edit info"
                                        >
                                            <x-admin.icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        <a
                                            href="/admin/departments/{{ $dept->id }}/members"
                                            class="{{ Ui::ICON_LINK }}"
                                            aria-label="Org chart of {{ $dept->name }}"
                                            title="Org chart"
                                        >
                                            <x-admin.icon name="network" class="h-4 w-4" />
                                        </a>
                                        @if ($superadmin)
                                            <x-admin.delete-row-button kind="department" :id="$dept->id" :name="$dept->name" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if (count($rows) === 0)
                            <x-admin.empty-row
                                :colspan="count($columns) + 1"
                                noun="departments"
                                :filtered="$list->filtered"
                                :createHref="$superadmin ? '/admin/departments/create' : null"
                                createLabel="New department"
                            />
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        <x-admin.list-pagination :list="$list" />
    </div>
    @if ($superadmin)
        <x-island name="admin/shared/RowDeleteDialog" />
    @endif
</x-layouts.app>
