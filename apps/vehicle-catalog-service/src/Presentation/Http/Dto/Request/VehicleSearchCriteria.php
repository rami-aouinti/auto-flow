<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

final readonly class VehicleSearchCriteria
{
    private const FILTER_KEYS = [
        'q',
        'manufacturerId',
        'modelId',
        'generationId',
        'fuelType',
        'powertrain',
        'bodyStyle',
        'transmission',
        'minPowerKw',
        'maxPowerKw',
        'minSeats',
        'maxSeats',
    ];

    private const ENUM_FILTERS = [
        'fuelType' => [
            'petrol',
            'diesel',
            'electric',
            'hybrid',
            'plug_in_hybrid',
            'hydrogen',
            'lpg',
            'cng',
            'other',
        ],
        'powertrain' => ['ice', 'mhev', 'hev', 'phev', 'bev', 'fcev', 'other'],
        'bodyStyle' => [
            'suv',
            'sedan',
            'hatchback',
            'estate',
            'coupe',
            'convertible',
            'van',
            'pickup',
            'other',
        ],
        'transmission' => ['manual', 'automatic', 'single_speed', 'other'],
    ];

    /**
     * @param array<string, scalar|null> $filters
     */
    private function __construct(
        public array $filters,
        public int $limit,
        public int $offset,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $limit = filter_var($request->query->get('limit', '20'), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);

        if (!is_int($limit) || !is_int($offset) || $limit < 1 || $limit > 100 || $offset < 0) {
            throw new InvalidArgumentException('limit must be 1-100 and offset must be non-negative.');
        }

        $filters = [];

        foreach (self::FILTER_KEYS as $key) {
            $value = $request->query->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            if (!is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('%s must be a scalar query value.', $key));
            }

            $filters[$key] = $value;
        }

        foreach (['manufacturerId', 'modelId', 'generationId'] as $idKey) {
            if (
                isset($filters[$idKey])
                && preg_match('/^[a-f0-9]{32}$/D', (string) $filters[$idKey]) !== 1
            ) {
                throw new InvalidArgumentException(sprintf('%s must be a valid catalogue identifier.', $idKey));
            }
        }

        foreach (['minPowerKw', 'maxPowerKw', 'minSeats', 'maxSeats'] as $numericKey) {
            if (!isset($filters[$numericKey])) {
                continue;
            }

            $value = filter_var($filters[$numericKey], FILTER_VALIDATE_INT);

            if (!is_int($value)) {
                throw new InvalidArgumentException(sprintf('%s must be an integer.', $numericKey));
            }

            $maximum = str_contains($numericKey, 'Seats') ? 20 : 10000;
            $minimum = str_contains($numericKey, 'Seats') ? 1 : 1;

            if ($value < $minimum || $value > $maximum) {
                throw new InvalidArgumentException(
                    sprintf('%s must be between %d and %d.', $numericKey, $minimum, $maximum),
                );
            }

            $filters[$numericKey] = $value;
        }

        foreach (self::ENUM_FILTERS as $key => $allowed) {
            if (isset($filters[$key]) && !in_array($filters[$key], $allowed, true)) {
                throw new InvalidArgumentException(sprintf('%s is not a supported catalogue filter.', $key));
            }
        }

        if (isset($filters['q']) && mb_strlen((string) $filters['q']) > 200) {
            throw new InvalidArgumentException('q cannot exceed 200 characters.');
        }

        if (
            isset($filters['minPowerKw'], $filters['maxPowerKw'])
            && $filters['minPowerKw'] > $filters['maxPowerKw']
        ) {
            throw new InvalidArgumentException('minPowerKw cannot exceed maxPowerKw.');
        }

        if (
            isset($filters['minSeats'], $filters['maxSeats'])
            && $filters['minSeats'] > $filters['maxSeats']
        ) {
            throw new InvalidArgumentException('minSeats cannot exceed maxSeats.');
        }

        return new self($filters, $limit, $offset);
    }
}
