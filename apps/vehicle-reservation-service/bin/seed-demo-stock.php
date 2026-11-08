<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('RESERVATION_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    fwrite(STDERR, "RESERVATION_DATABASE_URL is required.\n");
    exit(1);
}

$tenantId = $argv[1] ?? 'tenant-demo';
$variantId = $argv[2] ?? '';
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)) {
    fwrite(STDERR, "Tenant id is invalid.\n");
    exit(1);
}
if (!Uuid::isValid($variantId)) {
    fwrite(STDERR, "Usage: php bin/seed-demo-stock.php <tenantId> <vehicleVariantId-UUID>\n");
    exit(1);
}

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

$namespace = Uuid::fromString('c7f9c52a-55f0-4a8a-9baf-cc8e4d6e0f00');
$db->transactional(function () use ($db, $tenantId, $variantId, $namespace): void {
    $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);

    for ($index = 1; $index <= 3; ++$index) {
        $unitId = Uuid::v5($namespace, $tenantId . ':' . $variantId . ':' . $index)->toRfc4122();
        $vin = 'AFDEMO' . strtoupper(substr(str_replace('-', '', $unitId), 0, 11));

        $db->executeStatement(
            <<<'SQL'
                INSERT INTO vehicle_stock_units (
                    tenant_id, vehicle_unit_id, vehicle_variant_id, vin, status
                ) VALUES (
                    :tenant, :unit, :variant, :vin, 'available'
                )
                ON CONFLICT (tenant_id, vehicle_unit_id) DO NOTHING
                SQL,
            [
                'tenant' => $tenantId,
                'unit' => $unitId,
                'variant' => $variantId,
                'vin' => $vin,
            ],
        );
    }
});

fwrite(STDOUT, sprintf("Seeded (or kept) 3 demo physical units for tenant %s and variant %s.\n", $tenantId, $variantId));
