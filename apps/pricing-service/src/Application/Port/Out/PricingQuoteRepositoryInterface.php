<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

use App\Domain\Model\PricingQuote;

interface PricingQuoteRepositoryInterface
{
    public function save(PricingQuote $quote): void;
}
