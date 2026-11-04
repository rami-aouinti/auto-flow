<?php

declare(strict_types=1);

namespace App\Application\CreateOffer;

use DateTimeImmutable;

final readonly class CreateOfferCommand
{
    public function __construct(
        public string $tenantId,
        public string $createdBy,
        public string $subjectAccessToken,
        public string $vehicleId,
        public string $vehiclePrice,
        public string $downPayment,
        public string $residualValue,
        public string $currency,
        public int $durationMonths,
        public int $annualInterestRateBasisPoints,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
