<?php

declare(strict_types=1);

namespace App\Application\GetOffer;

use App\Domain\Entity\Offer;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;

final readonly class GetOfferHandler
{
    public function __construct(private OfferRepositoryInterface $offerRepository)
    {
    }

    public function __invoke(OfferId $offerId, string $tenantId, string $ownerId): ?Offer
    {
        return $this->offerRepository->findById($offerId, $tenantId, $ownerId);
    }
}
