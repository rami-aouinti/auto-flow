<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ReservationPersistenceTest extends TestCase
{
    private PDO $runtime;
    private PDO $worker;
    private PDO $publisher;

    protected function setUp(): void
    {
        $runtimeUrl = getenv('RESERVATION_TEST_DATABASE_URL');
        $workerUrl = getenv('RESERVATION_WORKER_TEST_DATABASE_URL');
        $publisherUrl = getenv('RESERVATION_PUBLISHER_TEST_DATABASE_URL');

        if (!$runtimeUrl || !$workerUrl || !$publisherUrl) {
            self::markTestSkipped('Reservation PostgreSQL integration URLs are not configured.');
        }

        $this->runtime = $this->connect($runtimeUrl);
        $this->worker = $this->connect($workerUrl);
        $this->publisher = $this->connect($publisherUrl);
    }

    public function testTenantRlsAndReservationRequiresAvailablePhysicalStock(): void
    {
        $tenantA = 'reservation-test-a-' . bin2hex(random_bytes(4));
        $tenantB = 'reservation-test-b-' . bin2hex(random_bytes(4));
        $unitId = $this->uuid();
        $reservationId = $this->uuid();

        $this->setTenant($this->runtime, $tenantA);
        $variantId = $this->uuid();
        $this->insertStock($this->runtime, $tenantA, $unitId, $variantId);
        $this->insertHeld($this->runtime, $tenantA, $reservationId, $unitId, $variantId, $this->uuid());

        self::assertSame(1, (int) $this->runtime->query('SELECT COUNT(*) FROM vehicle_reservations')->fetchColumn());

        $this->setTenant($this->runtime, $tenantB);
        self::assertSame(0, (int) $this->runtime->query('SELECT COUNT(*) FROM vehicle_reservations')->fetchColumn());

        $this->expectException(PDOException::class);
        $this->insertHeld($this->runtime, $tenantB, $this->uuid(), $unitId, $this->uuid(), $this->uuid());
    }

    public function testInventoryProjectionIsIdempotentVersionedAndDoesNotReopenBlockedStock(): void
    {
        $tenant = 'reservation-inventory-test-' . bin2hex(random_bytes(4));
        $unitId = $this->uuid();
        $variantId = $this->uuid();
        $reservationId = $this->uuid();
        $orderId = $this->uuid();
        $vin = '1HGCM82633A004352';
        $createdEvent = $this->uuid();
        $blockedEvent = $this->uuid();

        $apply = $this->worker->prepare(
            'SELECT public.vehicle_stock_apply_inventory_event(:tenant,:event,:unit,:variant,:vin,:status,:version)::int'
        );
        $apply->execute([
            'tenant' => $tenant,
            'event' => $createdEvent,
            'unit' => $unitId,
            'variant' => $variantId,
            'vin' => $vin,
            'status' => 'available',
            'version' => 1,
        ]);
        self::assertSame(1, (int) $apply->fetchColumn());

        // Re-delivery of the same event is acknowledged but not applied twice.
        $apply->execute([
            'tenant' => $tenant,
            'event' => $createdEvent,
            'unit' => $unitId,
            'variant' => $variantId,
            'vin' => $vin,
            'status' => 'available',
            'version' => 1,
        ]);
        self::assertSame(0, (int) $apply->fetchColumn());

        $this->setTenant($this->runtime, $tenant);
        $this->insertHeld($this->runtime, $tenant, $reservationId, $unitId, $variantId, $orderId);

        // Inventory can change operational status while Reservation still owns a hold.
        $apply->execute([
            'tenant' => $tenant,
            'event' => $blockedEvent,
            'unit' => $unitId,
            'variant' => $variantId,
            'vin' => $vin,
            'status' => 'blocked',
            'version' => 2,
        ]);
        self::assertTrue((bool) $apply->fetchColumn());

        $stock = $this->runtime->prepare(
            'SELECT status, inventory_status, inventory_version FROM vehicle_stock_units WHERE vehicle_unit_id = :unit'
        );
        $stock->execute(['unit' => $unitId]);
        self::assertSame([
            'status' => 'reserved',
            'inventory_status' => 'blocked',
            'inventory_version' => 2,
        ], $stock->fetch());

        $confirm = $this->runtime->prepare(
            "UPDATE vehicle_reservations SET status = 'confirmed', confirmed_at = CURRENT_TIMESTAMP WHERE reservation_id = :id"
        );
        try {
            $confirm->execute(['id' => $reservationId]);
            self::fail('A reservation backed by blocked Inventory stock must not be confirmed.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('Inventory no longer reports', $exception->getMessage());
        }

        $release = $this->runtime->prepare(
            "UPDATE vehicle_reservations SET status = 'released', released_at = CURRENT_TIMESTAMP WHERE reservation_id = :id"
        );
        $release->execute(['id' => $reservationId]);

        // A delayed older "available" event cannot undo the newer blocked state.
        $apply->execute([
            'tenant' => $tenant,
            'event' => $this->uuid(),
            'unit' => $unitId,
            'variant' => $variantId,
            'vin' => $vin,
            'status' => 'available',
            'version' => 1,
        ]);
        self::assertTrue((bool) $apply->fetchColumn());

        $stock->execute(['unit' => $unitId]);
        self::assertSame([
            'status' => 'unavailable',
            'inventory_status' => 'blocked',
            'inventory_version' => 2,
        ], $stock->fetch());
    }

    public function testExpiryWorkerWritesAuditAndOutboxAndPublisherCanClaim(): void
    {
        $tenant = 'reservation-expiry-test-' . bin2hex(random_bytes(4));
        $reservationId = $this->uuid();

        $this->setTenant($this->runtime, $tenant);
        $unitId = $this->uuid();
        $variantId = $this->uuid();
        $this->insertStock($this->runtime, $tenant, $unitId, $variantId);
        $statement = $this->runtime->prepare(
            "INSERT INTO vehicle_reservations
                (tenant_id, reservation_id, vehicle_unit_id, vehicle_variant_id, order_id, status, expires_at)
             VALUES (:tenant, :id, :unit, :variant, :order, 'held', CURRENT_TIMESTAMP - INTERVAL '1 minute')"
        );
        $statement->execute([
            'tenant' => $tenant,
            'id' => $reservationId,
            'unit' => $unitId,
            'variant' => $variantId,
            'order' => $this->uuid(),
        ]);

        self::assertSame(1, (int) $this->worker->query('SELECT public.vehicle_reservation_expire_due(50)')->fetchColumn());

        $this->setTenant($this->runtime, $tenant);
        $status = $this->runtime->prepare('SELECT status FROM vehicle_reservations WHERE reservation_id = :id');
        $status->execute(['id' => $reservationId]);
        self::assertSame('expired', $status->fetchColumn());

        $stock = $this->runtime->prepare('SELECT status, reservation_id FROM vehicle_stock_units WHERE vehicle_unit_id = :unit');
        $stock->execute(['unit' => $unitId]);
        self::assertSame(['status' => 'available', 'reservation_id' => null], $stock->fetch());

        $audit = $this->runtime->prepare('SELECT COUNT(*) FROM vehicle_reservation_audit_events WHERE reservation_id = :id');
        $audit->execute(['id' => $reservationId]);
        self::assertSame(1, (int) $audit->fetchColumn());

        $claimed = $this->publisher->query('SELECT * FROM public.vehicle_reservation_outbox_claim()')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($claimed);
        self::assertSame('reservation.expired.v1', $claimed['event_type']);

        $mark = $this->publisher->prepare('SELECT public.vehicle_reservation_outbox_mark_published(:tenant, :event)');
        $mark->execute(['tenant' => $tenant, 'event' => $claimed['event_id']]);

        $outbox = $this->runtime->prepare(
            'SELECT COUNT(*) FROM vehicle_reservation_outbox WHERE event_id = :event AND published_at IS NOT NULL'
        );
        $outbox->execute(['event' => $claimed['event_id']]);
        self::assertSame(1, (int) $outbox->fetchColumn());
    }

    private function connect(string $url): PDO
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('Invalid PostgreSQL test URL.');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'] ?? '127.0.0.1',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '/autoflow', '/'),
        );

        return new PDO($dsn, rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function setTenant(PDO $connection, string $tenant): void
    {
        $statement = $connection->prepare("SELECT set_config('app.tenant_id', :tenant, false)");
        $statement->execute(['tenant' => $tenant]);
    }

    private function insertStock(PDO $connection, string $tenant, string $unit, string $variant): void
    {
        $statement = $connection->prepare(
            "INSERT INTO vehicle_stock_units (tenant_id, vehicle_unit_id, vehicle_variant_id, status)
             VALUES (:tenant, :unit, :variant, 'available')"
        );
        $statement->execute(['tenant' => $tenant, 'unit' => $unit, 'variant' => $variant]);
    }

    private function insertHeld(PDO $connection, string $tenant, string $id, string $unit, string $variant, string $order): void
    {
        $statement = $connection->prepare(
            "INSERT INTO vehicle_reservations
                (tenant_id, reservation_id, vehicle_unit_id, vehicle_variant_id, order_id, status, expires_at)
             VALUES (:tenant, :id, :unit, :variant, :order, 'held', CURRENT_TIMESTAMP + INTERVAL '1 hour')"
        );
        $statement->execute([
            'tenant' => $tenant,
            'id' => $id,
            'unit' => $unit,
            'variant' => $variant,
            'order' => $order,
        ]);
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 65535), random_int(0, 65535),
            random_int(0, 65535), random_int(0, 4095),
            random_int(0, 16383) | 0x8000,
            random_int(0, 65535), random_int(0, 65535), random_int(0, 65535),
        );
    }
}
