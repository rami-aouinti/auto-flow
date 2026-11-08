<?php

declare(strict_types=1);

use Doctrine\\DBAL\\DriverManager;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('RESERVATION_WORKER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('RESERVATION_WORKER_DATABASE_URL is required.');
}

/** @var array{scheme?:string,host?:string,port?:int,user?:string,pass?:string,path?:string}|false $parts */
$parts = parse_url($databaseUrl);
if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
    throw new RuntimeException('A valid PostgreSQL database URL is required.');
}

$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => $parts['host'] ?? 'localhost',
    'port' => $parts['port'] ?? 5432,
    'user' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
    'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
    'dbname' => rawurldecode(ltrim($parts['path'] ?? '', '/')),
]);

$interval = max(1, min(60, (int) (getenv('RESERVATION_EXPIRY_INTERVAL_SECONDS') ?: 5)));
$batchSize = max(1, min(500, (int) (getenv('RESERVATION_EXPIRY_BATCH_SIZE') ?: 100)));

fwrite(STDOUT, sprintf("Vehicle Reservation expiry worker started (interval=%ds, batch=%d).%s", $interval, $batchSize, PHP_EOL));

while (true) {
    try {
        $expired = (int) $db->fetchOne(
            'SELECT public.vehicle_reservation_expire_due(:limit)',
            ['limit' => $batchSize],
        );

        if ($expired > 0) {
            fwrite(STDOUT, sprintf("Expired %d reservation(s).%s", $expired, PHP_EOL));
        }
    } catch (Throwable $error) {
        fwrite(STDERR, sprintf("Reservation expiry failed: %s%s", $error->getMessage(), PHP_EOL));
    }

    sleep($interval);
}
