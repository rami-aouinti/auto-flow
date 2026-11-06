<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class PricingQuoteProxyResponse
{
    public function __construct(
        public int $statusCode,
        public string $content,
    ) {
    }
}
