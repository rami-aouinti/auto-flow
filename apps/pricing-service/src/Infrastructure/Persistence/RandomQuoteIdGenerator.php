<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\Out\QuoteIdGeneratorInterface;

final class RandomQuoteIdGenerator implements QuoteIdGeneratorInterface
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
