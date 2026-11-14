<?php
declare(strict_types=1);

use App\Infrastructure\Indexing\VehicleCatalogueReindexer;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('CATALOG_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    fwrite(STDERR, "CATALOG_DATABASE_URL is required.\n");
    exit(2);
}
$parts = parse_url($databaseUrl);
if (!is_array($parts) || !isset($parts['host'], $parts['path'], $parts['user'])) {
    fwrite(STDERR, "CATALOG_DATABASE_URL is invalid.\n");
    exit(2);
}
$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, ltrim($parts['path'], '/'));
$pdo = new PDO($dsn, rawurldecode($parts['user']), rawurldecode($parts['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$reindexer = new VehicleCatalogueReindexer(
    $pdo,
    HttpClient::create(['timeout' => 30]),
    getenv('ELASTICSEARCH_URL') ?: 'http://elasticsearch:9200',
    getenv('SEARCH_VEHICLE_INDEX') ?: 'autoflow-vehicles',
);
$total = $reindexer->rebuild();
fwrite(STDOUT, sprintf("Indexed %d published vehicle variants.\n", $total));
