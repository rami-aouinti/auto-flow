<?php

declare(strict_types=1);

namespace App\Application\GetPayment;

use App\Application\PaymentNotFoundException;
use App\Application\Port\PaymentRepositoryInterface;

final readonly class GetPaymentHandler
{
    public function __construct(private PaymentRepositoryInterface $payments)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(string $tenantId, string $paymentId): array
    {
        $payment = $this->payments->findByTenantAndId($tenantId, $paymentId);

        if ($payment === null) {
            throw new PaymentNotFoundException();
        }

        return $payment;
    }

    /**
     * @return array{items: list<array<string,mixed>>, total: int, limit: int, offset: int}
     */

}
