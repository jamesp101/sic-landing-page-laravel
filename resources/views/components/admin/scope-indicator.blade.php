{{-- Small "Showing: <scope> · change" note so users understand why a list is filtered. Superadmins only. --}}
@php
    $scope = \App\Admin\AdminScope::current(request());
@endphp
@if (\App\Support\Authz::isSuperAdmin(request()->user()))
    <p class="text-xs text-muted-foreground" data-testid="scope-indicator">
        Showing: <span class="font-semibold text-foreground">{{ \App\Admin\AdminScope::scopeLabel($scope) }}</span> ·
        <button
            type="button"
            data-scope-change
            class="rounded-sm font-semibold text-link underline underline-offset-2 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring"
        >change</button>
    </p>
@endif
