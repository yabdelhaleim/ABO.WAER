<?php

/**
 * Laravel — Built-in PHP Development Server Router
 *
 * Fixes an issue where real public/ subdirectories (e.g. public/admin/,
 * public/storage/) are served as directory listings by PHP's built-in
 * server instead of being forwarded to index.php / Laravel's router.
 *
 * Usage (from project root):
 *   php -S localhost:8000 -t public server.php
 *
 * The standard `php artisan serve` uses its own router that does NOT
 * include the `! is_dir()` guard, so requests to /admin are intercepted
 * by the real public/admin/ directory and never reach Laravel.
 * Using this file instead solves the problem.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$publicPath = __DIR__.'/public'.$uri;

// Let the built-in server serve real *files* (CSS, JS, images, fonts …)
// but do NOT let it auto-serve real *directories* — those must fall
// through to Laravel so that routes like /admin, /storage, … work.
if ($uri !== '/' && file_exists($publicPath) && ! is_dir($publicPath)) {
    return false;
}

// Everything else goes to Laravel.
require_once __DIR__.'/public/index.php';
