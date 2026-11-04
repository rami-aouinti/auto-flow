<?php

declare(strict_types=1);

namespace App\Application\RejectOffer;

use App\Domain\Entity\Offer;
use App\Domain\Exception\OfferNotFound;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;

final readonly class RejectOfferHandler
{
    public function __construct(private OfferRepositoryInterface $offerRepository)
    {
    }

    public function __invoke(OfferId $offerId, string $tenantId, string $ownerId): Offer
    {
        $offer = $this->offerRepository->findById($offerId, $tenantId, $ownerId);

        if ($offer === null) {
            throw new OfferNotFound('Offer was not found.');
        }

        $offer->reject();
        $this->offerRepository->save($offer);

        return $offer;
    }
}
