<?php

declare(strict_types=1);

namespace App\Application\CreateOrder;

use App\Application\OrderApplicationService;
use App\Application\OrderReferenceValidationException;
use App\Application\OrderUpstreamAuthorizationException;
use App\Application\OrderUpstreamUnavailableException;
use App\Application\Port\OrderReferenceValidatorInterface;
use App\Infrastructure\Observability\OrderMetrics;
use InvalidArgumentException;

final readonly class CreateOrderHandler
{
    public function __construct(
        private OrderApplicationService $orders,
        private OrderReferenceValidatorInterface $referenceValidator,
        private OrderMetrics $metrics,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function __invoke(
        string $tenantId,
        string $actor,
        string $idempotencyKey,
        array $input,
        string $subjectAccessToken,
    ): array {
        foreach (['offerId', 'quoteId', 'vehicleId', 'customerId'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
                throw new InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $field));
            }
        }
        $input['customerId'] = strtolower($input['customerId']);
        if (strlen($input['customerId']) > 36
            || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $input['customerId']) !== 1) {
            throw new InvalidArgumentException('Field "customerId" must be a UUID.');
        }

        $existing = $this->orders->findIdempotentCreate(
            $tenantId,
            $actor,
            $idempotencyKey,
            $input['offerId'],
            $input['quoteId'],
            $input['vehicleId'],
            $input['customerId'],
        );
        if ($existing !== null) {
            $this->metrics->recordCreated();
            return $existing;
        }

        try {
            $verifiedSnapshots = $this->referenceValidator->validate(
                $input['offerId'],
                $input['quoteId'],
                $input['vehicleId'],
                $input['customerId'],
                $tenantId,
                $subjectAccessToken,
            );
        } catch (OrderReferenceValidationException|OrderUpstreamAuthorizationException|OrderUpstreamUnavailableException $exception) {
            // A concurrent first request may have committed while this request was calling upstream.
            $existing = $this->orders->findIdempotentCreate(
                $tenantId,
                $actor,
                $idempotencyKey,
                $input['offerId'],
                $input['quoteId'],
                $input['vehicleId'],
                $input['customerId'],
            );
            if ($existing !== null) {
                $this->metrics->recordCreated();
                return $existing;
            }
            throw $exception;
        }

        // Every snapshot is resolved from its owning service, never accepted from the browser.
        $input['offerSnapshot'] = $verifiedSnapshots['offerSnapshot'];
        $input['quoteSnapshot'] = $verifiedSnapshots['quoteSnapshot'];
        $input['vehicleSnapshot'] = $verifiedSnapshots['vehicleSnapshot'];
        $input['customerSnapshot'] = $verifiedSnapshots['customerSnapshot'];
        $input['customerVersion'] = $verifiedSnapshots['customerVersion'];

        $result = $this->orders->create($tenantId, $actor, $input, $idempotencyKey);
        $this->metrics->recordCreated();

        return $result;
    }
}
