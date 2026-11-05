<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class CatalogPostgresIntegrationTest extends TestCase
{
    public function testDemoCatalogueIsAvailableInPostgres(): void
    {
        $url = getenv('CATALOG_TEST_DATABASE_URL');

        if (!is_string($url) || $url === '') {
            self::markTestSkipped('CATALOG_TEST_DATABASE_URL is not configured.');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
            self::fail('CATALOG_TEST_DATABASE_URL is invalid.');
        }

        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $parts['host'],
                $parts['port'] ?? 5432,
                ltrim($parts['path'], '/'),
            ),
            rawurldecode($parts['user'] ?? ''),
            rawurldecode($parts['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $countStatement = $pdo->query(
            "SELECT COUNT(*) FROM catalog_vehicle_variants WHERE status = 'published'",
        );

        self::assertNotFalse($countStatement);
        self::assertGreaterThanOrEqual(7, (int) $countStatement->fetchColumn());

        $invalidStatement = $pdo->query(
            'SELECT COUNT(*) FROM catalog_vehicle_variants WHERE power_kw < 0 OR seats < 1',
        );

        self::assertNotFalse($invalidStatement);
        self::assertSame(0, (int) $invalidStatement->fetchColumn());
    }
}
