<?php
declare(strict_types=1);
use App\Infrastructure\Elasticsearch\ElasticsearchClient;
use Symfony\Component\HttpClient\HttpClient;
require dirname(__DIR__) . '/vendor/autoload.php';
$databaseUrl = getenv('CATALOG_DATABASE_URL') ?: '';
if ($databaseUrl === '') { fwrite(STDERR, "CATALOG_DATABASE_URL is required.\n"); exit(2); }
$parts = parse_url($databaseUrl);
if (!is_array($parts) || !isset($parts['host'], $parts['path'], $parts['user'])) { fwrite(STDERR, "CATALOG_DATABASE_URL is invalid.\n"); exit(2); }
$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, ltrim($parts['path'], '/'));
$pdo = new PDO($dsn, rawurldecode($parts['user']), rawurldecode($parts['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$http = HttpClient::create(['timeout' => 30]);
$baseUri = getenv('ELASTICSEARCH_URL') ?: 'http://elasticsearch:9200';
$alias = getenv('SEARCH_VEHICLE_INDEX') ?: 'autoflow-vehicles';
$targetIndex = $alias . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$indexer = new ElasticsearchClient($http, $baseUri, $targetIndex);
$aliasManager = new ElasticsearchClient($http, $baseUri, $alias);
$indexer->ensureVehicleIndex();
$sql = "SELECT v.variant_id, v.name, v.fuel_type, v.powertrain, v.body_style, v.transmission, v.power_kw, v.seats, v.doors, v.co2_emission_g_per_km, v.status, v.updated_at, g.name AS generation, g.production_start_year, g.production_end_year, m.name AS model, mf.name AS manufacturer FROM catalog_vehicle_variants v JOIN catalog_generations g ON g.generation_id=v.generation_id JOIN catalog_models m ON m.model_id=g.model_id JOIN catalog_manufacturers mf ON mf.manufacturer_id=m.manufacturer_id WHERE v.status='published' AND g.status='published' AND m.status='published' AND mf.status='published' ORDER BY v.variant_id";
$rows = $pdo->query($sql);
$batch = []; $total = 0;
try {
    foreach ($rows as $row) {
        $doc = [
            'vehicle_id' => $row['variant_id'], 'manufacturer' => $row['manufacturer'], 'model' => $row['model'],
            'generation' => $row['generation'], 'name' => $row['name'],
            'search_text' => implode(' ', [$row['manufacturer'],$row['model'],$row['generation'],$row['name'],$row['fuel_type'],$row['body_style']]),
            'fuel_type' => $row['fuel_type'], 'powertrain' => $row['powertrain'], 'body_style' => $row['body_style'],
            'transmission' => $row['transmission'], 'power_kw' => $row['power_kw'] === null ? null : (int)$row['power_kw'],
            'seats' => $row['seats'] === null ? null : (int)$row['seats'], 'doors' => $row['doors'] === null ? null : (int)$row['doors'],
            'co2_emission_g_per_km' => $row['co2_emission_g_per_km'] === null ? null : (int)$row['co2_emission_g_per_km'],
            'production_start_year' => $row['production_start_year'] === null ? null : (int)$row['production_start_year'],
            'production_end_year' => $row['production_end_year'] === null ? null : (int)$row['production_end_year'],
            'status' => $row['status'], 'updated_at' => $row['updated_at'],
        ];
        $batch[] = ['id' => $row['variant_id'], 'document' => $doc];
        if (count($batch) >= 400) { $total += $indexer->bulkIndex($batch); $batch = []; }
    }
    if ($batch !== []) $total += $indexer->bulkIndex($batch);
    // Switch the read alias only after every batch has been indexed successfully.
    $aliasManager->activateAlias($alias, $targetIndex);
    fwrite(STDOUT, sprintf("Indexed %d published vehicle variants; active alias %s -> %s.\n", $total, $alias, $targetIndex));
} catch (Throwable $error) {
    try { $indexer->deleteIndex($targetIndex); } catch (Throwable) {}
    throw $error;
}
