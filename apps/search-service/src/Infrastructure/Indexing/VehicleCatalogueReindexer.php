<?php
declare(strict_types=1);

namespace App\Infrastructure\Indexing;

use App\Infrastructure\Elasticsearch\ElasticsearchClient;
use PDO;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class VehicleCatalogueReindexer
{
    public function __construct(
        private PDO $catalogue,
        private HttpClientInterface $http,
        private string $elasticsearchUrl,
        private string $alias,
    ) {
    }

    public function rebuild(): int
    {
        $target = $this->alias . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $indexer = new ElasticsearchClient($this->http, $this->elasticsearchUrl, $target);
        $aliasManager = new ElasticsearchClient($this->http, $this->elasticsearchUrl, $this->alias);
        $indexer->ensureVehicleIndex();

        $sql = <<<'SQL'
            SELECT v.variant_id, v.name, v.fuel_type, v.powertrain, v.body_style,
                   v.transmission, v.power_kw, v.seats, v.doors,
                   v.co2_emission_g_per_km, v.status, v.updated_at,
                   g.name AS generation, g.production_start_year, g.production_end_year,
                   m.name AS model, mf.name AS manufacturer
            FROM catalog_vehicle_variants v
            JOIN catalog_generations g ON g.generation_id = v.generation_id
            JOIN catalog_models m ON m.model_id = g.model_id
            JOIN catalog_manufacturers mf ON mf.manufacturer_id = m.manufacturer_id
            WHERE v.status = 'published'
              AND g.status = 'published'
              AND m.status = 'published'
              AND mf.status = 'published'
            ORDER BY v.variant_id
            SQL;

        $statement = $this->catalogue->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not read published vehicle catalogue rows.');
        }

        $batch = [];
        $total = 0;
        try {
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $document = [
                    'vehicle_id' => $row['variant_id'],
                    'manufacturer' => $row['manufacturer'],
                    'model' => $row['model'],
                    'generation' => $row['generation'],
                    'name' => $row['name'],
                    'search_text' => implode(' ', [
                        $row['manufacturer'], $row['model'], $row['generation'],
                        $row['name'], $row['fuel_type'], $row['body_style'],
                    ]),
                    'fuel_type' => $row['fuel_type'],
                    'powertrain' => $row['powertrain'],
                    'body_style' => $row['body_style'],
                    'transmission' => $row['transmission'],
                    'power_kw' => $row['power_kw'] === null ? null : (int) $row['power_kw'],
                    'seats' => $row['seats'] === null ? null : (int) $row['seats'],
                    'doors' => $row['doors'] === null ? null : (int) $row['doors'],
                    'co2_emission_g_per_km' => $row['co2_emission_g_per_km'] === null ? null : (int) $row['co2_emission_g_per_km'],
                    'production_start_year' => $row['production_start_year'] === null ? null : (int) $row['production_start_year'],
                    'production_end_year' => $row['production_end_year'] === null ? null : (int) $row['production_end_year'],
                    'status' => $row['status'],
                    'updated_at' => $row['updated_at'],
                ];
                $batch[] = ['id' => (string) $row['variant_id'], 'document' => $document];
                if (count($batch) >= 400) {
                    $total += $indexer->bulkIndex($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $total += $indexer->bulkIndex($batch);
            }

            $aliasManager->activateAlias($this->alias, $target);
            return $total;
        } catch (\Throwable $error) {
            try {
                $indexer->deleteIndex($target);
            } catch (\Throwable) {
                // Preserve the original indexing exception.
            }
            throw $error;
        }
    }
}
