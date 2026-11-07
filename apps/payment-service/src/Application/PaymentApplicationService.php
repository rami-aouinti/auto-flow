<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\ProcessPaymentWebhook\ProcessPaymentWebhookHandler;
use App\Application\TransitionPayment\TransitionPaymentHandler;

/**
 * Compatibility façade for the HTTP controllers.
 *
 * Business operations live in dedicated application use cases.
 */
final readonly class PaymentApplicationService
{
    public function __construct(
        private TransitionPaymentHandler $transitionPayment,
        private ProcessPaymentWebhookHandler $processWebhook,
    ) {
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant,
        string $actor,
        string $paymentId,
        string $key,
        string $operation,
        ?int $refundAmount = null,
    ): array {
        return ($this->transitionPayment)($tenant, $actor, $paymentId, $key, $operation, $refundAmount);
    }

    /** @param array<string,mixed> $event */
    public function applyWebhook(array $event): void
    {
        ($this->processWebhook)($event);
    }
}
