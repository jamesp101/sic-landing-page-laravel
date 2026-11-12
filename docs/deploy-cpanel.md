# Deploying on cPanel

The application is checked out outside the web root. Each deployment copies the contents of `public/` into the document root (`public_html`) and writes an `index.php` there that points back at the checkout (`deploy/cpanel/deploy.sh`, run by `.cpanel.yml`).

## Requirements

- PHP 8.4 or newer for the domain (MultiPHP Manager), with `intl`, `gd`, `pdo_mysql`, `mbstring`, `fileinfo` and, if the host offers it, `imagick` (without it an animated GIF upload becomes a still WebP).
- A MySQL or MariaDB database and user (MySQL Databases).
- Terminal or SSH access, and Composer (present on most cPanel hosts).
- Apache or LiteSpeed with `mod_rewrite` (`.htaccess`).

cPanel has no Node, so the built assets come with the code: the GitHub workflow `cPanel branch` publishes every push to the main branch, plus `public/build`, as the branch `cpanel`. Deploy that branch.

## First deployment

1. **Clone**: Git Version Control → Create. Clone URL of the repository, repository path `repositories/sic` (anywhere outside `public_html`), then Manage → check out the branch `cpanel`.
2. **Configure**: in the Terminal:

    ```sh
    cd ~/repositories/sic
    cp .env.example .env
    ```

    Edit `.env`:

    | Key | Value |
    | --- | --- |
    | `APP_ENV`, `APP_DEBUG` | `production`, `false` |
    | `APP_URL` | the public origin, e.g. `https://www.sic.edu.ph` |
    | `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mariadb` (or `mysql`), `localhost`, and the database and user created in cPanel |
    | `SESSION_SECURE_COOKIE` | `true` |
    | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | `smtp` and an Email Account of the domain |
    | `UPLOAD_DIR` | an absolute directory outside the checkout and outside `public_html`, e.g. `/home/USER/sic-uploads` (create it); it survives redeployments and belongs in the backup |
    | `SEED_SUPERADMIN_PASSWORD`, `SEED_DEPARTMENT_PASSWORD` | remove both lines |

3. **Deploy**: Git Version Control → Manage → Pull or Deploy → Deploy HEAD Commit (or `bash deploy/cpanel/deploy.sh` in the Terminal). The script installs the PHP packages, generates `APP_KEY` if it is empty, publishes the web root, migrates the database and caches the configuration.
4. **First Superadmin**: `php artisan sic:create-admin` in the Terminal (use the PHP 8.4 binary, see below). Skip this when the database is adopted from the earlier site: its Users keep their passwords.
5. **Cron**: Cron Jobs → every minute:

    ```sh
    /opt/cpanel/ea-php84/root/usr/bin/php /home/USER/repositories/sic/artisan schedule:run >/dev/null 2>&1
    ```

    It runs the daily storage cleanup.
6. **HTTPS**: issue the certificate (SSL/TLS Status) and switch on Force HTTPS Redirect for the domain (Domains).

## Later deployments

Git Version Control → Manage → Pull or Deploy → Update from Remote, then Deploy HEAD Commit. The site answers 503 for the few seconds the script runs. A failed run leaves the site in maintenance mode only if `artisan up` itself fails; run `php artisan up` once the cause is fixed.

## The web root's `.htaccess`

The script writes the rules of `public/.htaccess` between `# BEGIN sic` and `# END sic` at the top of the document root's `.htaccess` and replaces only that section on later deployments. Everything else in the file stays, including the `cPanel-generated handler` block that MultiPHP Manager writes to select the domain's PHP version. Change the site's rules in `public/.htaccess`, never inside the section. Rules left by an earlier site in that file (for example WordPress rewrites) are kept too: remove them by hand before the first deployment.

## Settings of the deploy script

Set them in the shell that runs the script, or as `export` lines before the task in `.cpanel.yml`.

| Variable | Default | Use it when |
| --- | --- | --- |
| `CPANEL_DOCROOT` | `$HOME/public_html` | the site is an addon domain or subdomain with its own document root |
| `CPANEL_PHP` | newest of `/opt/cpanel/ea-php85`, `ea-php84`, else `php` | the host keeps PHP elsewhere (CloudLinux: `/opt/alt/php84/usr/bin/php`) |
| `CPANEL_COMPOSER` | `composer` | Composer is missing: download `composer.phar` and point this at it |

The account's plain `php` is often an older version than the domain's. Use the same binary as the script for `artisan` commands, e.g. `/opt/cpanel/ea-php84/root/usr/bin/php artisan …`.

## Limits of shared hosting

- **PHP limits**: `public/.user.ini` (copied into the web root) raises the upload, POST and memory limits. Values set in MultiPHP INI Editor win; keep `upload_max_filesize` ≥ 24M, `post_max_size` ≥ 26M, `memory_limit` ≥ 512M.
- **Videos**: an uploaded video (up to 100 MB) is one request body. If the host caps request bodies lower (`LimitRequestBody`, a ModSecurity rule, a proxy in front), large videos fail with 413; ask the host to raise the cap for `/api/admin/uploads/video`, use a YouTube link for the Slide, or store uploads on S3 (`STORAGE_DRIVER=s3`), where the browser uploads straight to the bucket.
- **Client IP**: rate limits count per client IP. Behind Cloudflare or another proxy set `TRUSTED_PROXIES` in `.env`; on plain cPanel leave it empty.
- **After editing `.env`** run `php artisan optimize` again (the configuration is cached).
