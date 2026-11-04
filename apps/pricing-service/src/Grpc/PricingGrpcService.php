<?php

declare(strict_types=1);

namespace App\Grpc;

use App\Application\CalculateQuote\CalculateQuoteCommand;
use App\Application\CalculateQuote\CalculateQuoteHandler;
use App\Grpc\Generated\Pricing\V1\CalculateQuoteRequest;
use App\Grpc\Generated\Pricing\V1\CalculateQuoteResponse;
use App\Grpc\Generated\Pricing\V1\PricingServiceInterface;
use AutoFlow\CoreBundle\Security\InvalidTokenException;
use AutoFlow\CoreBundle\Security\JwksUnavailableException;
use AutoFlow\CoreBundle\Security\MissingScopeException;
use AutoFlow\CoreBundle\Security\TenantDelegationException;
use AutoFlow\CoreBundle\Transport\Grpc\GrpcRequestAuthenticator;
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
    public function __construct(
        private CalculateQuoteHandler $handler,
        private GrpcRequestAuthenticator $requestAuthenticator,
    ) {
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- gRPC method name is defined by the .proto contract.
    public function CalculateQuote(ContextInterface $ctx, CalculateQuoteRequest $request): CalculateQuoteResponse
    {
        try {
            $principal = $this->requestAuthenticator->authenticate($ctx, 'pricing:quote');
            assert($principal->tenantId !== null);

            $quote = ($this->handler)(new CalculateQuoteCommand(
                tenantId: $principal->tenantId,
                vehicleId: trim($request->getVehicleId()),
                vehiclePrice: trim($request->getVehiclePrice()),
                downPayment: trim($request->getDownPayment()),
                residualValue: trim($request->getResidualValue()),
                currency: trim($request->getCurrency()),
                durationMonths: $request->getDurationMonths(),
                annualRateBps: $request->getAnnualInterestRateBasisPoints(),
            ));
        } catch (JwksUnavailableException $exception) {
            throw GRPCException::create(
                'The authentication service is temporarily unavailable.',
                StatusCode::UNAVAILABLE,
                $exception,
            );
        } catch (InvalidTokenException $exception) {
            throw GRPCException::create(
                'A valid bearer token is required.',
                StatusCode::UNAUTHENTICATED,
                $exception,
            );
        } catch (MissingScopeException | TenantDelegationException $exception) {
            throw GRPCException::create(
                'The caller is not authorized for this pricing operation.',
                StatusCode::PERMISSION_DENIED,
                $exception,
            );
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
        $response->setQuoteId($quote->quoteId);
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
