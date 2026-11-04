<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

use App\Domain\Model\PricingQuote;

interface PricingQuoteRepositoryInterface
{
    public function save(PricingQuote $quote): void;

    public function findById(string $tenantId, string $quoteId): ?PricingQuote;

    /**
     * @return list<PricingQuote>
     */
    public function findAll(string $tenantId, int $limit = 50, int $offset = 0): array;

    public function delete(string $tenantId, string $quoteId): bool;
}
