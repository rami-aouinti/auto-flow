<?php

declare(strict_types=1);

/**
 * Loads deterministic, local-only Offer Service demo records.
 *
 * Required environment:
 *   APP_ENV=dev
 *   OFFER_DATABASE_URL=postgresql://autoflow_offer:...@postgres:5432/autoflow
 *   DEMO_USER_SUBJECT=<the Keycloak "sub" claim of the demo user>
 *
 * Optional:
 *   DEMO_TENANT_ID=tenant-demo
 *
 * The script intentionally does not emit Outbox events: these are reference
 * records for Swagger/manual API exploration, not real business transitions.
 */

if ((getenv('APP_ENV') ?: 'prod') !== 'dev') {
    fwrite(STDERR, "Refusing to load demo fixtures unless APP_ENV=dev.\n");
    exit(1);
}

$databaseUrl = getenv('OFFER_DATABASE_URL');
$createdBy = getenv('DEMO_USER_SUBJECT');
$tenantId = getenv('DEMO_TENANT_ID') ?: 'tenant-demo';

if (!is_string($databaseUrl) || $databaseUrl === '') {
    fwrite(STDERR, "OFFER_DATABASE_URL is required.\n");
    exit(1);
}

if (!is_string($createdBy) || trim($createdBy) === '') {
    fwrite(STDERR, "DEMO_USER_SUBJECT is required (Keycloak access-token sub claim).\n");
    exit(1);
}

if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1) {
    fwrite(STDERR, "DEMO_TENANT_ID has an invalid format.\n");
    exit(1);
}

$parts = parse_url($databaseUrl);
if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
    fwrite(STDERR, "OFFER_DATABASE_URL must be a PostgreSQL URL.\n");
    exit(1);
}

$host = $parts['host'] ?? null;
$database = ltrim((string) ($parts['path'] ?? ''), '/');
$username = $parts['user'] ?? null;

if (!is_string($host) || $host === '' || $database === '' || !is_string($username) || $username === '') {
    fwrite(STDERR, "OFFER_DATABASE_URL must define host, database and username.\n");
    exit(1);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s',
    $host,
    is_int($parts['port'] ?? null) ? $parts['port'] : 5432,
    rawurldecode($database),
);

