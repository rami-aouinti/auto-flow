<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class IntegrationEvent
{
    public const PAYMENT_CAPTURED = 'PaymentCaptured';
    public const CONTRACT_SIGNED = 'contract.signed.v1';
    public const RESERVATION_CONFIRMED = 'reservation.confirmed.v1';

    private function __construct(
        public string $eventId,
        public string $eventType,
        public string $tenantId,
        public string $orderId,
        public DateTimeImmutable $occurredAt,
        public string $producer,
        public string $payloadHash,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Integration event must contain valid JSON.', previous: $exception);
        }

        if (!is_array($envelope) || array_is_list($envelope)) {
            throw new InvalidArgumentException('Integration event envelope must be a JSON object.');
        }

        if (($envelope['schemaVersion'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported integration event schemaVersion.');
        }

        $eventId = self::requiredString($envelope, 'eventId');
        $eventType = self::requiredString($envelope, 'eventType');
        $tenantId = self::requiredString($envelope, 'tenantId');
        $producer = self::requiredString($envelope, 'producer');
        $occurredAt = self::requiredString($envelope, 'occurredAt');

        if (!Uuid::isValid($eventId)) {
            throw new InvalidArgumentException('Integration event eventId must be a UUID.');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)) {
            throw new InvalidArgumentException('Integration event tenantId is invalid.');
        }

        $expectedProducer = match ($eventType) {
            self::PAYMENT_CAPTURED => 'payment-service',
            self::CONTRACT_SIGNED => 'contract-service',
            self::RESERVATION_CONFIRMED => 'vehicle-reservation-service',
            default => throw new InvalidArgumentException(sprintf('Unsupported fulfillment prerequisite event "%s".', $eventType)),
        };

        if ($producer !== $expectedProducer) {
            throw new InvalidArgumentException('Integration event producer does not match eventType.');
        }

        $data = $envelope['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Integration event data must be a JSON object.');
        }

        $orderId = self::requiredString($data, 'orderId');
        if (!Uuid::isValid($orderId)) {
            throw new InvalidArgumentException('Integration event data.orderId must be a UUID.');
        }

        try {
            $timestamp = new DateTimeImmutable($occurredAt);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Integration event occurredAt must be a valid timestamp.', previous: $exception);
        }

        return new self(
            $eventId,
            $eventType,
            $tenantId,
            $orderId,
            $timestamp->setTimezone(new \DateTimeZone('UTC')),
            $producer,
            hash('sha256', $json),
        );
    }

    /** @param array<string,mixed> $source */
    private static function requiredString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Integration event field "%s" is required.', $key));
        }

        return trim($value);
    }
}
