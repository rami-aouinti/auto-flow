<?php

declare(strict_types=1);

namespace App\Application\GetOfferHistory;

use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;

final readonly class GetOfferHistoryHandler
{
    public function __construct(private OfferRepositoryInterface $offerRepository)
    {
    }

    /**
     * @return list<array{eventId: string, eventType: string, fromStatus: ?string, toStatus: string, actorType: string, actorId: ?string, occurredAt: string, metadata: array<string, mixed>}>|null
     */
    public function __invoke(OfferId $offerId, string $tenantId, string $ownerId): ?array
    {
        return $this->offerRepository->findHistoryByOffer($offerId, $tenantId, $ownerId);
    }
}
