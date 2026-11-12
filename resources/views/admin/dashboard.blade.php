@php
    use App\Admin\AdminScope;
    use App\Admin\Ui;

    $user = request()->user();
    $stats = [
        ['label' => 'Posts & news', 'value' => $postsCount, 'note' => 'Total articles & announcements', 'icon' => 'file-text'],
        ['label' => 'Downloads', 'value' => $downloadsCount, 'note' => 'Total uploaded public files', 'icon' => 'download'],
        ['label' => 'Departments', 'value' => $departmentsCount, 'note' => 'Colleges & academic divisions', 'icon' => 'school'],
        ['label' => 'Users', 'value' => $usersCount, 'note' => 'People who sign in to the admin', 'icon' => 'users'],
    ];
    $tasks = [
        ['href' => '/admin/posts/create', 'title' => 'New post', 'text' => 'Publish an article, event or announcement.', 'cta' => 'New post', 'icon' => 'circle-plus', 'external' => false],
        ['href' => '/admin/downloads', 'title' => 'Upload file', 'text' => 'Add forms, calendars or guides to the public downloads page.', 'cta' => 'Upload file', 'icon' => 'upload', 'external' => false],
        ['href' => '/', 'title' => 'Preview public site', 'text' => 'Open the public site to review your changes.', 'cta' => 'Open site', 'icon' => 'school', 'external' => true],
    ];
@endphp
<x-layouts.app title="Dashboard" :breadcrumbs="[['title' => 'Dashboard', 'href' => '/dashboard']]">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 p-6">
        <header class="space-y-2">
            <p class="{{ Ui::EYEBROW }}">Admin</p>
            <h1 class="text-3xl font-bold tracking-tight text-foreground">
                Welcome back, {{ $user->name === null || $user->name === '' ? 'Administrator' : $user->name }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ $actor->isSuperAdmin()
                    ? 'Superadmin • Full administrative controls active.'
                    : 'Department user • Managing content for '.AdminScope::scopeLabel($scope).'.' }}
            </p>
            <x-admin.scope-indicator />
        </header>

        <section aria-labelledby="overview" class="space-y-4">
            <h2 id="overview" class="text-xl font-bold tracking-tight text-foreground" data-test="dashboard-scope">
                Overview · {{ AdminScope::scopeLabel($scope) }}
            </h2>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4 lg:gap-6">
                @foreach ($stats as $stat)
                    <div class="{{ Ui::CARD }} flex flex-col p-4 sm:p-6">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-sans text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                {{ $stat['label'] }}
                            </h3>
                            <div class="shrink-0 rounded-lg bg-gold-500/10 p-2 text-gold-600 dark:text-gold-400">
                                <x-admin.icon :name="$stat['icon']" class="h-5 w-5" />
                            </div>
                        </div>
                        <p class="mt-4 text-3xl font-extrabold tabular-nums text-foreground">{{ $stat['value'] }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ $stat['note'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="quick-tasks" class="space-y-4">
            <h2 id="quick-tasks" class="text-xl font-bold tracking-tight text-foreground">
                Quick tasks
            </h2>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                @foreach ($tasks as $task)
                    <a
                        href="{{ $task['href'] }}"
                        @if ($task['external']) target="_blank" rel="noopener" @endif
                        class="{{ Ui::CARD }} group flex flex-col justify-between p-4 transition-[border-color,box-shadow] duration-200 ease-out hover:border-gold-500 hover:shadow-brand-md sm:p-6"
                    >
                        <div class="space-y-2">
                            <div class="w-fit rounded-lg bg-gold-500/10 p-2.5 text-gold-600 dark:text-gold-400">
                                <x-admin.icon :name="$task['icon']" class="h-6 w-6" />
                            </div>
                            <h3 class="mt-3 font-bold text-foreground transition-colors group-hover:text-link">
                                {{ $task['title'] }}
                            </h3>
                            <p class="text-sm leading-relaxed text-muted-foreground">{{ $task['text'] }}</p>
                        </div>
                        <span class="mt-6 flex items-center gap-1.5 text-sm font-bold text-link transition-[gap] duration-200 ease-out group-hover:gap-2.5">
                            {{ $task['cta'] }} <x-admin.icon name="arrow-right" class="h-3.5 w-3.5" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts.app>
