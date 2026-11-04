<?php

declare(strict_types=1);

namespace App\Grpc;

use App\Application\CalculateQuote\CalculateQuoteCommand;
use App\Application\CalculateQuote\CalculateQuoteHandler;
use App\Grpc\Generated\Pricing\V1\CalculateQuoteRequest;
use App\Grpc\Generated\Pricing\V1\CalculateQuoteResponse;
use App\Grpc\Generated\Pricing\V1\PricingServiceInterface;
use InvalidArgumentException;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
final readonly class PricingGrpcService implements PricingServiceInterface
{
    public function __construct(private CalculateQuoteHandler $handler)
    {
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- gRPC method name is defined by the .proto contract.
    public function CalculateQuote(ContextInterface $ctx, CalculateQuoteRequest $request): CalculateQuoteResponse
    {
        try {
            $quote = ($this->handler)(new CalculateQuoteCommand(
                vehicleId: trim($request->getVehicleId()),
                vehiclePrice: trim($request->getVehiclePrice()),
                downPayment: trim($request->getDownPayment()),
                residualValue: trim($request->getResidualValue()),
                currency: trim($request->getCurrency()),
                durationMonths: $request->getDurationMonths(),
                annualRateBps: $request->getAnnualInterestRateBasisPoints(),
            ));
        } catch (InvalidArgumentException $exception) {
            throw GRPCException::create(
                'The pricing request contains invalid lease terms.',
                StatusCode::INVALID_ARGUMENT,
                $exception,
            );
        } catch (Throwable $exception) {
            throw GRPCException::create(
                'The pricing calculation could not be completed.',
                StatusCode::INTERNAL,
                $exception,
            );
        }

        $response = new CalculateQuoteResponse();
        $response->setVehicleId($quote->vehicleId);
        $response->setMonthlyAmount($quote->monthlyAmount->toDecimal());
        $response->setTotalInstallmentsAmount($quote->totalInstallmentsAmount->toDecimal());
        $response->setTotalCostIncludingDownPayment($quote->totalCostIncludingDownPayment->toDecimal());
        $response->setResidualValue($quote->residualValue->toDecimal());
        $response->setCurrency($quote->monthlyAmount->currency);
        $response->setDurationMonths($quote->durationMonths);
        $response->setAnnualInterestRateBasisPoints($quote->annualInterestRateBasisPoints);
        $response->setCalculationVersion($quote->calculationVersion);

        return $response;
    }
}
