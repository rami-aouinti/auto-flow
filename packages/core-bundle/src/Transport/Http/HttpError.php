<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Http;

final readonly class HttpError
{
    public function __construct(
        public int $status,
        public string $title,
        public string $detail,
    ) {
    }
}
