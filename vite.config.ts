import { fileURLToPath } from 'node:url';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

// Entries: `site.ts` (public pages: vanilla scripts + public islands), `admin.ts` (admin, settings and auth
// islands). Blade loads them with @vite; islands are code-split per component (resources/js/islands.ts).
export default defineConfig({
    plugins: [
        // The Laravel plugin refuses to start in CI environments; unit tests do not need it.
        ...(process.env.VITEST
            ? []
            : [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/site.ts', 'resources/js/admin.ts'],
                      refresh: true,
                  }),
              ]),
        svelte(),
        tailwindcss(),
    ],
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    // `cropperjs` is only loaded through a dynamic import() in ImageCropper.svelte. Pre-bundle it at
    // dev startup so Vite does not re-optimize mid-session and answer 504 "Outdated Optimize Dep".
    optimizeDeps: { include: ['cropperjs'] },
    server: {
        // Not the default `localhost`: Node resolves it to `[::1]`, the hot file then says `http://[::1]:5173`,
        // and a bracketed IPv6 address is not a valid CSP source, so browsers block every dev asset.
        host: '127.0.0.1',
        watch: { ignored: ['**/storage/**', '**/vendor/**'] },
    },
    test: { include: ['resources/js/**/*.test.ts'] },
});
