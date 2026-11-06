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
    public function list(string $tenantId, int $limit = 20, int $offset = 0): array
    {
        if (trim($tenantId) === '') {
            throw new DomainException('Tenant context is required.');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new DomainException('Limit must be between 1 and 100 and offset must be non-negative.');
        }

        $result = $this->payments->listByTenant($tenantId, $limit, $offset);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

}