$pdo = new PDO(
    $dsn,
    rawurldecode($username),
    rawurldecode((string) ($parts['pass'] ?? '')),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$format = static fn (DateTimeImmutable $date): string => $date->format('Y-m-d\TH:i:s.uP');

$fixtures = [
    [
        'id' => 'demo-offer-draft-001',
        'vehicleId' => 'vehicle-demo-bmw-i4',
        'quoteId' => 'quote-demo-draft-001',
        'status' => 'draft',
        'createdAt' => $now->modify('-1 day'),
        'expiresAt' => $now->modify('+29 days'),
        'publishedAt' => null,
        'acceptedAt' => null,
        'rejectedAt' => null,
        'expiredAt' => null,
        'monthlyAmount' => '729.90',
        'totalInstallmentsAmount' => '35035.20',
        'totalCostIncludingDownPayment' => '40035.20',
        'residualValue' => '8000.00',
        'vehiclePrice' => '42000.00',
        'downPayment' => '5000.00',
    ],
    [
        'id' => 'demo-offer-published-001',
        'vehicleId' => 'vehicle-demo-tesla-model-3',
        'quoteId' => 'quote-demo-published-001',
        'status' => 'published',
        'createdAt' => $now->modify('-2 days'),
        'expiresAt' => $now->modify('+28 days'),
        'publishedAt' => $now->modify('-1 day'),
        'acceptedAt' => null,
        'rejectedAt' => null,
        'expiredAt' => null,
        'monthlyAmount' => '589.00',
        'totalInstallmentsAmount' => '28272.00',
        'totalCostIncludingDownPayment' => '33272.00',
        'residualValue' => '9500.00',
        'vehiclePrice' => '38990.00',
        'downPayment' => '5000.00',
    ],
    [
        'id' => 'demo-offer-accepted-001',
        'vehicleId' => 'vehicle-demo-volvo-ex30',
        'quoteId' => 'quote-demo-accepted-001',
        'status' => 'accepted',
        'createdAt' => $now->modify('-5 days'),
        'expiresAt' => $now->modify('+25 days'),
        'publishedAt' => $now->modify('-4 days'),
        'acceptedAt' => $now->modify('-3 days'),
        'rejectedAt' => null,
        'expiredAt' => null,
        'monthlyAmount' => '499.00',
        'totalInstallmentsAmount' => '23952.00',
        'totalCostIncludingDownPayment' => '27952.00',
        'residualValue' => '7200.00',
        'vehiclePrice' => '35990.00',
        'downPayment' => '4000.00',
    ],
    [
        'id' => 'demo-offer-rejected-001',
        'vehicleId' => 'vehicle-demo-audi-q4',
        'quoteId' => 'quote-demo-rejected-001',
        'status' => 'rejected',
        'createdAt' => $now->modify('-7 days'),
        'expiresAt' => $now->modify('+23 days'),
        'publishedAt' => $now->modify('-6 days'),
        'acceptedAt' => null,
        'rejectedAt' => $now->modify('-5 days'),
        'expiredAt' => null,
        'monthlyAmount' => '649.00',
        'totalInstallmentsAmount' => '31152.00',
        'totalCostIncludingDownPayment' => '36152.00',
        'residualValue' => '8500.00',
        'vehiclePrice' => '41990.00',
        'downPayment' => '5000.00',
    ],
    [
        'id' => 'demo-offer-expired-001',
        'vehicleId' => 'vehicle-demo-mercedes-eqa',
        'quoteId' => 'quote-demo-expired-001',
        'status' => 'expired',
        'createdAt' => $now->modify('-40 days'),
        'expiresAt' => $now->modify('-10 days'),
        'publishedAt' => $now->modify('-39 days'),
        'acceptedAt' => null,
        'rejectedAt' => null,
        'expiredAt' => $now->modify('-10 days'),
        'monthlyAmount' => '699.00',
        'totalInstallmentsAmount' => '33552.00',
        'totalCostIncludingDownPayment' => '38552.00',
        'residualValue' => '7800.00',
        'vehiclePrice' => '44990.00',
        'downPayment' => '5000.00',
    ],
];

$insert = $pdo->prepare(<<<'SQL'
    INSERT INTO public.offers (
        tenant_id, offer_id, created_by, status, quote_id, pricing_snapshot,
        created_at, expires_at, published_at, accepted_at, rejected_at, expired_at
    ) VALUES (
        :tenant_id, :offer_id, :created_by, :status, :quote_id, CAST(:pricing_snapshot AS JSONB),
        :created_at, :expires_at, :published_at, :accepted_at, :rejected_at, :expired_at
    )
    ON CONFLICT (tenant_id, offer_id) DO UPDATE SET
        created_by = EXCLUDED.created_by,
        status = EXCLUDED.status,
        quote_id = EXCLUDED.quote_id,
        pricing_snapshot = EXCLUDED.pricing_snapshot,
        created_at = EXCLUDED.created_at,
        expires_at = EXCLUDED.expires_at,
        published_at = EXCLUDED.published_at,
        accepted_at = EXCLUDED.accepted_at,
        rejected_at = EXCLUDED.rejected_at,
        expired_at = EXCLUDED.expired_at
    SQL);

$pdo->beginTransaction();

try {
    $tenantStatement = $pdo->prepare("SELECT set_config('app.tenant_id', :tenant_id, true)");
    $tenantStatement->execute(['tenant_id' => $tenantId]);

    foreach ($fixtures as $fixture) {
        $snapshot = [
            'quoteId' => $fixture['quoteId'],
            'vehicleId' => $fixture['vehicleId'],
            'monthlyAmount' => $fixture['monthlyAmount'],
            'totalInstallmentsAmount' => $fixture['totalInstallmentsAmount'],
            'totalCostIncludingDownPayment' => $fixture['totalCostIncludingDownPayment'],
            'residualValue' => $fixture['residualValue'],
            'currency' => 'EUR',
            'durationMonths' => 48,
            'annualInterestRateBasisPoints' => 425,
            'calculationVersion' => 'demo-v1',
        ];

        $insert->execute([
            'tenant_id' => $tenantId,
            'offer_id' => $fixture['id'],
            'created_by' => trim($createdBy),
            'status' => $fixture['status'],
            'quote_id' => $fixture['quoteId'],
            'pricing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => $format($fixture['createdAt']),
            'expires_at' => $format($fixture['expiresAt']),
            'published_at' => $fixture['publishedAt'] instanceof DateTimeImmutable
                ? $format($fixture['publishedAt']) : null,
            'accepted_at' => $fixture['acceptedAt'] instanceof DateTimeImmutable
                ? $format($fixture['acceptedAt']) : null,
            'rejected_at' => $fixture['rejectedAt'] instanceof DateTimeImmutable
                ? $format($fixture['rejectedAt']) : null,
            'expired_at' => $fixture['expiredAt'] instanceof DateTimeImmutable
                ? $format($fixture['expiredAt']) : null,
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}

printf(
    "Loaded %d Offer demo fixtures for tenant '%s' and subject '%s'.\n",
    count($fixtures),
    $tenantId,
    trim($createdBy),
);
foreach ($fixtures as $fixture) {
    printf("%-32s status=%s vehicle=%s\n", $fixture['id'], $fixture['status'], $fixture['vehicleId']);
}
