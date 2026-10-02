<?php

/*
 * index.php dla serwera: pliki publiczne są w /home/iascomm/public_html/tm,
 * a sama aplikacja w /home/iascomm/TimeManager (niedostępna z internetu).
 * Przy każdym wdrożeniu deploy/deploy.sh kopiuje go do public_html/tm.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_path = dirname(__DIR__, 2).'/TimeManager';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $app_path.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $app_path.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
$app = require_once $app_path.'/bootstrap/app.php';

// Katalog publiczny to ten folder (dla manifestu Vite i public_path()).
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
