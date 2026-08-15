<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The production API is deployed below /endpoints. The parent .htaccess
// forwards clean URLs into public while REQUEST_URI keeps the prefix.
$deploymentPrefix = '/endpoints';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (
    ($requestUri === $deploymentPrefix || str_starts_with($requestUri, $deploymentPrefix.'/'))
    && ! str_starts_with($requestUri, $deploymentPrefix.'/public')
) {
    $_SERVER['REQUEST_URI'] = substr($requestUri, strlen($deploymentPrefix)) ?: '/';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
