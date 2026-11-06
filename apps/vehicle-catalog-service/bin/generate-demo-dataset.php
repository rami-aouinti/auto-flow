<?php

declare(strict_types=1);

/**
 * Generates deterministic, fictional local catalogue data.
 *
 * Safe to rerun: stable IDs and ON CONFLICT DO NOTHING preserve existing rows.
 * This script is intended for local/demo environments only.
 *
 * Usage:
 *   php bin/generate-demo-dataset.php [manufacturers=30] [models=300] [generations=600] [variants=5000]
 */

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "DATABASE_URL is required.\n");
    exit(1);
}

if ((getenv('APP_ENV') ?: 'dev') === 'prod' && getenv('ALLOW_DEMO_DATA_IN_PROD') !== '1') {
    fwrite(STDERR, "Refusing to generate demo data in prod. Set ALLOW_DEMO_DATA_IN_PROD=1 only for an isolated test environment.\n");
    exit(1);
}

$counts = array_map(static fn (string $value): int => filter_var($value, FILTER_VALIDATE_INT) ?: 0, array_slice($argv, 1));
[$manufacturerCount, $modelCount, $generationCount, $variantCount] = array_pad($counts, 4, 0);
$manufacturerCount = $manufacturerCount ?: 30;
$modelCount = $modelCount ?: 300;
$generationCount = $generationCount ?: 600;
$variantCount = $variantCount ?: 5000;

if ($manufacturerCount < 1 || $manufacturerCount > 100
    || $modelCount < $manufacturerCount || $modelCount > 2000
    || $generationCount < $modelCount || $generationCount > 5000
    || $variantCount < $generationCount || $variantCount > 50000) {
    fwrite(STDERR, "Expected limits: manufacturers 1-100, models >= manufacturers and <= 2000, generations >= models and <= 5000, variants >= generations and <= 50000.\n");
    exit(1);
}

