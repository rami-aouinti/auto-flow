<?php

declare(strict_types=1);

namespace App\Application\ListPayments;

use App\Application\Port\PaymentRepositoryInterface;
use DomainException;

final readonly class ListPaymentsHandler
{
    public function __construct(private PaymentRepositoryInterface $payments)
    {
    }

    /**
     * @return array{items: list<array<string,mixed>>, total: int, limit: int, offset: int}
     */
    public function __invoke(string $tenantId, int $limit = 20, int $offset = 0): array
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
