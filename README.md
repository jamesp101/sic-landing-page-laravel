# San Isidro College — website (Laravel)

Laravel port of the Astro site (`../sic-landing-page-astro`). Pages are server-rendered in Blade for SEO; Svelte islands are mounted only where interaction is needed.

Stack: Laravel 13 · Blade · Svelte 5 islands (Vite) · Tailwind v4 · Eloquent (MariaDB/MySQL; SQLite in tests) · Laravel session auth · uploads on local disk or S3 · PHPUnit, Vitest, Playwright · devenv for the toolchain.

## Quick start

Requires [devenv](https://devenv.sh) (Nix). Everything else (PHP 8.4 with imagick, Composer, Node 24, pnpm, MariaDB 11.4, Playwright browsers) comes from `devenv.nix`.

| Goal | Command |
| --- | --- |
| Run the dev stack (MariaDB, migrate, seed on first run, Laravel + Vite) | `devenv up` |
| Run the full check suite on a throwaway SQLite file | `devenv test` |
| Open a shell with the toolchain | `devenv shell` |

Site: <http://localhost:8000>. Seeded logins: `admin@sic.edu.ph` (superadmin), `cit@sic.edu.ph`, `cba@sic.edu.ph` (department users); passwords come from `SEED_SUPERADMIN_PASSWORD` / `SEED_DEPARTMENT_PASSWORD` in `.env` (created from `.env.example`).

`devenv up` refuses to start when port 3306 is already taken (`strict_ports: true` in `devenv.yaml`). Stop whatever holds the port and run `devenv up` again.

## Daily commands (inside `devenv shell`)

| Task | Command |
| --- | --- |
| PHP tests | `php artisan test` |
| PHP code style | `vendor/bin/pint` |
| Svelte/TypeScript check | `pnpm run check` |
| TypeScript unit tests | `pnpm run test` |
| Production assets | `pnpm run build` |
| End-to-end tests (needs a build) | `pnpm run e2e` |
| Everything CI runs | `composer run ci` |
| Migrate | `php artisan migrate` |
| Seed demo data (local database only) | `php artisan db:seed` |
| Create a Superadmin in production | `php artisan sic:create-admin` |
| Recompute `search_text` | `php artisan sic:backfill-search` |
| Delete unreferenced uploads (older than 24 h) | `php artisan storage:cleanup [--dry-run]` |
| Create missing Image variants | `php artisan storage:variants` |

## Database

Table and column names are those of the Astro site (and of the original Laravel site), so an existing database is adopted in place: `php artisan migrate` creates only the tables that are missing. The Better Auth tables of the Astro site (`auth_sessions`, `auth_accounts`, `auth_verifications`, `auth_rate_limits`) are not used; `users.password` already holds the bcrypt hash, so existing Users keep their Password login.

## How it fits together

- **Pages**: controllers in `app/Http/Controllers/{Site,Admin,Auth,Settings}` render Blade views. Public pages are cacheable for anonymous visitors (no cookies, `Cache-Control: public`).
- **Islands**: `<x-island name="…" :props="[…]" />` mounts a Svelte component from `resources/js/components`. Public pages load `resources/js/site.ts`; admin, settings and sign-in pages load `resources/js/admin.ts`.
- **Actions**: islands call `actions.posts.create(input)` (`resources/js/lib/actions.ts`), which posts to `/_actions/posts.create` and runs `App\Actions\PostsActions::create()`.
- **Rich content**: Block documents and Legacy Markdown are rendered by `App\Content\Content` on the server and by the same rules in TypeScript inside the editor canvas.
- **Uploads**: `STORAGE_DRIVER` selects the backend (`local` or `s3`, see `.env.example`); code only talks to `App\Storage\Storage`, and the database stores bare keys. Files are served at `/storage/<key>`.

See `AGENTS.md` for the rules to keep when changing code, and `GLOSSARY.md` for the domain terms.

## Deployment

cPanel (shared hosting): follow `docs/deploy-cpanel.md`.

Any PHP 8.4+ host (nginx or Apache + PHP-FPM, or FrankenPHP) with the `intl`, `gd`, `pdo_mysql` and `imagick` extensions. Without `imagick` the site works, but an animated GIF upload becomes a still WebP.

1. `composer install --no-dev --optimize-autoloader`, `pnpm install --frozen-lockfile && pnpm run build`.
2. Set `.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` = the public origin (mailed links and canonical URLs use it), `DB_*`, mail, storage, `SESSION_SECURE_COOKIE=true`). Behind a reverse proxy set `TRUSTED_PROXIES`, otherwise the per-IP rate limits see the proxy address.
3. `php artisan migrate --force`, `php artisan optimize`.
4. Point `UPLOAD_DIR` at a persistent directory outside the deploy folder (e.g. `/var/lib/sic/uploads`) and back it up with the database, or use `STORAGE_DRIVER=s3`.
5. Cron: `* * * * * php artisan schedule:run` (daily storage cleanup).
6. PHP limits: `upload_max_filesize` ≥ 24M and `post_max_size` ≥ 26M (Downloads up to 20 MB), `memory_limit` ≥ 512M when images are converted with GD. Videos (up to 100 MB) are streamed from the request body: the web server body limit (`client_max_body_size`, `LimitRequestBody`) must allow 101 MB on `/api/admin/uploads/video`.
7. A database adopted from the Astro site: Users keep their passwords. Sessions do not carry over (everyone signs in once), and the Better Auth tables (`auth_*`) are left untouched and unused.
