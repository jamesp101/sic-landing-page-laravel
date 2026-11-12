// Entry for public pages (layouts/public): only the public islands, so no admin bundle is reachable.
import { hasStaffHint } from '@/lib/staff-hint';
import './scripts/print-details';
import { islandLoaders, mountIslands } from './islands';

mountIslands(islandLoaders(import.meta.glob('./components/site/*.svelte')));

// Anonymous visitors only: signed-in responses are not cacheable, so prefetching them would not help.
if (!hasStaffHint(document.cookie)) void import('./scripts/public-prefetch');
// Albums render as plain links; the lightbox upgrades them on pages that have one.
if (document.querySelector('[data-album]')) void import('./scripts/lightbox');
