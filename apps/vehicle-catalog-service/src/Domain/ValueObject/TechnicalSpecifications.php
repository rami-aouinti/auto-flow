<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final readonly class TechnicalSpecifications
{
    private function __construct(
        public ?int $powerKw,
        public ?int $displacementCc,
        public ?int $seats,
        public ?int $doors,
        public ?int $curbWeightKg,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
        public ?int $co2EmissionGPerKm,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::positiveInt($data, 'powerKw', 10000),
            self::positiveInt($data, 'displacementCc', 20000),
            self::boundedInt($data, 'seats', 1, 20),
            self::boundedInt($data, 'doors', 1, 8),
            self::positiveInt($data, 'curbWeightKg', 100000),
            self::positiveInt($data, 'lengthMm', 20000),
            self::positiveInt($data, 'widthMm', 5000),
            self::positiveInt($data, 'heightMm', 5000),
            self::nonNegativeInt($data, 'co2EmissionGPerKm', 2000),
        );
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return [
            'powerKw' => $this->powerKw,
            'displacementCc' => $this->displacementCc,
            'seats' => $this->seats,
            'doors' => $this->doors,
            'curbWeightKg' => $this->curbWeightKg,
            'lengthMm' => $this->lengthMm,
            'widthMm' => $this->widthMm,
            'heightMm' => $this->heightMm,
            'co2EmissionGPerKm' => $this->co2EmissionGPerKm,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function positiveInt(array $data, string $key, int $max): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        $value = filter_var($data[$key], FILTER_VALIDATE_INT);

        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException(
                sprintf('%s must be an integer between 1 and %d.', $key, $max),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nonNegativeInt(array $data, string $key, int $max): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        $value = filter_var($data[$key], FILTER_VALIDATE_INT);

        if (!is_int($value) || $value < 0 || $value > $max) {
            throw new InvalidArgumentException(
                sprintf('%s must be an integer between 0 and %d.', $key, $max),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function boundedInt(array $data, string $key, int $min, int $max): ?int
    {
        $value = self::positiveInt($data, $key, $max);

        if ($value !== null && $value < $min) {
            throw new InvalidArgumentException(
                sprintf('%s must be between %d and %d.', $key, $min, $max),
            );
        }

        return $value;
    }
}
