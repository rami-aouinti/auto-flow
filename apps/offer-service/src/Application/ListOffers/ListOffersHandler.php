<?php

declare(strict_types=1);

namespace App\Application\ListOffers;

use App\Domain\Entity\Offer;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferStatus;

final readonly class ListOffersHandler
{
    public function __construct(private OfferRepositoryInterface $offerRepository)
    {
    }

    /**
     * @return array{items: list<Offer>, total: int}
     */
    public function __invoke(
        string $tenantId,
        string $ownerId,
        ?OfferStatus $status,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        return $this->offerRepository->findByOwner(
            $tenantId,
            $ownerId,
            $status,
            $search,
            $limit,
            $offset,
        );
    }
}
