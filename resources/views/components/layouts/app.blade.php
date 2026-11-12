{{--
    Admin shell: sidebar, header, breadcrumbs and toasts. Always noindex. The `admin/AdminShell` island adopts
    the server-rendered page content (slot). Props: title, breadcrumbs (list of ['title', 'href'?]).
--}}
@props(['title', 'breadcrumbs' => []])
@php
    $user = request()->user();
    $scope = \App\Admin\AdminScope::current(request());
    $scopeSummary = [
        'kind' => $scope['kind'],
        'id' => $scope['kind'] === 'department' ? $scope['id'] : null,
        'label' => \App\Admin\AdminScope::scopeLabel($scope),
    ];
    $scopeDepartments = array_map(fn (array $d) => [
        'id' => $d['id'],
        'name' => $d['name'],
        'logoUrl' => \App\Storage\Storage::publicUrl($d['logoPath']),
    ], \App\Admin\AdminScope::departments(request()));
    $sidebarOpen = \App\Admin\AdminScope::cookieValue(request(), 'sidebar_state') !== 'false';
    $currentPath = '/'.trim(request()->path(), '/');
@endphp
<x-layouts.base :seo="['title' => $title, 'description' => 'San Isidro College administration.', 'noindex' => true]" entry="admin">
    <x-island
        name="admin/AdminShell"
        data-island-adopt
        :props="[
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'currentPath' => $currentPath,
            'breadcrumbs' => $breadcrumbs,
            'sidebarOpen' => $sidebarOpen,
            'scope' => $scopeSummary,
            'scopeDepartments' => $scopeDepartments,
            'scopeLocked' => ! \App\Support\Authz::isSuperAdmin($user),
            'flash' => \App\Support\Flash::take(),
        ]"
    >
        {{ $slot }}
    </x-island>
</x-layouts.base>
