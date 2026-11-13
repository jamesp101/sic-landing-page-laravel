@php
    use App\Admin\Ui;

    $typeClass = [
        'article' => 'bg-status-article-bg text-status-article-fg',
        'event' => 'bg-status-event-bg text-status-event-fg',
        'announcement' => 'bg-status-announcement-bg text-status-announcement-fg',
    ];
    $typeIcon = ['article' => 'file-text', 'event' => 'calendar', 'announcement' => 'megaphone'];
    $columns = [
        ['title', 'Post'],
        ['type', 'Type'],
        ...($list->showDepartment ? [['department', 'Department']] : []),
        ['featured', 'Featured'],
    ];
@endphp
<x-layouts.app title="Posts" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard'], ['title' => 'Posts']]">
    <div class="{{ Ui::WIDE_PAGE }}">
        <x-admin.page-header
            :scoped="true"
            title="Posts"
            description="Create, edit or delete articles, announcements and campus events."
        >
            <x-admin.add-link href="/admin/posts/create">New post</x-admin.add-link>
        </x-admin.page-header>

        <x-admin.list-filter-bar :list="$list" placeholder="Search posts…" label="Search posts">
            <select name="type" aria-label="Filter by type" class="{{ Ui::SELECT }}">
                <option value="">All types</option>
                @foreach ($postTypes as $t)
                    <option value="{{ $t }}" @selected($t === $list->values['type'])>{{ ucfirst($t) }}</option>
                @endforeach
            </select>
            <select name="featured" aria-label="Filter by featured" class="{{ Ui::SELECT }}">
                <option value="">All featured</option>
                <option value="yes" @selected($list->values['featured'] === true)>Featured only</option>
                <option value="no" @selected($list->values['featured'] === false)>Not featured</option>
            </select>
        </x-admin.list-filter-bar>

        <div class="{{ Ui::CARD }} overflow-hidden">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Posts table">
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
                        @foreach ($posts as $post)
                            @php
                                $image = $imageUrls[$post->id] ?? null;
                                $icon = $typeIcon[$post->type] ?? 'file-text';
                                $author = $post->user?->name ?? 'System';
                                $created = Ui::formatDate($post->created_at);
                            @endphp
                            <tr class="hover:bg-surface-alt">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($image)
                                            <img
                                                src="{{ $image }}"
                                                alt=""
                                                loading="lazy"
                                                class="h-10 w-16 shrink-0 rounded-md border border-border object-cover"
                                            />
                                        @else
                                            <div class="flex h-10 w-16 shrink-0 items-center justify-center rounded-md {{ $typeClass[$post->type] ?? 'bg-secondary text-secondary-foreground' }}">
                                                <x-admin.icon :name="$icon" class="h-5 w-5" />
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <span class="block max-w-60 truncate font-semibold text-foreground" title="{{ $post->title }}">
                                                {{ $post->title }}
                                            </span>
                                            <span
                                                class="mt-0.5 block max-w-60 truncate text-xs text-muted-foreground"
                                                title="Created {{ $created }} by {{ $author }}"
                                            >
                                                {{ $created }} · {{ $author }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="{{ Ui::PILL }} capitalize {{ $typeClass[$post->type] ?? '' }}">{{ $post->type }}</span>
                                </td>
                                @if ($list->showDepartment)
                                    <td class="px-4 py-3 text-muted-foreground">
                                        <span class="block max-w-40 truncate" title="{{ $post->department?->name ?? 'General (all departments)' }}">
                                            {{ $post->department?->name ?? 'General (all departments)' }}
                                        </span>
                                    </td>
                                @endif
                                <td class="px-4 py-3">
                                    @if ($post->is_featured)
                                        <span class="{{ Ui::PILL }} bg-file-archive-bg text-file-archive-fg">
                                            <x-admin.icon name="star" class="h-3 w-3 fill-current" /> Featured
                                        </span>
                                    @else
                                        <span class="text-muted-foreground" aria-label="Not featured">–</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a
                                            href="/admin/posts/{{ $post->id }}/edit"
                                            class="{{ Ui::ICON_LINK }}"
                                            aria-label="Edit post {{ $post->title }}"
                                            title="Edit post"
                                        >
                                            <x-admin.icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        <x-admin.delete-row-button kind="post" :id="$post->id" :name="$post->title" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if ($posts->isEmpty())
                            <x-admin.empty-row
                                :colspan="count($columns) + 1"
                                noun="posts"
                                :filtered="$list->filtered"
                                createHref="/admin/posts/create"
                                createLabel="New post"
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
