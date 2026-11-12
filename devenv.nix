{ pkgs, lib, config, ... }:

# One-command workflows:
#   devenv up     -> MariaDB, install deps, migrate, seed (first run), `php artisan serve` + Vite dev server
#   devenv test   -> full check suite (pint, types, unit tests, build, PHPUnit, e2e) on a throwaway SQLite file
#   devenv shell  -> php 8.4 + composer + node 24 + pnpm + playwright browsers + mariadb client
{
  dotenv.enable = true;

  packages = [ pkgs.sqlite pkgs.mariadb_114 ];

  # Local MariaDB for `devenv up` (DB_* in .env.example). Data lives in .devenv/state/mysql.
  # The `sic` user may create databases: e2e runs create their own `sic_e2e_<port>` database.
  services.mysql = lib.mkIf (!config.devenv.isTesting) {
    enable = true;
    package = pkgs.mariadb_114;
    settings.mysqld = {
      bind-address = "127.0.0.1";
      port = 3306;
      character-set-server = "utf8mb4";
      collation-server = "utf8mb4_unicode_ci";
    };
    initialDatabases = [ { name = "sic"; } ];
    ensureUsers = [
      {
        name = "sic";
        password = "sic";
        ensurePermissions = { "*.*" = "ALL PRIVILEGES"; };
      }
    ];
  };

  languages.php = {
    enable = true;
    # imagick: animated GIF -> animated WebP (GD keeps only the first frame).
    package = pkgs.php84.buildEnv {
      extensions = { enabled, all }: enabled ++ [ all.imagick ];
      extraConfig = ''
        memory_limit = 512M
        upload_max_filesize = 24M
        post_max_size = 26M
      '';
    };
  };

  languages.javascript = {
    enable = true;
    package = pkgs.nodejs_24;
    pnpm = {
      enable = true;
      install.enable = true;
    };
  };

  env = {
    # Playwright browsers from nixpkgs; keep @playwright/test pinned to this version.
    PLAYWRIGHT_BROWSERS_PATH = "${pkgs.playwright-driver.browsers}";
    PLAYWRIGHT_SKIP_VALIDATE_HOST_REQUIREMENTS = "true";
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD = "1";
  };

  enterShell = ''
    [ -f .env ] || cp .env.example .env
    echo "$(php --version | head -1) | node $(node --version) | pnpm $(pnpm --version) | playwright ${pkgs.playwright-driver.version}"
  '';

  tasks."app:setup" = lib.mkIf (!config.devenv.isTesting) {
    exec = "composer install && pnpm install && (grep -q '^APP_KEY=.\\+' .env || php artisan key:generate) && php artisan migrate --force && php artisan sic:seed --if-empty";
    after = [ "devenv:mysql:configure" ];
    before = [ "devenv:processes:laravel" "devenv:processes:vite" ];
  };

  processes = lib.mkIf (!config.devenv.isTesting) {
    laravel.exec = "php artisan serve --host=127.0.0.1 --port=8000";
    vite.exec = "pnpm run dev";
  };

  # CI stays on SQLite: a throwaway file, independent of the MariaDB settings in .env.
  enterTest = ''
    set -euo pipefail
    export DB_CONNECTION=sqlite DB_DATABASE="$PWD/storage/ci.sqlite"
    composer install --no-interaction
    pnpm install --frozen-lockfile
    composer run ci
  '';
}
