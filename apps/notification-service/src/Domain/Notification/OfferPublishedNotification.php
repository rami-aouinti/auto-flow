<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Domain\Notification;

use InvalidArgumentException;

final readonly class OfferPublishedNotification
{
    /** @param array<string, mixed> $pricing */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $offerId,
        public string $createdBy,
        public string $vehicleId,
        public string $quoteId,
        public string $expiresAt,
        public array $pricing,
    ) {
        foreach ([$eventId, $tenantId, $offerId, $createdBy, $vehicleId, $quoteId, $expiresAt] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Offer notification identifiers must not be empty.');
            }
        }

        foreach (['monthlyAmount', 'totalInstallmentsAmount', 'totalCostIncludingDownPayment', 'residualValue', 'currency', 'durationMonths', 'annualInterestRateBasisPoints', 'calculationVersion'] as $field) {
            if (!array_key_exists($field, $pricing)) {
                throw new InvalidArgumentException(sprintf('Offer pricing snapshot is missing "%s".', $field));
            }
        }
    }

    /** @param array<string, mixed> $envelope */
    public static function fromEnvelope(array $envelope): self
    {
        if ((int) ($envelope['schemaVersion'] ?? 0) !== 2) {
            throw new InvalidArgumentException('Unsupported OfferPublished event schema version.');
        }

        $data = $envelope['data'] ?? null;
        $aggregate = $envelope['aggregate'] ?? null;
        if (!is_array($data) || !is_array($aggregate) || ($envelope['eventType'] ?? null) !== 'OfferPublished') {
            throw new InvalidArgumentException('Unsupported or malformed offer event envelope.');
        }

        $pricing = $data['pricing'] ?? null;
        if (!is_array($pricing)) {
            throw new InvalidArgumentException('OfferPublished event must contain its immutable pricing snapshot.');
        }

        return new self(
            (string) ($envelope['eventId'] ?? ''),
            (string) ($envelope['tenantId'] ?? ''),
            (string) ($aggregate['id'] ?? $data['offerId'] ?? ''),
            (string) ($data['createdBy'] ?? ''),
            (string) ($data['vehicleId'] ?? ''),
            (string) ($data['quoteId'] ?? ''),
            (string) ($data['expiresAt'] ?? ''),
            $pricing,
        );
    }
}
