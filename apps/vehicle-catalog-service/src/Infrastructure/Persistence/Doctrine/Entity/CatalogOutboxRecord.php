<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'catalog_outbox',
    indexes: [
        new ORM\Index(name: 'idx_catalog_outbox_pending', columns: ['next_attempt_at', 'occurred_at'], options: ['where' => 'published_at IS NULL']),
    ],
)]
class CatalogOutboxRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
    private string $id;

    #[ORM\Column(name: 'aggregate_id', type: 'string', length: 32)]
    private string $aggregateId;

    #[ORM\Column(name: 'event_type', type: 'string', length: 120)]
    private string $eventType;

    #[ORM\Column(name: 'schema_version', type: 'smallint')]
    private int $schemaVersion;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $payload;

    #[ORM\Column(name: 'occurred_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'published_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'next_attempt_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(name: 'locked_until', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(name: 'last_error', type: 'string', length: 500, nullable: true)]
    private ?string $lastError = null;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $id,
        string $aggregateId,
        string $eventType,
        int $schemaVersion,
        array $payload,
    ) {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->id = $id;
        $this->aggregateId = $aggregateId;
        $this->eventType = $eventType;
        $this->schemaVersion = $schemaVersion;
        $this->payload = $payload;
        $this->occurredAt = $now;
        $this->nextAttemptAt = $now;
    }
}
