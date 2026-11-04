<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

interface QuoteIdGeneratorInterface
{
    public function generate(): string;
}
