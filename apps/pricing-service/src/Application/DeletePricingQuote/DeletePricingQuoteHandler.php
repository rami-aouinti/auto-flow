<?php

declare(strict_types=1);

namespace App\Application\DeletePricingQuote;

use App\Application\Port\Out\PricingQuoteRepositoryInterface;

final readonly class DeletePricingQuoteHandler
{
    public function __construct(
        private PricingQuoteRepositoryInterface $quoteRepository,
    ) {
    }

    public function __invoke(string $tenantId, string $quoteId, ?string $ownerId): bool
    {
        return $this->quoteRepository->delete($tenantId, $quoteId, $ownerId);
    }
}
