// Entry for admin, settings and auth pages (layouts/app, layouts/auth, layouts/settings).
import { islandLoaders, mountIslands } from './islands';

mountIslands(islandLoaders(import.meta.glob('./components/**/*.svelte')));

// Page-level "change" links (scope indicators) ask the sidebar switcher to open.
document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-scope-change]')) {
        window.dispatchEvent(new CustomEvent('admin:open-scope'));
    }
});
