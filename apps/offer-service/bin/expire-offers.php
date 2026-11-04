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

error_log('[Offer Expiration] Worker started.');

while (true) {
    try {
        $count = $handler(new DateTimeImmutable(), 100, 100);

        if ($count > 0) {
            error_log(sprintf('[Offer Expiration] Expired %d offer(s).', $count));
        }
    } catch (Throwable $exception) {
        error_log('[Offer Expiration] Worker iteration failed: ' . $exception->getMessage());
    }

    sleep(30);
}
