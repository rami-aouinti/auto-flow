<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\CatalogueId;
use App\Domain\ValueObject\CatalogStatus;
use InvalidArgumentException;

final readonly class Manufacturer
{
    private function __construct(
        public CatalogueId $id,
        public string $code,
        public string $name,
        public CatalogStatus $status,
    ) {
    }

    public static function register(string $code, string $name): self
    {
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/D', $code) !== 1) {
            throw new InvalidArgumentException(
                'Manufacturer code must contain 2-40 uppercase letters, digits, underscores or hyphens.',
            );
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Manufacturer name must contain 1-120 characters.');
        }

        return new self(CatalogueId::generate(), $code, $name, CatalogStatus::DRAFT);
    }

    public function withStatus(CatalogStatus $status): self
    {
        return new self($this->id, $this->code, $this->name, $this->status->transitionTo($status));
    }

    public function equals(self $other): bool
    {
        return $this->id->value === $other->id->value;
    }
}
