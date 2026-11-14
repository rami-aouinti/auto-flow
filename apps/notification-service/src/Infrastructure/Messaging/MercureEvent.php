<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Messaging;

use UnexpectedValueException;

final class MercureEvent
{
    /**
     * @param array<string,mixed> $headers
     * @return array{topic:string,data:array<string,mixed>}
     */
    public static function fromDelivery(
        string $body,
        array $headers,
        ?string $messageType,
        ?string $messageId,
        string $topicBase,
    ): array {
        $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || array_is_list($envelope)) {
            throw new UnexpectedValueException('Event envelope must be a JSON object.');
        }

        $tenant = self::nestedString($envelope, [
            'tenantId',
            'tenant_id',
            'metadata.tenantId',
            'context.tenantId',
        ]) ?? self::headerString($headers, 'tenantId')
            ?? self::headerString($headers, 'tenant_id');

        if ($tenant === null || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $tenant) !== 1) {
            throw new UnexpectedValueException(
                'Event has no valid tenant identifier; refusing cross-tenant publication.',
            );
        }

        $eventType = (string) ($envelope['eventType'] ?? $envelope['event_type'] ?? $messageType ?? 'BusinessEvent');
        $eventId = (string) ($envelope['eventId'] ?? $envelope['event_id'] ?? $messageId ?? bin2hex(random_bytes(12)));
        $aggregate = is_array($envelope['aggregate'] ?? null) ? $envelope['aggregate'] : [];
        $topic = rtrim($topicBase, '/') . '/' . rawurlencode($tenant) . '/events';

        return [
            'topic' => $topic,
            'data' => [
                'id' => $eventId,
                'type' => mb_substr($eventType, 0, 120),
                'tenantId' => $tenant,
                'occurredAt' => (string) ($envelope['occurredAt'] ?? $envelope['occurred_at'] ?? gmdate(DATE_ATOM)),
                'aggregateType' => isset($aggregate['type']) ? mb_substr((string) $aggregate['type'], 0, 80) : null,
                'aggregateId' => isset($aggregate['id']) ? mb_substr((string) $aggregate['id'], 0, 128) : null,
                'message' => mb_substr(
                    $eventType . (isset($aggregate['id']) ? ' · ' . (string) $aggregate['id'] : ''),
                    0,
                    300,
                ),
                'producer' => mb_substr(self::headerString($headers, 'producer') ?? 'autoflow', 0, 80),
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    private static function nestedString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $headers */
    private static function headerString(array $headers, string $key): ?string
    {
        $value = $headers[$key] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
