<?php

/*
 * Menyiapkan database uji (madingo_test) dan peran aplikasi sebelum
 * pengujian berjalan, memakai akun pemilik dari .env (DB_OWNER_*).
 */

use App\Core\Database\Provisioner;

require __DIR__.'/../vendor/autoload.php';

if (is_file(__DIR__.'/../.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$env = fn (string $key, string $default = '') => (string) ($_ENV[$key] ?? getenv($key) ?: $default);

$provisioner = new Provisioner(
    $env('DB_HOST', '127.0.0.1'),
    $env('DB_PORT', '5432'),
    $env('DB_OWNER_USERNAME', 'postgres'),
    $env('DB_OWNER_PASSWORD'),
);

$database = $env('DB_DATABASE', 'madingo_test');
$provisioner->ensureDatabase($database);
$provisioner->ensureAppRole($env('DB_USERNAME', 'madingo_app'), $env('DB_PASSWORD'), $database);
