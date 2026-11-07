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
        foreach (['offerId', 'quoteId', 'vehicleId'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                throw new InvalidArgumentException(sprintf('Field "%s" must be a string.', $field));
            }
        }

        $existing = $this->orders->findIdempotentCreate(
            $tenantId,
            $actor,
            $idempotencyKey,
            $input['offerId'],
            $input['quoteId'],
            $input['vehicleId'],
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
            );
            if ($existing !== null) {
                $this->metrics->recordCreated();
                return $existing;
            }
            throw $exception;
        }

        // Snapshots are resolved from authoritative services, never accepted from the browser.
        $input['offerSnapshot'] = $verifiedSnapshots['offerSnapshot'];
        $input['quoteSnapshot'] = $verifiedSnapshots['quoteSnapshot'];
        $input['vehicleSnapshot'] = $verifiedSnapshots['vehicleSnapshot'];

        $result = $this->orders->create($tenantId, $actor, $input, $idempotencyKey);
        $this->metrics->recordCreated();

        return $result;
    }
}
