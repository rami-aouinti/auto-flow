<?php

declare(strict_types=1);

namespace App\Application\CalculateQuote;

final readonly class CalculateQuoteCommand
{
    public function __construct(
        public string $vehicleId,
        public string $vehiclePrice,
        public string $downPayment,
        public string $residualValue,
        public string $currency,
        public int $durationMonths,
        public int $annualRateBps,
    ) {
    }
}
