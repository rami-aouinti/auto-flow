<?php

declare(strict_types=1);

namespace App\Application\CalculateQuote;

use App\Application\Port\Out\PricingQuoteRepositoryInterface;
use App\Application\Port\Out\QuoteIdGeneratorInterface;
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
        private PricingQuoteRepositoryInterface $quoteRepository,
        private QuoteIdGeneratorInterface $quoteIdGenerator,
        private PricingPolicyRepositoryInterface $policyRepository,
    ) {
    }

    public function __invoke(CalculateQuoteCommand $command): PricingQuote
    {
        $currency = strtoupper($command->currency);
        $policy = $this->policyRepository->findActive($command->tenantId);

        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal($command->vehiclePrice, $currency),
            downPayment: Money::fromDecimal($command->downPayment, $currency),
            residualValue: Money::fromDecimal($command->residualValue, $currency),
            durationMonths: $command->durationMonths,
            annualInterestRateBasisPoints: $command->annualRateBps,
        );

        if ($policy !== null && !$policy->allows($terms->durationMonths, $terms->annualInterestRateBasisPoints)) {
            throw new \\InvalidArgumentException('Lease terms are outside the active pricing policy.');
        }

        $quote = $this->calculator->calculate(
            $this->quoteIdGenerator->generate(),
            $command->tenantId,
            $command->createdBy,
            $command->vehicleId,
            $terms,
            $policy,
        );

        $this->quoteRepository->save($quote);

        return $quote;
    }
}
