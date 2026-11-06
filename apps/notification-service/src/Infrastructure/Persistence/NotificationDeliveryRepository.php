<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Persistence;

use PDO;

final class NotificationDeliveryRepository
{
    public function __construct(private readonly PDO $connection)
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notification_deliveries (
                event_id VARCHAR(64) PRIMARY KEY,
                tenant_id VARCHAR(128) NOT NULL,
                aggregate_id VARCHAR(64) NOT NULL,
                notification_type VARCHAR(80) NOT NULL,
                recipient_email VARCHAR(320) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'processing', 'sent', 'failed', 'dead')),
                attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
                last_error TEXT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                sent_at TIMESTAMPTZ NULL
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_notification_deliveries_status ON notification_deliveries (status, updated_at)');
    }

    public function begin(string $eventId, string $tenantId, string $offerId): bool
    {
        $statement = $this->connection->prepare(<<<'SQL'
            INSERT INTO notification_deliveries (event_id, tenant_id, aggregate_id, notification_type, status)
            VALUES (:event_id, :tenant_id, :aggregate_id, 'offer.published.email', 'processing')
            ON CONFLICT (event_id) DO UPDATE
                SET status = 'processing', updated_at = NOW()
                WHERE notification_deliveries.status <> 'sent'
                  AND notification_deliveries.status <> 'dead'
            SQL);
        $statement->execute([
            'event_id' => $eventId,
            'tenant_id' => $tenantId,
            'aggregate_id' => $offerId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markSent(string $eventId, string $recipient): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
            UPDATE notification_deliveries
            SET status = 'sent', recipient_email = :recipient, sent_at = NOW(),
                updated_at = NOW(), last_error = NULL
            WHERE event_id = :event_id
            SQL);
        $statement->execute(['event_id' => $eventId, 'recipient' => $recipient]);
    }

    public function markFailed(string $eventId, string $error, bool $dead): int
    {
        $statement = $this->connection->prepare(<<<'SQL'
            UPDATE notification_deliveries
            SET status = :status, attempt_count = attempt_count + 1,
                last_error = :error, updated_at = NOW()
            WHERE event_id = :event_id
            RETURNING attempt_count
            SQL);
        $statement->execute([
            'event_id' => $eventId,
            'status' => $dead ? 'dead' : 'failed',
            'error' => mb_substr($error, 0, 2000),
        ]);

        return (int) $statement->fetchColumn();
    }
}