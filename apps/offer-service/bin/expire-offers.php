<?php

declare(strict_types=1);

use App\Application\ExpireOffers\ExpireOffersHandler;
use App\Infrastructure\Persistence\PostgresConnection;
use App\Infrastructure\Persistence\PostgresOfferRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('OFFER_DATABASE_URL');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new RuntimeException('OFFER_DATABASE_URL is required.');
}

$handler = new ExpireOffersHandler(
    new PostgresOfferRepository(new PostgresConnection($databaseUrl)),
);

$runOnce = in_array('--once', $argv, true);
error_log('[Offer Expiration] Worker started' . ($runOnce ? ' (one-shot).' : '.'));

do {
    try {
        $count = $handler(new DateTimeImmutable(), 100, 100);

        if ($count > 0) {
            error_log(sprintf(
                '[Offer Expiration] Expired %d offer(s).',
                $count,
            ));
        }
    } catch (Throwable $exception) {
        error_log('[Offer Expiration] Worker iteration failed: ' . $exception->getMessage());

        // CronJob must see a failure to apply Kubernetes Job retry policy.
        if ($runOnce) {
            exit(1);
        }
    }

    if ($runOnce) {
        break;
    }

    sleep(30);
} while (true);
