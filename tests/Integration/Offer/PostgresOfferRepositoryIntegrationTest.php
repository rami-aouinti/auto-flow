<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Integration\Offer;

use App\Domain\Entity\Offer;
use App\Domain\Exception\ConcurrentOfferModification;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\PricingSnapshot;
use App\Infrastructure\Persistence\PostgresConnection;
use App\Infrastructure\Persistence\PostgresOfferRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PostgresOfferRepositoryIntegrationTest extends TestCase
{
    private PostgresOfferRepository $repository;
    private \PDO $connection;

    protected function setUp(): void
    {
        $databaseUrl = getenv('OFFER_TEST_DATABASE_URL');
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            self::markTestSkipped('Set OFFER_TEST_DATABASE_URL to run PostgreSQL Offer integration tests.');
        }

        $connection = new PostgresConnection($databaseUrl);
        $this->connection = $connection->get();
        $this->repository = new PostgresOfferRepository($connection);
    }

    public function testOfferAndCreatedEventAreCommittedAndTenantScoped(): void
    {
        $tenantId = 'it-' . bin2hex(random_bytes(8));
        $ownerId = 'it-owner-' . bin2hex(random_bytes(6));
        $offer = $this->createOffer($tenantId, $ownerId, new DateTimeImmutable('+2 days'));

        $this->repository->save($offer);

        self::assertSame([], $offer->domainEvents());
        self::assertNotNull($this->repository->findById($offer->id(), $tenantId, $ownerId));
        self::assertNull($this->repository->findById($offer->id(), $tenantId . '-other', $ownerId));

        $event = $this->connection->prepare(<<<'SQL'
            SELECT event_type, payload
            FROM offer_outbox_events
            WHERE tenant_id = :tenant_id
              AND aggregate_id = :offer_id
            SQL);
        $event->execute(['tenant_id' => $tenantId, 'offer_id' => (string) $offer->id()]);
        $row = $event->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('OfferCreated', $row['event_type']);
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((string) $offer->id(), $payload['data']['offerId']);
    }

    public function testExpirationLocksRowsAndPersistsExpiredEvent(): void
    {
        $tenantId = 'it-' . bin2hex(random_bytes(8));
        $ownerId = 'it-owner-' . bin2hex(random_bytes(6));
        $now = new DateTimeImmutable();
        $offer = $this->createOffer($tenantId, $ownerId, $now->modify('-1 minute'), $now->modify('-2 days'));
        $this->repository->save($offer);

        $tenantIds = $this->repository->findExpiredTenantIds($now, 100);
        self::assertContains($tenantId, $tenantIds);

        self::assertSame(1, $this->repository->expireDueForTenant($tenantId, $now, 100));
        $expired = $this->repository->findById($offer->id(), $tenantId, $ownerId);

        self::assertNotNull($expired);
        self::assertSame('expired', $expired->status()->value);

        $event = $this->connection->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM offer_outbox_events
            WHERE tenant_id = :tenant_id
              AND aggregate_id = :offer_id
              AND event_type = 'OfferExpired'
            SQL);
        $event->execute(['tenant_id' => $tenantId, 'offer_id' => (string) $offer->id()]);
        self::assertSame(1, (int) $event->fetchColumn());
    }

    public function testConcurrentLifecycleUpdateCannotOverwriteNewerStatus(): void
    {
        $tenantId = 'it-' . bin2hex(random_bytes(8));
        $ownerId = 'it-owner-' . bin2hex(random_bytes(6));
        $offer = $this->createOffer($tenantId, $ownerId, new DateTimeImmutable('+2 days'));
        $this->repository->save($offer);

        $firstRead = $this->repository->findById($offer->id(), $tenantId, $ownerId);
        $staleRead = $this->repository->findById($offer->id(), $tenantId, $ownerId);
        self::assertNotNull($firstRead);
        self::assertNotNull($staleRead);

        $firstRead->publish(new DateTimeImmutable('+1 minute'));
        $this->repository->save($firstRead);

        $staleRead->publish(new DateTimeImmutable('+2 minutes'));
        $this->expectException(ConcurrentOfferModification::class);
        $this->repository->save($staleRead);
    }

    private function createOffer(
        string $tenantId,
        string $ownerId,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $createdAt = null,
    ): Offer {
        $createdAt ??= new DateTimeImmutable();

        return Offer::create(
            OfferId::generate(),
            $tenantId,
            $ownerId,
            new PricingSnapshot(
                'quote-' . bin2hex(random_bytes(6)),
                'vehicle-456',
                '329.00',
                '15792.00',
                '20792.00',
                '8000.00',
                'EUR',
                48,
                425,
                'pricing-v1',
            ),
            $createdAt,
            $expiresAt,
        );
    }
}
