<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\PostgresConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "DATABASE_URL is required.\n");
    exit(1);
}
$sql = file_get_contents(dirname(__DIR__) . '/fixtures/vehicle_catalog_demo.sql');
if ($sql === false) {
    throw new RuntimeException('Could not read vehicle catalogue fixtures.');
}
(new PostgresConnection($url))->get()->exec($sql);
fwrite(STDOUT, "Vehicle Catalog demo fixtures loaded.\n");