<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Messaging;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class IntegrationEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public string $aggregateId,
        public DateTimeImmutable $occurredAt,
        public int $schemaVersion,
        public array $payload,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {
        if ($eventId === '' || $eventName === '' || $aggregateId === '' || $schemaVersion < 1) {
            throw new InvalidArgumentException('Integration event metadata is incomplete.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(
        string $eventName,
        string $aggregateId,
        array $payload,
        ?string $correlationId = null,
        ?string $causationId = null,
        int $schemaVersion = 1,
    ): self {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $eventId = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );

        return new self(
            $eventId,
            $eventName,
            $aggregateId,
            new DateTimeImmutable(),
            $schemaVersion,
            $payload,
            $correlationId,
            $causationId,
        );
    }
}
