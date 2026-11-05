<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

use InvalidArgumentException;

final readonly class CreateGenerationRequest
{
    public function __construct(
        public string $modelId,
        public string $name,
        public ?int $productionStartYear,
        public ?int $productionEndYear,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['modelId', 'name'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidArgumentException(sprintf('%s must be a string.', $field));
            }
        }

        return new self(
            $data['modelId'],
            $data['name'],
            self::nullableInteger($data, 'productionStartYear'),
            self::nullableInteger($data, 'productionEndYear'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function nullableInteger(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        $raw = $data[$key];

        if (!is_int($raw) && (!is_string($raw) || preg_match('/^-?\\d+$/D', $raw) !== 1)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer or null.', $key));
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer or null.', $key));
        }

        return $value;
    }
}
