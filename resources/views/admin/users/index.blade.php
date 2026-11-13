@php
    use App\Admin\Ui;
    $users = $list->rows;
@endphp
<x-layouts.app title="Users" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Users']]">
    <div class="{{ Ui::WIDE_PAGE }}">
        <x-admin.page-header title="Users" description="People who sign in to the admin, with their roles and departments.">
            <x-admin.add-link href="/admin/users/create">New user</x-admin.add-link>
        </x-admin.page-header>

        <x-admin.list-filter-bar :list="$list" placeholder="Search name or email…" label="Search users" submitLabel="Search" />

        <div class="{{ Ui::CARD }} overflow-hidden">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Users table">
                <table class="w-full min-w-3xl border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-border bg-surface-alt">
                            <x-admin.sortable-th :list="$list" field="name">Name</x-admin.sortable-th>
                            <x-admin.sortable-th :list="$list" field="email">Email</x-admin.sortable-th>
                            <x-admin.sortable-th :list="$list" field="role">Role</x-admin.sortable-th>
                            <x-admin.sortable-th :list="$list" field="department">Department</x-admin.sortable-th>
                            <th scope="col" class="{{ Ui::TH }} w-28 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($users as $user)
                            <tr class="hover:bg-surface-alt/60">
                                <td class="px-4 py-3 font-semibold text-foreground">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="max-w-64 truncate" title="{{ $user->name }}">{{ $user->name }}</span>
                                        @if ($user->id === $actor->id)
                                            <span class="text-xs font-normal text-muted-foreground">(you)</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <span class="block max-w-64 truncate" title="{{ $user->email }}">{{ $user->email }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($user->role === 'superadmin')
                                        <span class="{{ Ui::PILL }} bg-status-announcement-bg text-status-announcement-fg">Superadmin</span>
                                    @else
                                        <span class="{{ Ui::PILL }} bg-status-article-bg text-status-article-fg">Department user</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs font-semibold text-link">
                                    <span class="block max-w-56 truncate" @if ($user->department) title="{{ $user->department->name }}" @endif>{{ $user->department?->name ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a
                                            href="/admin/users/{{ $user->id }}/edit"
                                            class="{{ Ui::ICON_LINK }}"
                                            aria-label="Edit {{ $user->name }}"
                                            title="Edit user"
                                        >
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        @if ($user->id !== $actor->id)
                                            <x-admin.delete-row-button kind="user" :id="$user->id" :name="$user->name" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if (count($users) === 0)
                            <x-admin.empty-row
                                :colspan="5"
                                noun="users"
                                :filtered="$list->filtered"
                                createHref="/admin/users/create"
                                createLabel="New user"
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
