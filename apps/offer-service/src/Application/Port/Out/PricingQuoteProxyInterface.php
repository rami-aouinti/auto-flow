<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

use App\Application\DTO\PricingQuoteProxyResponse;

interface PricingQuoteProxyInterface
{
    /** @param array<string, scalar|null> $payload */
    public function requestQuote(
        string $vehicleId,
        array $payload,
        string $subjectAccessToken,
        string $tenantId,
    ): PricingQuoteProxyResponse;
}
