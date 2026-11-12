{{-- Renders `Downloads::getDownloadsPage()` (downloads, groups, folders, selected, allHref, totalCount). --}}
@props(['downloads', 'groups', 'folders', 'selected', 'allHref', 'totalCount'])
@php
    $folderLink = 'flex min-h-11 items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm transition duration-200 ease-out hover:border-gold-500 hover:shadow-brand-sm';
    $folderOn = 'border-gold-500 bg-surface font-semibold shadow-brand-sm';
    $folderOff = 'border-border bg-surface';
@endphp
<div class="space-y-10">
    @if (count($folders) > 0)
        <nav aria-label="Folders">
            <h2 class="mb-3 font-sans text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                Folders
            </h2>
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <li>
                    <a href="{{ $allHref }}" @if ($selected === '') aria-current="page" @endif @class([$folderLink, $selected === '' ? $folderOn : $folderOff])>
                        <span>All files</span>
                        <span class="text-xs text-muted-foreground">{{ $totalCount }}</span>
                    </a>
                </li>
                @foreach ($folders as $folder)
                    <li>
                        <a href="{{ $folder['href'] }}" @if ($selected === $folder['name']) aria-current="page" @endif @class([$folderLink, $selected === $folder['name'] ? $folderOn : $folderOff])>
                            <span class="truncate">{{ $folder['name'] }}</span>
                            <span class="text-xs text-muted-foreground">{{ $folder['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    @if (count($downloads) === 0)
        <x-site.empty-state title="No files found" description="No files match your search, department or folder." actionHref="/downloads" actionLabel="Show all files" />
    @else
        @foreach ($groups as $index => $group)
            <section aria-labelledby="folder-{{ $index }}">
                <h2 id="folder-{{ $index }}" class="mb-4 text-2xl font-bold text-brand-800 dark:text-foreground">
                    {{ $group['name'] }} <span class="font-sans text-base font-normal text-muted-foreground">({{ count($group['files']) }})</span>
                </h2>
                <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($group['files'] as $file)
                        <li class="flex flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-brand-sm transition duration-200 ease-out hover:border-border-strong hover:shadow-brand-md">
                            <div class="flex items-start gap-3">
                                <x-site.file-kind-badge :kind="$file['kind']" :extension="$file['extension']" />
                                <div class="min-w-0 flex-1">
                                    <h3 class="break-words font-sans text-sm font-semibold text-foreground">
                                        {{ $file['fileName'] }}
                                    </h3>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        {{ \App\Support\Format::formatFileSize($file['size']) }} ·
                                        <time datetime="{{ \App\Site\Seo::iso($file['createdAt']) }}">
                                            {{ \App\Support\Format::formatDate($file['createdAt'], ['short' => true]) }}
                                        </time>
                                    </p>
                                </div>
                            </div>
                            @if ($file['department'])
                                <p class="mt-3 text-xs text-muted-foreground">
                                    Department:
                                    <span class="font-semibold text-foreground">{{ $file['department']['name'] }}</span>
                                </p>
                            @endif
                            @if (count($file['tags']) > 0)
                                <ul class="mt-3 flex flex-wrap gap-2" aria-label="Tags">
                                    @foreach ($file['tags'] as $tag)
                                        <li>
                                            <a href="{{ \App\Site\PostFilters::downloadsHref(['search' => $tag]) }}" class="inline-flex min-h-8 items-center rounded-md bg-surface-alt px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors duration-200 hover:text-link">
                                                {{ $tag }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="mt-5 flex items-center justify-end gap-3 border-t border-border pt-4">
                                <a href="{{ $file['url'] }}" target="_blank" rel="noopener" class="{{ \App\Site\Classes::BTN_OUTLINE_COMPACT }}">
                                    View<span class="sr-only"> {{ $file['fileName'] }} (opens in new tab)</span>
                                </a>
                                <a href="{{ $file['downloadUrl'] }}" download="{{ $file['fileName'] }}" class="{{ \App\Site\Classes::BTN_PRIMARY_COMPACT }}">
                                    Download<span class="sr-only"> {{ $file['fileName'] }}</span>
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
