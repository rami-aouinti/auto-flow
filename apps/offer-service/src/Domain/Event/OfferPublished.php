<?php

declare(strict_types=1);

namespace App\Domain\Event;

use DateTimeImmutable;

final readonly class OfferPublished implements DomainEvent
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $offerId,
        public string $createdBy,
        public string $quoteId,
        public string $vehicleId,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $expiresAt,
        /** @var array<string, string|int> */
        public array $pricing,
    ) {
    }

    public function toEnvelope(): array
    {
        return [
            'eventId' => $this->eventId,
            'eventType' => 'OfferPublished',
            'schemaVersion' => 1,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'producer' => 'offer-service',
            'tenantId' => $this->tenantId,
            'aggregate' => ['type' => 'Offer', 'id' => $this->offerId],
            'data' => [
                'offerId' => $this->offerId,
                'createdBy' => $this->createdBy,
                'quoteId' => $this->quoteId,
                'vehicleId' => $this->vehicleId,
                'expiresAt' => $this->expiresAt->format(DATE_ATOM),
                'pricing' => $this->pricing,
            ],
        ];
    }
}
