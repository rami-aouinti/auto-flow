<?php

declare(strict_types=1);

namespace App\Application\Port;

interface OrderReferenceValidatorInterface
{
    /**
     * Resolve and verify all upstream references using the authenticated caller's delegated token.
     *
     * @return array{
     *   offerSnapshot: array<string,mixed>,
     *   quoteSnapshot: array<string,mixed>,
     *   vehicleSnapshot: array<string,mixed>,
     *   customerSnapshot: array<string,mixed>,
     *   customerVersion: int
     * }
     */
    public function validate(
        string $offerId,
        string $quoteId,
        string $vehicleId,
        string $customerId,
        string $tenantId,
        string $subjectAccessToken,
    ): array;
}
