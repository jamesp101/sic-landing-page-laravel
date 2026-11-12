<?php

// Front controller of a cPanel document root. deploy/cpanel/deploy.sh copies this file to
// <document root>/index.php and replaces the application path. Same as public/index.php, except that the
// application lives outside the web root and this directory is its public path.

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appDir = '__APP_DIR__';

if (file_exists($maintenance = $appDir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appDir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appDir.'/bootstrap/app.php';

// Vite reads build/manifest.json from the public path.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
