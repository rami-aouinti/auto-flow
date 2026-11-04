<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

use App\Application\DTO\PricingQuoteRequest;
use App\Domain\ValueObject\PricingSnapshot;

interface PricingGatewayInterface
{
    public function createQuote(PricingQuoteRequest $request, string $subjectAccessToken): PricingSnapshot;
}
