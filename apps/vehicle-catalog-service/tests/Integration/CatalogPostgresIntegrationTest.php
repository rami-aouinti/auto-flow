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
        self::assertIsArray($parts);
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            rawurldecode($parts['user'] ?? ''),
            rawurldecode($parts['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $count = (int) $pdo->query("SELECT COUNT(*) FROM catalog_vehicle_variants WHERE status='published'")->fetchColumn();
        self::assertGreaterThanOrEqual(7, $count);
        $invalid = $pdo->query("SELECT COUNT(*) FROM catalog_vehicle_variants WHERE power_kw < 0 OR seats < 1")->fetchColumn();
        self::assertSame(0, (int) $invalid);
    }
}