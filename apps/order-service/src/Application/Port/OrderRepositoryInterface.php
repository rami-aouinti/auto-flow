<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\OrderStatus;

interface OrderRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findIdempotentCreate(
        string $tenantId,
        string $actor,
        string $idempotencyKey,
        string $offerId,
        string $quoteId,
        string $vehicleId,
    ): ?array;

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $actor, array $input, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function transition(
        string $tenantId,
        string $actor,
        string $orderId,
        OrderStatus $target,
        string $idempotencyKey,
        ?string $reason = null,
    ): array;

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $orderId): array;

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(string $tenantId, int $limit, int $offset): array;

    /** @return list<array<string,mixed>> */
    public function history(string $tenantId, string $orderId): array;
}
