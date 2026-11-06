<?php

declare(strict_types=1);

namespace App\Application\ListPayments;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

final readonly class ListPaymentsHandler
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{items: list<array<string,mixed>>, total: int, limit: int, offset: int} */
    public function __invoke(string $tenantId, int $limit, int $offset, ?string $orderId = null, ?string $status = null): array
    {
        $where = ['tenant_id = :tenant'];
        $params = ['tenant' => $tenantId];
        if ($orderId !== null) {
            $where[] = 'order_id = :orderId';
            $params['orderId'] = $orderId;
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $predicate = implode(' AND ', $where);

        $this->db->beginTransaction();
        try {
            // PostgreSQL RLS uses a transaction-local tenant context. The explicit
            // tenant predicate is retained as defence in depth.
            $this->db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
            $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE ' . $predicate, $params);
            $rows = $this->db->fetchAllAssociative(
                'SELECT payment_id, order_id, provider, amount_minor, amount_refunded_minor, currency, status, created_at, updated_at
                 FROM payments WHERE ' . $predicate . ' ORDER BY created_at DESC, payment_id DESC LIMIT :limit OFFSET :offset',
                $params + ['limit' => $limit, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            throw $exception;
        }

        $items = array_map(static fn (array $row): array => [
            'paymentId' => (string) $row['payment_id'],
            'orderId' => (string) $row['order_id'],
            'provider' => (string) $row['provider'],
            'amountMinor' => (int) $row['amount_minor'],
            'amountRefundedMinor' => (int) $row['amount_refunded_minor'],
            'currency' => (string) $row['currency'],
            'status' => (string) $row['status'],
            'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            'updatedAt' => (new \DateTimeImmutable((string) $row['updated_at']))->format(DATE_ATOM),
        ], $rows);

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }
}
