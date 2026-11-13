{{--
    Admin shell: sidebar, header, breadcrumbs and toasts. Always noindex. The server renders the shell around the page
    content (`x-admin.shell`) so every page paints with its sidebar; the `admin/AdminShell` island then replaces it
    and adopts the content. Props: title, breadcrumbs (list of ['title', 'href'?]).
--}}
@props(['title', 'breadcrumbs' => []])
@php
    $user = request()->user();
    $scope = \App\Admin\AdminScope::current(request());
    $currentPath = '/'.trim(request()->path(), '/');
    $shell = [
        'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        'navItems' => \App\Admin\AdminNav::items($user, $scope, $currentPath),
        'breadcrumbs' => $breadcrumbs,
        'sidebarOpen' => \App\Admin\AdminScope::cookieValue(request(), 'sidebar_state') !== 'false',
        'scope' => [
            'kind' => $scope['kind'],
            'id' => $scope['kind'] === 'department' ? $scope['id'] : null,
            'label' => \App\Admin\AdminScope::scopeLabel($scope),
        ],
        'scopeDepartments' => array_map(fn (array $d) => [
            'id' => $d['id'],
            'name' => $d['name'],
            'logoUrl' => \App\Storage\Storage::publicUrl($d['logoPath']),
        ], \App\Admin\AdminScope::departments(request())),
        'scopeLocked' => ! \App\Support\Authz::isSuperAdmin($user),
    ];
@endphp
<x-layouts.base :seo="['title' => $title, 'description' => 'San Isidro College administration.', 'noindex' => true]" entry="admin">
    <x-island name="admin/AdminShell" data-island-adopt :props="[...$shell, 'flash' => \App\Support\Flash::take()]">
        <x-admin.shell :shell="$shell">{{ $slot }}</x-admin.shell>
    </x-island>
</x-layouts.base>
