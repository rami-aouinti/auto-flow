<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class CrmTenantIsolationTest extends TestCase
{
    public function testRuntimeRoleCannotReadAnotherTenantApplications(): void
    {
        $url = getenv('CRM_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('CRM_TEST_DATABASE_URL is not configured.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
            self::fail('CRM_TEST_DATABASE_URL is invalid.');
        }
        $database = ltrim((string) $parts['path'], '/');
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $database);
        $pdo = new PDO($dsn, rawurldecode($parts['user']), rawurldecode($parts['pass']), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->beginTransaction();
        try {
            $bypass = $pdo->query('SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user')->fetchColumn();
            self::assertFalse((bool) $bypass, 'The CRM runtime database role must not bypass row-level security.');

            $applicationId = 'b7e9f3d4-1f6c-4a5a-8f31-9d4e2c6a7b10';
            $customerId = 'c4f0f45a-9f7a-4c87-9db0-9c5db6b7b001';
            $setTenant = $pdo->prepare("SELECT set_config('app.tenant_id', :tenant, true)");
            $setTenant->execute(['tenant' => 'tenant-crm-a']);

            $insert = $pdo->prepare(
                'INSERT INTO crm_applications (tenant_id,application_id,customer_id,created_by,updated_by)
                 VALUES (:tenant,:application,:customer,:actor,:actor)',
            );
            $insert->execute([
                'tenant' => 'tenant-crm-a',
                'application' => $applicationId,
                'customer' => $customerId,
                'actor' => 'crm-rls-test',
            ]);

            $setTenant->execute(['tenant' => 'tenant-crm-b']);
            $visibleToOtherTenant = $pdo->prepare('SELECT COUNT(*) FROM crm_applications WHERE application_id=:id');
            $visibleToOtherTenant->execute(['id' => $applicationId]);
            self::assertSame(0, (int) $visibleToOtherTenant->fetchColumn());

            $setTenant->execute(['tenant' => 'tenant-crm-a']);
            $visibleToOwnerTenant = $pdo->prepare('SELECT COUNT(*) FROM crm_applications WHERE application_id=:id');
            $visibleToOwnerTenant->execute(['id' => $applicationId]);
            self::assertSame(1, (int) $visibleToOwnerTenant->fetchColumn());
        } finally {
            $pdo->rollBack();
        }
    }
}
