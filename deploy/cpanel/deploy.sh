#!/usr/bin/env bash
# Deploys this checkout on a cPanel account. Run from the repository root (cPanel's Git Version Control runs it
# through .cpanel.yml on every deployment; over SSH: `bash deploy/cpanel/deploy.sh`). Safe to repeat.
#
# The application stays where it is checked out, outside the web root. Only the contents of public/ are copied
# into the document root, with an index.php that points back at this checkout.
#
# Settings (environment variables, all optional):
#   CPANEL_DOCROOT   document root of the domain            default: $HOME/public_html
#   CPANEL_PHP       PHP 8.4+ CLI binary                    default: newest /opt/cpanel/ea-php8x, else `php`
#   CPANEL_COMPOSER  composer command or composer.phar path default: `composer`
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCROOT="${CPANEL_DOCROOT:-$HOME/public_html}"
cd "$APP_DIR"

fail() {
    echo "cPanel deploy: $*" >&2
    exit 1
}

# cPanel's default `php` is often older than the domain's PHP (MultiPHP): prefer the EasyApache 8.4+ binaries.
PHP="${CPANEL_PHP:-}"
if [ -z "$PHP" ]; then
    for candidate in /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php; do
        if [ -x "$candidate" ]; then
            PHP="$candidate"
            break
        fi
    done
fi
PHP="${PHP:-php}"
"$PHP" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' ||
    fail "$PHP is PHP $("$PHP" -r 'echo PHP_VERSION;'); 8.4 or newer is required. Set CPANEL_PHP."

COMPOSER="${CPANEL_COMPOSER:-composer}"
COMPOSER_PATH="$(command -v "$COMPOSER" || true)"
[ -n "$COMPOSER_PATH" ] || [ -f "$COMPOSER" ] ||
    fail "composer was not found. Set CPANEL_COMPOSER to its path (or to composer.phar)."
COMPOSER_PATH="${COMPOSER_PATH:-$COMPOSER}"
# A PHP script or phar runs with the PHP chosen above (the account's default `php` may be older);
# a native wrapper runs as it is, with that PHP first on PATH.
case "$(head -c 2 "$COMPOSER_PATH")" in
    '#!' | '<?') COMPOSER_CMD=("$PHP" "$COMPOSER_PATH") ;;
    *) COMPOSER_CMD=("$COMPOSER_PATH") ;;
esac
case "$PHP" in
    */*) export PATH="$(dirname "$PHP"):$PATH" ;;
esac

[ -f .env ] || fail "$APP_DIR/.env is missing. Copy .env.example to .env and fill it in (see docs/deploy-cpanel.md)."
# The host has no Node: the assets are built before the code reaches it (the \`cpanel\` branch carries them).
[ -f public/build/manifest.json ] ||
    fail "public/build is missing. Deploy the \`cpanel\` branch, or upload the output of \`pnpm run build\`."
[ -d "$DOCROOT" ] || fail "document root $DOCROOT does not exist. Set CPANEL_DOCROOT."
case "$DOCROOT/" in
    "$APP_DIR"/*) fail "the document root must not be inside the application directory ($APP_DIR)." ;;
esac
case "$APP_DIR/" in
    "$DOCROOT"/*) fail "the application must not live inside the document root ($DOCROOT): .env would be one rewrite rule away from the web." ;;
esac

echo "cPanel deploy: $APP_DIR -> $DOCROOT ($("$PHP" -r 'echo PHP_VERSION;'))"

"${COMPOSER_CMD[@]}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

grep -q '^APP_KEY=.\+' .env || "$PHP" artisan key:generate --force

"$PHP" artisan down --retry=15 || true
trap '"$PHP" artisan up || true' EXIT

# Web root: everything in public/ except the front controller (written below) and the dev-server marker.
# Nothing else in the document root is touched (.well-known, cgi-bin, …); only build/ is mirrored exactly,
# so assets of earlier builds do not pile up.
HTACCESS_KEPT="$(mktemp)"
if [ -f "$DOCROOT/.htaccess" ]; then
    # Lines the host or the owner put there (MultiPHP's "cPanel-generated handler" block selects the domain's
    # PHP version): everything outside our own section survives.
    awk '/^# BEGIN sic\r?$/ { skip = 1; next } /^# END sic\r?$/ { skip = 0; next } !skip' "$DOCROOT/.htaccess" >"$HTACCESS_KEPT"
fi
mkdir -p "$DOCROOT/build"
cp -R public/. "$DOCROOT/"
rm -f "$DOCROOT/hot"
rm -rf "$DOCROOT/build"
cp -R public/build "$DOCROOT/build"
{
    echo '# BEGIN sic'
    echo '# Managed by deploy/cpanel/deploy.sh: rewritten on every deployment. Edit public/.htaccess instead.'
    cat public/.htaccess
    echo '# END sic'
    cat "$HTACCESS_KEPT"
} >"$DOCROOT/.htaccess"
rm -f "$HTACCESS_KEPT"

# Front controller of the document root: public/index.php with the paths of this checkout.
sed "s#__APP_DIR__#${APP_DIR//#/\\#}#g" deploy/cpanel/index.php >"$DOCROOT/index.php"

"$PHP" artisan migrate --force
# Initial Superadmin from ADMIN_EMAIL / ADMIN_NAME / ADMIN_PASSWORD in .env, only while none exists. The cached
# configuration of the previous deploy hides .env from env(), so clear it first (optimize rebuilds it below).
"$PHP" artisan config:clear
"$PHP" artisan sic:create-admin --if-missing --no-interaction
"$PHP" artisan optimize

trap - EXIT
"$PHP" artisan up
echo "cPanel deploy: done."
