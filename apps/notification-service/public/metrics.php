<?php

declare(strict_types=1);

$databaseUrl = getenv('NOTIFICATION_DATABASE_URL') ?: 'postgresql://autoflow:autoflow@postgres:5432/autoflow';
$parts = parse_url($databaseUrl);
if (!is_array($parts)) {
    http_response_code(503);
    exit;
}
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? 'postgres', $parts['port'] ?? 5432, ltrim((string) ($parts['path'] ?? '/autoflow'), '/')),
    rawurldecode((string) ($parts['user'] ?? 'autoflow')),
    rawurldecode((string) ($parts['pass'] ?? 'autoflow')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS notification_deliveries (
        event_id VARCHAR(64) PRIMARY KEY,
        tenant_id VARCHAR(128) NOT NULL,
        aggregate_id VARCHAR(64) NOT NULL,
        notification_type VARCHAR(80) NOT NULL,
        recipient_email VARCHAR(320) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        sent_at TIMESTAMPTZ NULL
    )
    SQL);
$rows = $pdo->query("SELECT status, COUNT(*) AS total FROM notification_deliveries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
echo "# HELP autoflow_notification_deliveries_total Number of persisted notification deliveries by state.\n";
echo "# TYPE autoflow_notification_deliveries_total gauge\n";
foreach (['pending', 'processing', 'sent', 'failed', 'dead'] as $status) {
    printf("autoflow_notification_deliveries_total{status=\"%s\"} %d\n", $status, (int) ($rows[$status] ?? 0));
}
