# AGENTS.md

San Isidro College website. Laravel 13 + Blade (server-rendered) + Svelte 5 islands + Tailwind v4 + Eloquent. See `README.md` for commands and `GLOSSARY.md` for domain terms (Slide, Layer, Highlight image, Department, …).

## Environment

- Run everything inside `devenv shell` (PHP 8.4 with imagick, Composer, Node 24, pnpm, MariaDB client, Playwright browsers). `devenv up` = dev stack (MariaDB + `artisan serve` + Vite); `devenv test` = full CI on a throwaway SQLite file.
- PHP checks: `php artisan test`, `vendor/bin/pint`. JS checks: `pnpm run check` (svelte-check), `pnpm run test` (Vitest), `pnpm run build`, `pnpm run e2e` (Playwright).

## Always apply

- Schema: new migration per change; keep the existing table and column names (the production database is adopted in place). Guard with `Schema::hasTable` / `hasColumn` where an existing database may already have the object.
- Design: colours only through tokens (`resources/css/tokens.css`), no hex in components. Public page widths only through the page frame (`page-frame`, `reading-frame`, `--measure` in `app.css`), never `max-w-*` + `px-6` by hand.
- Authorization: every action, upload route and admin controller method calls `App\Support\Authz` first (directly or through `App\Admin\AdminPage` / `App\Admin\DepartmentAccess`). The `auth` middleware only redirects signed-out visitors.
- Rejections: throw `App\Support\RequestError` (or `ValidationException` for field errors); `bootstrap/app.php` renders it per entry point. No hand-built error JSON.
- Actions: `POST /_actions/<module>.<action>` → `App\Actions\<Module>Actions::<action>()` (`ActionController`). Input and result use the camelCase names the islands send and read. A new public action must be added to `ActionController::PUBLIC`.
- Rich content: render, parse, excerpt and validate only through `App\Content\Content`; writers set `search_text` with `Content::searchTextOf`. The PHP renderer and the TS renderer (`resources/js/lib/blocks`, used by the editor canvas) must produce the same HTML: change both, and the parity fixtures in `tests/Unit/Content/fixtures`.
- Files: only through `App\Storage\Storage`; rows that own files write and remove them through `App\Storage\StoredFiles` (`commitWithFiles` / `deleteWithFiles`); store bare keys.
- Islands: `<x-island name="admin/posts/PostForm" :props="[…]" />` mounts `resources/js/components/<name>.svelte`. Props are the component's camelCase props. Public pages load only `resources/js/site.ts` (no admin bundle).
- Public pages are cacheable for anonymous visitors: no session cookie, no CSRF meta, no per-visitor markup in them (`App\Http\Middleware\RequestPolicy`).
- Inline scripts need `nonce="{{ Vite::cspNonce() }}"` (CSP has no `unsafe-inline` for scripts).
- Tests: PHPUnit in `tests/Unit` and `tests/Feature` (in-memory SQLite); Vitest beside the TS code (`resources/js/**/*.test.ts`); Playwright in `e2e/`.
- Shared rules exist twice where both sides need them (Block rendering, Overlay parsing and Mobile layout, org and nav tree rules, slug, public-page test): PHP under `app/`, TypeScript under `resources/js/lib`. Change both; the TS-generated fixtures under `tests/Unit/**/fixtures` fail when they disagree.
- PHP 8.4 is the floor (`devenv.nix`, `composer.json`): no 8.5-only features.

## Area docs

Read the doc before editing in its area.

- Editing the middleware, request policy, CSP, caching or cookies → `docs/agents/http-security.md`
- Editing public prefetch or the `sic_staff` hint → `docs/agents/navigation-prefetch.md`
- Writing tests, the action test helpers or demo data → `docs/agents/testing.md`
- Editing `.cpanel.yml`, `deploy/cpanel`, `public/.htaccess`, `public/.user.ini` or the `cPanel branch` workflow → `docs/deploy-cpanel.md`

## Layout

| Area | Code |
| --- | --- |
| Public site (queries, SEO, navigation, Downloads page) | `app/Site`, `app/Http/Controllers/Site`, `resources/views/site`, `resources/views/components/site` |
| Rich content (Block documents, Legacy Markdown) | `app/Content`, `resources/js/lib/blocks`, `resources/js/components/admin/editor` |
| Carousel, Overlay, slide designer | `app/Carousel`, `resources/js/lib/carousel-*.ts`, `resources/js/components/admin/carousels` |
| Admin (scope, lists, pages, Org chart, Navigation links) | `app/Admin`, `app/Actions`, `app/Http/Controllers/Admin`, `resources/views/admin` |
| Users, Password login, rate limits, mail | `app/Auth`, `app/Http/Controllers/Auth`, `resources/views/auth`, `resources/views/settings` |
| Storage, uploads, Image variants, cleanup | `app/Storage`, `app/Http/Controllers/Storage` |
| Request policy, CSP, caching, staff hint | `app/Http/Middleware/RequestPolicy.php`, `app/Http/RequestPolicyRules.php` |
| Demo data and seeders | `database/seeders` |
