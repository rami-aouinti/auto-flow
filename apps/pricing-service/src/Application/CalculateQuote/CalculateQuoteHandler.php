<?php

declare(strict_types=1);

namespace App\Application\CalculateQuote;

use App\Domain\Model\PricingQuote;
use App\Domain\Service\LeasePaymentCalculator;
use App\Domain\ValueObject\LeaseTerms;
use App\Domain\ValueObject\Money;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final readonly class CalculateQuoteHandler
{
    public function __construct(
        private LeasePaymentCalculator $calculator,
    ) {
    }

    public function __invoke(CalculateQuoteCommand $command): PricingQuote
    {
        $currency = strtoupper($command->currency);

        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal($command->vehiclePrice, $currency),
            downPayment: Money::fromDecimal($command->downPayment, $currency),
            residualValue: Money::fromDecimal($command->residualValue, $currency),
            durationMonths: $command->durationMonths,
            annualInterestRateBasisPoints: $command->annualRateBps,
        );

        return $this->calculator->calculate($command->vehicleId, $terms);
    }
}
