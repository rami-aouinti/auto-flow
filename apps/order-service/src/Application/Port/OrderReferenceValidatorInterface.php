<?php

declare(strict_types=1);

namespace App\Application\Port;

interface OrderReferenceValidatorInterface
{
    /**
     * Resolve and verify upstream references using the authenticated caller's delegated token.
     *
     * @return array{offerSnapshot: array<string,mixed>, quoteSnapshot: array<string,mixed>, vehicleSnapshot: array<string,mixed>}
     */
    public function validate(
        string $offerId,
        string $quoteId,
        string $vehicleId,
        string $tenantId,
        string $subjectAccessToken,
    ): array;
}
