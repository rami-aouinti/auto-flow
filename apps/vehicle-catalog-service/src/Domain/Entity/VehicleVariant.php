<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\CatalogueId;
use App\Domain\ValueObject\CatalogStatus;
use InvalidArgumentException;

final readonly class VehicleVariant
{
    private const FUEL_TYPES = [
        'petrol',
        'diesel',
        'electric',
        'hybrid',
        'plug_in_hybrid',
        'hydrogen',
        'lpg',
        'cng',
        'other',
    ];

    private const POWERTRAINS = ['ice', 'mhev', 'hev', 'phev', 'bev', 'fcev', 'other'];

    private const BODY_STYLES = [
        'suv',
        'sedan',
        'hatchback',
        'estate',
        'coupe',
        'convertible',
        'van',
        'pickup',
        'other',
    ];

    private const TRANSMISSIONS = ['manual', 'automatic', 'single_speed', 'other'];

    /**
     * @param list<array{mediaId: string, usage: string, sortOrder?: int, altText?: string}> $mediaReferences
     */
    private function __construct(
        public CatalogueId $id,
        public CatalogueId $generationId,
        public ?string $manufacturerCode,
        public string $name,
        public string $fuelType,
        public string $powertrain,
        public string $bodyStyle,
        public string $transmission,
        public ?int $powerKw,
        public ?int $displacementCc,
        public ?int $seats,
        public ?int $doors,
        public ?int $curbWeightKg,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
        public ?int $co2EmissionGPerKm,
        public array $mediaReferences,
        public CatalogStatus $status,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function register(array $data): self
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Variant name must contain 1-160 characters.');
        }

        $fuel = self::choice($data, 'fuelType', self::FUEL_TYPES);
        $powertrain = self::choice($data, 'powertrain', self::POWERTRAINS);
        $body = self::choice($data, 'bodyStyle', self::BODY_STYLES);
        $transmission = self::choice($data, 'transmission', self::TRANSMISSIONS);
        $manufacturerCode = isset($data['manufacturerCode'])
            ? strtoupper(trim((string) $data['manufacturerCode']))
            : null;

        if (
            $manufacturerCode !== null
            && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $manufacturerCode) !== 1
        ) {
            throw new InvalidArgumentException('Manufacturer variant code has an invalid format.');
        }

        $media = $data['mediaReferences'] ?? [];

        if (!is_array($media) || count($media) > 20) {
            throw new InvalidArgumentException('A variant can reference at most 20 media assets.');
        }

        /** @var list<array{mediaId: string, usage: string, sortOrder?: int, altText?: string}> $mediaReferences */
        $mediaReferences = [];

        foreach ($media as $reference) {
            if (!is_array($reference) || !isset($reference['mediaId'], $reference['usage'])) {
                throw new InvalidArgumentException('Each media reference requires mediaId and usage.');
            }

            $mediaId = trim((string) $reference['mediaId']);
            $usage = trim((string) $reference['usage']);

            if ($mediaId === '' || mb_strlen($mediaId) > 128 || $usage === '' || mb_strlen($usage) > 40) {
                throw new InvalidArgumentException('Media reference fields must be non-empty and within their length limits.');
            }

            $mediaReference = ['mediaId' => $mediaId, 'usage' => $usage];

            if (isset($reference['sortOrder'])) {
                $sortOrder = filter_var($reference['sortOrder'], FILTER_VALIDATE_INT);

                if (!is_int($sortOrder) || $sortOrder < 0) {
                    throw new InvalidArgumentException('Media reference sortOrder must be a non-negative integer.');
                }

                $mediaReference['sortOrder'] = $sortOrder;
            }

            if (isset($reference['altText'])) {
                $altText = trim((string) $reference['altText']);

                if (mb_strlen($altText) > 255) {
                    throw new InvalidArgumentException('Media reference altText cannot exceed 255 characters.');
                }

                $mediaReference['altText'] = $altText;
            }

            $mediaReferences[] = $mediaReference;
        }

        return new self(
            CatalogueId::generate(),
            CatalogueId::fromString((string) ($data['generationId'] ?? '')),
            $manufacturerCode,
            $name,
            $fuel,
            $powertrain,
            $body,
            $transmission,
            self::positiveInt($data, 'powerKw', 10000),
            self::positiveInt($data, 'displacementCc', 20000),
            self::boundedInt($data, 'seats', 1, 20),
            self::boundedInt($data, 'doors', 1, 8),
            self::positiveInt($data, 'curbWeightKg', 100000),
            self::positiveInt($data, 'lengthMm', 20000),
            self::positiveInt($data, 'widthMm', 5000),
            self::positiveInt($data, 'heightMm', 5000),
            self::nonNegativeInt($data, 'co2EmissionGPerKm', 2000),
            $mediaReferences,
            CatalogStatus::DRAFT,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private static function choice(array $data, string $key, array $allowed): string
    {
        $value = (string) ($data[$key] ?? '');

        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
            );
        }

        return $value;
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
