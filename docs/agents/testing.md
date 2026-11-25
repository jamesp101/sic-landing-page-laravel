# Testing

## Demo data

`Database\Seeders\DemoData` is shared by `DatabaseSeeder` and the specs: the Users, Departments, Posts, Hero sections and Members that specs look up by name. Rows refer to each other by key (`'department' => 'cit'`, `'author' => 'cit'`, `'parent' => <member key>`); the seeder resolves keys to ids. `php artisan sic:seed [--if-empty]` seeds; `--if-empty` does nothing once a User exists. `SchoolsSeeder` (`php artisan db:seed --class=SchoolsSeeder`, `SCHOOLS_RESET=1` recreates) adds five schools with 20 posts each. `php artisan sic:backfill-search` recomputes `search_text`.

Seeding writes demo Users with known passwords, so only a local database (SQLite or a loopback host) is accepted unless `SEED_ALLOW_REMOTE=1`; passwords come from `SEED_SUPERADMIN_PASSWORD` / `SEED_DEPARTMENT_PASSWORD` (`config('sic.seed')`).

`Tests\TestCase` offers `actingAsSuperadmin()`, `actingAsDepartmentUser($department)` and `postAction('posts.create', $input)` (the response of `POST /_actions/<name>`). `composer ci` runs pint, PHPUnit, svelte-check, vitest, the build and the e2e specs.

## E2E specs

Specs run in parallel against one shared database. A spec that compares two loads of the same page (server markup vs mounted island: `site-header`, `admin-shell`) only reads, and runs in the `layout-parity` project before every other spec (`playwright.config.ts`); otherwise a spec that writes (Navigation links, posts) changes the page between its two loads. Add a new read-only layout-parity spec to that project's `testMatch` and to `chromium`'s `testIgnore`.