$parts = parse_url($url);
if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
    fwrite(STDERR, "DATABASE_URL must be a PostgreSQL URL.\n");
    exit(1);
}
$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? 'postgres', $parts['port'] ?? 5432, ltrim((string) ($parts['path'] ?? ''), '/'));
$pdo = new PDO($dsn, rawurldecode((string) ($parts['user'] ?? '')), rawurldecode((string) ($parts['pass'] ?? '')), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$brands = [
    ['code' => 'ATLAS', 'name' => 'Atlas Motor Works'],
    ['code' => 'NORTHSTAR', 'name' => 'Northstar Automotive'],
    ['code' => 'RHEIN', 'name' => 'Rhein Mobility'],
    ['code' => 'SOLARIS', 'name' => 'Solaris Vehicles'],
    ['code' => 'PACIFIC', 'name' => 'Pacific Motor Company'],
    ['code' => 'ALPINE', 'name' => 'Alpine Mobility'],
    ['code' => 'ORBIT', 'name' => 'Orbit Automotive'],
    ['code' => 'VANTAGE', 'name' => 'Vantage Motors'],
    ['code' => 'EVERGREEN', 'name' => 'Evergreen Auto'],
    ['code' => 'MONARCH', 'name' => 'Monarch Mobility'],
];
$modelNames = ['Aster','Nova','Summit','Terra','Vector','Pulse','Horizon','Crest','Voyager','Metro','Aero','Pioneer','Lumen','Ranger','Vertex','Comet','Legacy','Eclipse','Canyon','Harbor','Cobalt','Aurora','Meridian','Tempo','Sierra','Atlas','Zephyr','Origin','Trail','Vista'];
$fuels = ['petrol','diesel','electric','hybrid','plug_in_hybrid'];
$powertrains = ['ice','ice','bev','hev','phev'];
$bodies = ['suv','sedan','hatchback','estate','coupe','van'];
$transmissions = ['manual','automatic','single_speed'];

$id = static fn (string $kind, int $number): string => substr(hash('sha256', 'autoflow-demo-'.$kind.'-'.$number), 0, 32);
$manufacturerInsert = $pdo->prepare("INSERT INTO catalog_manufacturers(manufacturer_id,code,name,status) VALUES(:id,:code,:name,'published') ON CONFLICT DO NOTHING");
$modelInsert = $pdo->prepare("INSERT INTO catalog_models(model_id,manufacturer_id,name,normalized_name,status) VALUES(:id,:manufacturer,:name,:normalized,'published') ON CONFLICT DO NOTHING");
$generationInsert = $pdo->prepare("INSERT INTO catalog_generations(generation_id,model_id,name,production_start_year,production_end_year,status) VALUES(:id,:model,:name,:start,NULL,'published') ON CONFLICT DO NOTHING");
$variantInsert = $pdo->prepare(<<<'SQL'
INSERT INTO catalog_vehicle_variants(
 variant_id,generation_id,manufacturer_code,name,fuel_type,powertrain,body_style,transmission,
 power_kw,displacement_cc,seats,doors,curb_weight_kg,length_mm,width_mm,height_mm,co2_emission_g_per_km,media_references,status
) VALUES (
 :id,:generation,:code,:name,:fuel,:powertrain,:body,:transmission,
 :power,:displacement,:seats,:doors,:weight,:length,:width,:height,:co2,'[]'::jsonb,'published'
) ON CONFLICT DO NOTHING
SQL);

$pdo->beginTransaction();
try {
    for ($i = 1; $i <= $manufacturerCount; $i++) {
        $brand = $brands[($i - 1) % count($brands)];
        $suffix = $i > count($brands) ? ' '.str_pad((string) $i, 2, '0', STR_PAD_LEFT) : '';
        $manufacturerInsert->execute(['id' => $id('manufacturer', $i), 'code' => $brand['code'].'-'.$i, 'name' => $brand['name'].$suffix]);
    }

    for ($i = 1; $i <= $modelCount; $i++) {
        $manufacturerNumber = (($i - 1) % $manufacturerCount) + 1;
        $base = $modelNames[($i - 1) % count($modelNames)];
        $name = $base.' '.(100 + intdiv($i - 1, count($modelNames)));
        $modelInsert->execute(['id' => $id('model', $i), 'manufacturer' => $id('manufacturer', $manufacturerNumber), 'name' => $name, 'normalized' => strtolower($name)]);
    }

    for ($i = 1; $i <= $generationCount; $i++) {
        $modelNumber = (($i - 1) % $modelCount) + 1;
        $generationInsert->execute(['id' => $id('generation', $i), 'model' => $id('model', $modelNumber), 'name' => 'Generation '.(1 + intdiv($i - 1, $modelCount)), 'start' => 2010 + ($i % 15)]);
    }

    for ($i = 1; $i <= $variantCount; $i++) {
        $generationNumber = (($i - 1) % $generationCount) + 1;
        $fuelIndex = ($i - 1) % count($fuels);
        $fuel = $fuels[$fuelIndex];
        $powertrain = match ($fuel) { 'petrol', 'diesel' => 'ice', 'electric' => 'bev', 'hybrid' => 'hev', default => 'phev' };
        $body = $bodies[($i - 1) % count($bodies)];
        $transmission = $fuel === 'electric' ? 'single_speed' : $transmissions[($i - 1) % 2];
        $power = 70 + (($i * 17) % 260);
        $variantInsert->execute([
            'id' => $id('variant', $i), 'generation' => $id('generation', $generationNumber),
            'code' => sprintf('DEMO-V-%05d', $i), 'name' => sprintf('Demo Variant %05d · %d kW', $i, $power),
            'fuel' => $fuel, 'powertrain' => $powertrain, 'body' => $body, 'transmission' => $transmission,
            'power' => $power, 'displacement' => in_array($fuel, ['petrol','diesel'], true) ? 1000 + (($i * 37) % 2500) : null,
            'seats' => 5, 'doors' => $body === 'coupe' ? 2 : 5, 'weight' => 1100 + (($i * 13) % 1200),
            'length' => 3900 + (($i * 11) % 1100), 'width' => 1750 + (($i * 7) % 300),
            'height' => 1400 + (($i * 5) % 500), 'co2' => $fuel === 'electric' ? 0 : 80 + (($i * 3) % 150),
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

printf("Catalogue demo dataset ensured: requested manufacturers=%d models=%d generations=%d variants=%d. Existing records were preserved.\n",
    $manufacturerCount, $modelCount, $generationCount, $variantCount);
