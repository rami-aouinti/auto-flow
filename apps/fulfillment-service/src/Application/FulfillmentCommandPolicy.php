<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final class FulfillmentCommandPolicy
{
    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     * @return array{status:string,changes:array<string,mixed>}
     */
    public function apply(
        string $command,
        array $record,
        array $payload,
        DateTimeImmutable $now,
    ): array {
        $status = (string) $record['status'];
        $changes = [];

        switch ($command) {
            case 'start-preparation':
                $this->expectStatus($status, 'ready_for_preparation');
                $next = 'preparing';
                break;

            case 'complete-preparation':
                $this->expectStatus($status, 'preparing');
                $next = 'ready_for_dispatch';
                break;

            case 'schedule':
                if (!in_array($status, ['ready_for_dispatch', 'delivery_failed'], true)) {
                    throw new DomainException(sprintf('Cannot schedule delivery while fulfillment is %s.', $status));
                }
                $scheduledFor = $this->date($payload, 'scheduledFor');
                if ($scheduledFor <= $now) {
                    throw new InvalidArgumentException('scheduledFor must be in the future.');
                }
                $carrier = $this->requiredString($payload, 'carrier', 120);
                $tracking = $payload['trackingReference'] ?? null;
                if ($tracking !== null && (!is_string($tracking) || trim($tracking) === '' || mb_strlen(trim($tracking)) > 180)) {
                    throw new InvalidArgumentException('trackingReference must contain between 1 and 180 characters.');
                }
                $next = 'scheduled';
                $changes = [
                    'scheduled_for' => $scheduledFor->format('Y-m-d H:i:sP'),
                    'carrier' => $carrier,
                    'tracking_reference' => $tracking === null ? null : trim($tracking),
                    'delivery_failure_reason' => null,
                ];
                break;

            case 'dispatch':
                $this->expectStatus($status, 'scheduled');
                if (!is_string($record['scheduled_for'] ?? null) || $record['scheduled_for'] === '') {
                    throw new DomainException('Scheduled fulfillment has no delivery time.');
                }
                $scheduledFor = new DateTimeImmutable((string) $record['scheduled_for']);
                if ($scheduledFor > $now) {
                    throw new DomainException('Fulfillment cannot be dispatched before its scheduled delivery time.');
                }
                $next = 'in_transit';
                $changes = ['dispatched_at' => $now->format('Y-m-d H:i:sP')];
                break;

            case 'confirm-delivery':
                $this->expectStatus($status, 'in_transit');
                $recipient = $this->requiredString($payload, 'recipientName', 180);
                if (!is_string($record['dispatched_at'] ?? null) || $record['dispatched_at'] === '') {
                    throw new DomainException('In-transit fulfillment has no dispatch timestamp.');
                }
                $dispatchedAt = new DateTimeImmutable((string) $record['dispatched_at']);
                if ($now < $dispatchedAt) {
                    throw new DomainException('Delivery confirmation cannot precede dispatch.');
                }
                $next = 'delivered';
                $changes = [
                    'delivered_at' => $now->format('Y-m-d H:i:sP'),
                    'recipient_name' => $recipient,
                ];
                break;

            case 'fail-delivery':
                $this->expectStatus($status, 'in_transit');
                $next = 'delivery_failed';
                $changes = ['delivery_failure_reason' => $this->requiredString($payload, 'reason', 500)];
                break;

            case 'cancel':
                if (in_array($status, ['in_transit', 'delivered', 'cancelled'], true)) {
                    throw new DomainException(sprintf('Cannot cancel fulfillment while it is %s.', $status));
                }
                $next = 'cancelled';
                break;

            default:
                throw new InvalidArgumentException(sprintf('Unsupported fulfillment command "%s".', $command));
        }

        return ['status' => $next, 'changes' => $changes];
    }

    private function expectStatus(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new DomainException(sprintf('Command requires status %s; current status is %s.', $expected, $actual));
        }
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s must contain between 1 and %d characters.', $key, $maxLength));
        }

        return trim($value);
    }

    /** @param array<string,mixed> $payload */
    private function date(array $payload, string $key): DateTimeImmutable
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(?::\\d{2}(?:\\.\\d+)?)?(?:Z|[+-]\\d{2}:?\\d{2})$/D', trim($value))) {
            throw new InvalidArgumentException(sprintf('%s is required as an ISO-8601 timestamp.', $key));
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(sprintf('%s must be a valid ISO-8601 timestamp.', $key), previous: $exception);
        }
    }
}
