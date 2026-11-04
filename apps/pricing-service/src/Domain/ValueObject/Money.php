<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
    }

    public static function fromDecimal(string $amount, string $currency): self
    {
        $currency = strtoupper($currency);

        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO code.');
        }

        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Money amount must be a non-negative decimal with at most two fractional digits.');
        }

        $whole = (int) $matches[1];
        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;

        if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) {
            throw new InvalidArgumentException('Money amount exceeds the supported range.');
        }

        return new self(($whole * 100) + $fraction, $currency);
    }

    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        $currency = strtoupper($currency);

        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO code.');
        }

        return new self($minorUnits, $currency);
    }

    public function toDecimal(): string
    {
        return sprintf('%d.%02d', intdiv($this->minorUnits, 100), $this->minorUnits % 100);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > $this->minorUnits) {
            throw new InvalidArgumentException('Money subtraction cannot produce a negative amount.');
        }

        return self::fromMinorUnits($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > PHP_INT_MAX - $this->minorUnits) {
            throw new InvalidArgumentException('Money addition exceeds the supported range.');
        }

        return self::fromMinorUnits($this->minorUnits + $other->minorUnits, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money values must use the same currency.');
        }
    }
}
