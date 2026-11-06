<?php

declare(strict_types=1);

namespace App\\Infrastructure\\Persistence\\Doctrine;

use App\\Application\\IdempotencyConflictException;
use App\\Application\\OrderNotFoundException;
use App\\Application\\Port\\OrderRepositoryInterface;
use App\\Domain\\Order;
use App\\Domain\\OrderStatus;
use Doctrine\\DBAL\\Connection;
use DomainException;
use OpenTelemetry\\API\\Trace\\Propagation\\TraceContextPropagator;
use Symfony\\Component\\Uid\\Uuid;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
final readonly class DoctrineOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $actor, array $input, string $idempotencyKey): array
    {
        $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return $this->connection->transactional(
            function (Connection $db) use ($tenantId, $actor, $input, $idempotencyKey, $requestHash): array {
                $this->setTenant($db, $tenantId);
                $db->executeQuery(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                    ['lock_key' => $tenantId . ':' . $actor . ':' . $idempotencyKey],
                );
                $existing = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT request_hash, response_json
                        FROM order_idempotency
                        WHERE tenant_id = :tenant AND actor = :actor AND idempotency_key = :key
                        SQL,
                    ['tenant' => $tenantId, 'actor' => $actor, 'key' => $idempotencyKey],
                );
                if (is_array($existing)) {
                    if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                        throw new IdempotencyConflictException('Idempotency-Key was already used with a different request body.');
                    }
                    return $this->decodeObject($existing['response_json']);
                }

                $order = new Order(
                    Uuid::v7()->toRfc4122(), $tenantId, $actor,
                    $input['offerId'], $input['quoteId'], $input['vehicleId'],
                    $input['offerSnapshot'], $input['quoteSnapshot'], $input['vehicleSnapshot'],
                );
                $now = $this->now();
                $db->executeStatement(
                    <<<'SQL'
                        INSERT INTO orders (
                            tenant_id, order_id, created_by, offer_id, quote_id, vehicle_id, status,
                            offer_snapshot, quote_snapshot, vehicle_snapshot, created_at, updated_at
                        ) VALUES (
                            :tenant, :id, :actor, :offer, :quote, :vehicle, :status,
                            CAST(:offer_snapshot AS JSONB), CAST(:quote_snapshot AS JSONB),
                            CAST(:vehicle_snapshot AS JSONB), :now, :now
                        )
                        SQL,
                    [
                        'tenant' => $tenantId, 'id' => $order->id, 'actor' => $actor,
                        'offer' => $order->offerId, 'quote' => $order->quoteId, 'vehicle' => $order->vehicleId,
                        'status' => $order->status->value,
                        'offer_snapshot' => json_encode($order->offerSnapshot, JSON_THROW_ON_ERROR),
                        'quote_snapshot' => json_encode($order->quoteSnapshot, JSON_THROW_ON_ERROR),
                        'vehicle_snapshot' => json_encode($order->vehicleSnapshot, JSON_THROW_ON_ERROR),
                        'now' => $now,
                    ],
                );
                $this->recordHistory($db, $tenantId, $order->id, null, OrderStatus::DRAFT, $actor, null);
                $this->writeOutbox($db, $tenantId, $order->id, 'OrderCreated', [
                    'orderId' => $order->id,
                    'offerId' => $order->offerId,
                    'quoteId' => $order->quoteId,
                    'vehicleId' => $order->vehicleId,
                ]);
                $response = $this->findInTransaction($db, $tenantId, $order->id);
                $db->executeStatement(
                    <<<'SQL'
                        INSERT INTO order_idempotency (
                            tenant_id, actor, idempotency_key, request_hash, order_id, response_json, created_at
                        ) VALUES (:tenant, :actor, :key, :hash, :id, CAST(:response AS JSONB), :now)
                        SQL,
                    [
                        'tenant' => $tenantId,
                        'actor' => $actor,
                        'key' => $idempotencyKey,
                        'hash' => $requestHash,
                        'id' => $order->id,
                     'response' => json_encode($response, JSON_THROW_ON_ERROR), 'now' => $now],
                );
                return $response;
        });
    }

    /** @return array<string, mixed> */
    public function transition(
        string $tenantId,
        string $actor,
        string $orderId,
        OrderStatus $target,
        ?string $reason = null,
    ): array {
        return $this->connection->transactional(
            function (Connection $db) use ($tenantId, $actor, $orderId, $target, $reason): array {
                $this->setTenant($db, $tenantId);
                $row = $db->fetchAssociative(
                    'SELECT * FROM orders WHERE tenant_id = :tenant AND order_id = :id FOR UPDATE',
                    ['tenant' => $tenantId, 'id' => $orderId],
                );
                if (!is_array($row)) {
                    throw new OrderNotFoundException('Order not found.');
                }
                $from = OrderStatus::from((string) $row['status']);
                $order = new Order(
                    (string) $row['order_id'], $tenantId, (string) $row['created_by'],
                    (string) $row['offer_id'], (string) $row['quote_id'], (string) $row['vehicle_id'],
                    $this->decodeObject($row['offer_snapshot']), $this->decodeObject($row['quote_snapshot']),
                    $this->decodeObject($row['vehicle_snapshot']), $from,
                    $row['submitted_at'] === null ? null : new \\DateTimeImmutable((string) $row['submitted_at']),
                    $row['decided_at'] === null ? null : new \\DateTimeImmutable((string) $row['decided_at']),
                    $row['decision_reason'] === null ? null : (string) $row['decision_reason'],
                );
                $order->transitionTo($target, $reason);
                $now = $this->now();
                $db->executeStatement(
                    <<<'SQL'
                        UPDATE orders
                        SET status = :status, updated_at = :now, submitted_at = :submitted,
                            decided_at = :decided, decision_reason = :reason
                        WHERE tenant_id = :tenant AND order_id = :id
                        SQL,
                    [
                        'status' => $target->value, 'now' => $now,
                        'submitted' => $order->submittedAt?->format('Y-m-d H:i:sP'),
                        'decided' => $order->decidedAt?->format('Y-m-d H:i:sP'),
                        'reason' => $order->decisionReason, 'tenant' => $tenantId, 'id' => $orderId,
                    ],
                );
                $this->recordHistory($db, $tenantId, $orderId, $from, $target, $actor, $reason);
                $event = 'Order' . str_replace(' ', '', ucwords(str_replace('_', ' ', $target->value)));
                $this->writeOutbox($db, $tenantId, $orderId, $event, [
                    'orderId' => $orderId, 'previousStatus' => $from->value, 'status' => $target->value,
                ]);
                return $this->findInTransaction($db, $tenantId, $orderId);
        });
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $orderId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($tenantId, $orderId): array {
            $this->setTenant($db, $tenantId);
            $row = $db->fetchAssociative(
                'SELECT * FROM orders WHERE tenant_id = :tenant AND order_id = :id',
                ['tenant' => $tenantId, 'id' => $orderId],
            );
            if (!is_array($row)) {
                throw new OrderNotFoundException('Order not found.');
            }
            return $this->map($row);
        });
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(string $tenantId, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new DomainException('Limit must be between 1 and 100 and offset must be non-negative.');
        }
        return $this->connection->transactional(function (Connection $db) use ($tenantId, $limit, $offset): array {
            $this->setTenant($db, $tenantId);
            $items = $db->fetchAllAssociative(
                'SELECT * FROM orders WHERE tenant_id = :tenant ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
                ['tenant' => $tenantId, 'limit' => $limit, 'offset' => $offset],
                [
                    'limit' => \\Doctrine\\DBAL\\ParameterType::INTEGER,
                    'offset' => \\Doctrine\\DBAL\\ParameterType::INTEGER,
                ],
            );
            $total = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM orders WHERE tenant_id = :tenant',
                ['tenant' => $tenantId],
            );
            return ['items' => array_map($this->map(...), $items), 'total' => $total];
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(string $tenantId, string $orderId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($tenantId, $orderId): array {
            $this->setTenant($db, $tenantId);
            if (!$db->fetchOne(
                'SELECT 1 FROM orders WHERE tenant_id = :tenant AND order_id = :id',
                ['tenant' => $tenantId, 'id' => $orderId],
            )) {
                throw new OrderNotFoundException('Order not found.');
            }
            return $db->fetchAllAssociative(
                <<<'SQL'
                    SELECT from_status, to_status, actor, reason, occurred_at
                    FROM order_history
                    WHERE tenant_id = :tenant AND order_id = :id
                    ORDER BY occurred_at ASC, history_id ASC
                    SQL,
                ['tenant' => $tenantId, 'id' => $orderId],
            );
        });
    }

    private function setTenant(Connection $db, string $tenantId): void
    {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
    }

    private function findInTransaction(Connection $db, string $tenantId, string $orderId): array
    {
        $row = $db->fetchAssociative(
            'SELECT * FROM orders WHERE tenant_id = :tenant AND order_id = :id',
            ['tenant' => $tenantId, 'id' => $orderId],
        );
        if (!is_array($row)) {
            throw new OrderNotFoundException('Order not found after persistence.');
        }
        return $this->map($row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function map(array $row): array
    {
        return [
            'orderId' => $row['order_id'], 'tenantId' => $row['tenant_id'], 'createdBy' => $row['created_by'],
            'offerId' => $row['offer_id'], 'quoteId' => $row['quote_id'], 'vehicleId' => $row['vehicle_id'],
            'status' => $row['status'], 'offerSnapshot' => $this->decodeObject($row['offer_snapshot']),
            'quoteSnapshot' => $this->decodeObject($row['quote_snapshot']),
            'vehicleSnapshot' => $this->decodeObject($row['vehicle_snapshot']),
            'decisionReason' => $row['decision_reason'],
            'createdAt' => (new \\DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            'updatedAt' => (new \\DateTimeImmutable((string) $row['updated_at']))->format(DATE_ATOM),
            'submittedAt' => $row['submitted_at'] === null
                ? null
                : (new \\DateTimeImmutable((string) $row['submitted_at']))->format(DATE_ATOM),
            'decidedAt' => $row['decided_at'] === null
                ? null
                : (new \\DateTimeImmutable((string) $row['decided_at']))->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        $decoded = is_array($value)
            ? $value
            : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \\UnexpectedValueException('Stored order snapshot must be a JSON object.');
        }
        return $decoded;
    }

    private function recordHistory(
        Connection $db,
        string $tenantId,
        string $orderId,
        ?OrderStatus $from,
        OrderStatus $to,
        string $actor,
        ?string $reason,
    ): void
    {
        $db->executeStatement(
            'INSERT INTO order_history (tenant_id, order_id, from_status, to_status, actor, reason, occurred_at)
             VALUES (:tenant, :id, :from_status, :to_status, :actor, :reason, :occurred)',
            ['tenant' => $tenantId, 'id' => $orderId, 'from_status' => $from?->value,
             'to_status' => $to->value, 'actor' => $actor, 'reason' => $reason, 'occurred' => $this->now()],
        );
    }

    /** @param array<string,mixed> $payload */
    private function writeOutbox(
        Connection $db,
        string $tenantId,
        string $orderId,
        string $eventType,
        array $payload,
    ): void
    {
        $eventId = Uuid::v7()->toRfc4122();
        $traceContext = [];
        TraceContextPropagator::getInstance()->inject($traceContext);
        $occurredAt = (new \\DateTimeImmutable('now', new \\DateTimeZone('UTC')))->format(DATE_ATOM);
        $envelope = [
            'eventId' => $eventId, 'eventType' => $eventType, 'schemaVersion' => 1,
            'producer' => 'order-service', 'aggregate' => ['type' => 'Order', 'id' => $orderId],
            'tenantId' => $tenantId, 'occurredAt' => $occurredAt,
            'traceContext' => $traceContext, 'data' => $payload,
        ];
        $db->executeStatement(
            'INSERT INTO order_outbox (event_id, tenant_id, order_id, event_type, payload, occurred_at)
             VALUES (:event, :tenant, :order, :type, CAST(:payload AS JSONB), :occurred)',
            ['event' => $eventId, 'tenant' => $tenantId, 'order' => $orderId, 'type' => $eventType,
             'payload' => json_encode($envelope, JSON_THROW_ON_ERROR), 'occurred' => $occurredAt],
        );
    }

    private function now(): string
    {
        return (new \\DateTimeImmutable('now', new \\DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
    }
}
