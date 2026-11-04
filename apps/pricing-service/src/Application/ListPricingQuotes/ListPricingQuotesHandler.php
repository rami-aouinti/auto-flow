<?php

declare(strict_types=1);

namespace App\Application\ListPricingQuotes;

use App\Application\Port\Out\PricingQuoteRepositoryInterface;

final readonly class ListPricingQuotesHandler
{
    public function __construct(
        private PricingQuoteRepositoryInterface $quoteRepository,
    ) {
    }

    /**
     * @return list<\App\Domain\Model\PricingQuote>
     */
    public function __invoke(string $tenantId, int $limit = 50, int $offset = 0): array
    {
        return $this->quoteRepository->findAll($tenantId, $limit, $offset);
    }
}
