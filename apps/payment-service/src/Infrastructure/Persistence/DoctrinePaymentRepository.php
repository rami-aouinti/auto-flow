<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\PaymentRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class DoctrinePaymentRepository implements PaymentRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByTenantAndId(string $tenantId, string $paymentId): ?array
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeQuery(
                "SELECT set_config('app.tenant_id', :tenant, true)",
                ['tenant' => $tenantId],
            );

            $row = $this->connection->fetchAssociative(
                'SELECT payment_id,order_id,provider,provider_payment_id,amount_minor,currency,status,amount_refunded_minor,created_at,updated_at FROM payments WHERE tenant_id=:tenant AND payment_id=:id',
                ['tenant' => $tenantId, 'id' => $paymentId],
            );

            $this->connection->commit();

            return $row === false ? null : $row;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
