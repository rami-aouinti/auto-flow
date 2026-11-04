<?php

declare(strict_types=1);

namespace App\Application\CreateOffer;

use App\Application\DTO\PricingQuoteRequest;
use App\Application\Port\Out\PricingGatewayInterface;
use App\Domain\Entity\Offer;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;

final readonly class CreateOfferHandler
{
    public function __construct(
        private PricingGatewayInterface $pricingGateway,
        private OfferRepositoryInterface $offerRepository,
    ) {
    }

    public function __invoke(CreateOfferCommand $command): Offer
    {
        $snapshot = $this->pricingGateway->createQuote(
            new PricingQuoteRequest(
                tenantId: $command->tenantId,
                vehicleId: $command->vehicleId,
                vehiclePrice: $command->vehiclePrice,
                downPayment: $command->downPayment,
                residualValue: $command->residualValue,
                currency: $command->currency,
                durationMonths: $command->durationMonths,
                annualInterestRateBasisPoints: $command->annualInterestRateBasisPoints,
            ),
            $command->subjectAccessToken,
        );

        if (
            $snapshot->vehicleId !== $command->vehicleId
            || $snapshot->currency !== strtoupper($command->currency)
            || $snapshot->durationMonths !== $command->durationMonths
            || $snapshot->annualInterestRateBasisPoints !== $command->annualInterestRateBasisPoints
        ) {
            throw new UnexpectedValueException('Pricing returned a quote that does not match the requested vehicle or terms.');
        }

        $offer = Offer::create(
            id: OfferId::generate(),
            tenantId: $command->tenantId,
            createdBy: $command->createdBy,
            pricingSnapshot: $snapshot,
            createdAt: $command->createdAt,
            expiresAt: $command->expiresAt,
        );

        $this->offerRepository->save($offer);

        return $offer;
    }
}
