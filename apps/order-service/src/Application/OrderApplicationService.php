<?php

declare(strict_types=1);

namespace App\\Application;

use App\\Application\\Port\\OrderRepositoryInterface;
use App\\Domain\\OrderStatus;
use DomainException;

final readonly class OrderApplicationService
{
    public function __construct(private OrderRepositoryInterface $orders)
    {
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $actor, array $input, string $idempotencyKey): array
    {
        $this->assertIdentifier($tenantId, 'tenantId');
        $this->assertIdentifier($actor, 'actor');
        $this->assertIdentifier($idempotencyKey, 'Idempotency-Key');

        foreach (['offerId', 'quoteId', 'vehicleId', 'offerSnapshot', 'quoteSnapshot', 'vehicleSnapshot'] as $field) {
            if (!array_key_exists($field, $input)) {
                throw new DomainException(sprintf('Missing required field: %s.', $field));
            }
        }
        foreach (['offerSnapshot', 'quoteSnapshot', 'vehicleSnapshot'] as $field) {
            if (!is_array($input[$field]) || $input[$field] === [] || array_is_list($input[$field])) {
                throw new DomainException(sprintf('%s must be a non-empty JSON object.', $field));
            }
        }
        foreach (['offerId', 'quoteId', 'vehicleId'] as $field) {
            if (!is_string($input[$field]) || trim($input[$field]) === '' || strlen($input[$field]) > 128) {
                throw new DomainException(sprintf('%s is invalid.', $field));
            }
        }

        $snapshotSize = strlen(json_encode([
            $input['offerSnapshot'],
            $input['quoteSnapshot'],
            $input['vehicleSnapshot'],
        ], JSON_THROW_ON_ERROR));
        if ($snapshotSize > 65536) {
            throw new DomainException('Combined order snapshots must not exceed 64 KiB.');
        }

        return $this->orders->create($tenantId, $actor, $input, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function transition(
        string $tenantId,
        string $actor,
        string $orderId,
        OrderStatus $target,
        ?string $reason = null,
    ): array {
        $this->assertIdentifier($tenantId, 'tenantId');
        $this->assertIdentifier($actor, 'actor');
        $this->assertIdentifier($orderId, 'orderId');

        return $this->orders->transition($tenantId, $actor, $orderId, $target, $reason);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $orderId): array
    {
        $this->assertIdentifier($tenantId, 'tenantId');
        $this->assertIdentifier($orderId, 'orderId');

        return $this->orders->get($tenantId, $orderId);
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(string $tenantId, int $limit, int $offset): array
    {
        $this->assertIdentifier($tenantId, 'tenantId');

        return $this->orders->list($tenantId, $limit, $offset);
    }

    /** @return list<array<string,mixed>> */
    public function history(string $tenantId, string $orderId): array
    {
        $this->assertIdentifier($tenantId, 'tenantId');
        $this->assertIdentifier($orderId, 'orderId');

        return $this->orders->history($tenantId, $orderId);
    }

    private function assertIdentifier(string $value, string $field): void
    {
        if (
            trim($value) === ''
            || strlen($value) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new DomainException(sprintf('%s is invalid.', $field));
        }
    }
}
