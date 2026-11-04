<?php

declare(strict_types=1);

namespace App\Application\GetPricingQuote;

use App\Application\Port\Out\PricingQuoteRepositoryInterface;
use App\Domain\Model\PricingQuote;

final readonly class GetPricingQuoteHandler
{
    public function __construct(
        private PricingQuoteRepositoryInterface $quoteRepository,
    ) {
    }

    public function __invoke(string $tenantId, string $quoteId, ?string $ownerId): ?PricingQuote
    {
        return $this->quoteRepository->findById($tenantId, $quoteId, $ownerId);
    }
}
