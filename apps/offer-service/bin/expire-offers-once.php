<?php

declare(strict_types=1);

use App\Application\ExpireOffers\ExpireOffersHandler;
use App\Infrastructure\Persistence\PostgresConnection;
use App\Infrastructure\Persistence\PostgresOfferRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('OFFER_DATABASE_URL');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    fwrite(STDERR, "OFFER_DATABASE_URL is required.\n");
    exit(2);
}

try {
    $handler = new ExpireOffersHandler(
        new PostgresOfferRepository(new PostgresConnection($databaseUrl)),
    );
    $count = $handler(new DateTimeImmutable(), 100, 100);
    fwrite(STDOUT, sprintf("Expired %d offer(s).\n", $count));
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Offer expiration failed: %s\n", $exception->getMessage()));
    exit(1);
}
