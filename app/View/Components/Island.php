<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A Svelte island: `<x-island name="admin/posts/PostForm" :props="[...]" />` renders a mount point that
 * resources/js/islands.ts fills with `resources/js/components/<name>.svelte`. Props are JSON (camelCase keys,
 * the component's own prop names). The slot is server-rendered fallback markup, replaced on mount.
 */
class Island extends Component
{
    /** @param array<string, mixed> $props */
    public function __construct(public string $name, public array $props = []) {}

    public function render(): View
    {
        return view('components.island');
    }
}
