import { createRawSnippet, mount, type Component } from 'svelte';

type Loader = () => Promise<{ default: Component<any> }>;

/**
 * Mounts the Svelte islands a Blade page declares with `<x-island name="…" :props="[…]" />`:
 * `<div data-island="admin/posts/PostForm" data-props="{…}">`. `loaders` maps the name (path under
 * `resources/js/components`, no extension) to its lazy import, so each island is its own chunk.
 * Server-rendered fallback markup inside the mount point is replaced. `data-island-ready` marks a mounted island.
 * An island marked `data-island-adopt` (the admin shell) wraps server-rendered page content instead: the child nodes
 * of its `data-island-children` element are kept and handed to the component as its `children` snippet; the
 * server-rendered shell around them is replaced.
 */
export function mountIslands(loaders: Record<string, Loader>, root: ParentNode = document): void {
    for (const target of root.querySelectorAll<HTMLElement>('[data-island]:not([data-island-ready])')) {
        const name = target.dataset.island!;
        const load = loaders[name];
        if (!load) {
            console.error(`Unknown island: ${name}`);
            continue;
        }
        const props = JSON.parse(target.dataset.props || '{}');
        void load().then(({ default: component }) => {
            if (target.hasAttribute('data-island-adopt')) {
                const holder = target.querySelector<HTMLElement>('[data-island-children]');
                if (!holder) {
                    console.error(`Island ${name} adopts content but has no [data-island-children]`);
                    return;
                }
                const nodes = [...holder.childNodes];
                const children = createRawSnippet(() => ({
                    render: () => '<div style="display: contents"></div>',
                    setup: (element) => element.append(...nodes),
                }));
                target.replaceChildren();
                mount(component, { target, props: { ...props, children } });
            } else {
                target.replaceChildren();
                mount(component, { target, props });
            }
            target.dataset.islandReady = '';
        });
    }
}

// `import.meta.glob('./components/**/*.svelte')` → `{ 'admin/posts/PostForm': loader }`.
export function islandLoaders(modules: Record<string, () => Promise<unknown>>): Record<string, Loader> {
    return Object.fromEntries(
        Object.entries(modules).map(([path, loader]) => [
            path.replace(/^\.\/components\//, '').replace(/\.svelte$/, ''),
            loader as Loader,
        ]),
    );
}
