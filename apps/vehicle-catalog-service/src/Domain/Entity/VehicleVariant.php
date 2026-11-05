<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\CatalogueId;
use App\Domain\ValueObject\CatalogStatus;
use App\Domain\ValueObject\TechnicalSpecifications;
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
        public TechnicalSpecifications $specifications,
        public array $mediaReferences,
        public CatalogStatus $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
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

        if ($manufacturerCode === '') {
            $manufacturerCode = null;
        }

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
                throw new InvalidArgumentException(
                    'Media reference fields must be non-empty and within their length limits.',
                );
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
            TechnicalSpecifications::fromArray($data),
            $mediaReferences,
            CatalogStatus::DRAFT,
        );
    }

    public function withStatus(CatalogStatus $status): self
    {
        return new self(
            $this->id,
            $this->generationId,
            $this->manufacturerCode,
            $this->name,
            $this->fuelType,
            $this->powertrain,
            $this->bodyStyle,
            $this->transmission,
            $this->specifications,
            $this->mediaReferences,
            $this->status->transitionTo($status),
        );
    }

    public function equals(self $other): bool
    {
        return $this->id->value === $other->id->value;
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
}
