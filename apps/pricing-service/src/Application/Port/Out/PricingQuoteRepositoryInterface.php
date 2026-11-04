<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

use App\Domain\Model\PricingQuote;

interface PricingQuoteRepositoryInterface
{
    public function save(PricingQuote $quote): void;

    /**
     * A null owner ID is reserved for callers authorized to access every quote in the tenant.
     */
    public function findById(string $tenantId, string $quoteId, ?string $ownerId): ?PricingQuote;

    /**
     * @return list<PricingQuote>
     * A null owner ID is reserved for callers authorized to access every quote in the tenant.
     */
    public function findAll(
        string $tenantId,
        ?string $ownerId,
        int $limit = 50,
        int $offset = 0,
    ): array;

    /**
     * A null owner ID is reserved for callers authorized to delete every quote in the tenant.
     */
    public function delete(string $tenantId, string $quoteId, ?string $ownerId): bool;
}
