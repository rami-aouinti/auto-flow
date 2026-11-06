<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Offer;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\OfferStatus;
use DateTimeImmutable;

interface OfferRepositoryInterface
{
    public function save(Offer $offer): void;

    public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer;

    /**
     * @return array{items: list<Offer>, total: int}
     */
    public function findByOwner(
        string $tenantId,
        string $ownerId,
        ?OfferStatus $status,
        ?string $search,
        int $limit,
        int $offset,
    ): array;

    /**
     * @return list<array{eventId: string, eventType: string, fromStatus: ?string, toStatus: string, actorType: string, actorId: ?string, occurredAt: string, metadata: array<string, mixed>}>|null
     */
    public function findHistoryByOffer(OfferId $id, string $tenantId, string $ownerId): ?array;

    /** @return list<string> */
    public function findExpiredTenantIds(DateTimeImmutable $now, int $limit): array;

    public function expireDueForTenant(string $tenantId, DateTimeImmutable $now, int $limit): int;
}
