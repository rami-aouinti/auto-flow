<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Offer;
use App\Domain\ValueObject\OfferId;

interface OfferRepositoryInterface
{
    public function save(Offer $offer): void;

    public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer;
}
