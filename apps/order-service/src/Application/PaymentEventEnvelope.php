<?php

declare(strict_types=1);

namespace App\Application;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class PaymentEventEnvelope
{
    private function __construct(
        public string $eventId,
        public string $eventType,
        public string $tenantId,
        public string $paymentId,
        public string $orderId,
        public string $payloadHash,
    ) {
    }

    public static function fromMessage(string $body, string $messageId, string $messageType): self
    {
        $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || array_is_list($envelope)) {
            throw new InvalidArgumentException('Payment event must be a JSON object.');
        }

        $eventId = $envelope['eventId'] ?? null;
        $eventType = $envelope['eventType'] ?? null;
        $tenantId = $envelope['tenantId'] ?? null;
        $data = $envelope['data'] ?? null;
        $allowedTypes = [
            'PaymentAuthorized',
            'PaymentCaptured',
            'PaymentFailed',
            'PaymentRefunded',
            'PaymentPartiallyRefunded',
            'PaymentCancelled',
        ];

        if (!is_string($eventId) || !Uuid::isValid($eventId)
            || !is_string($eventType) || !in_array($eventType, $allowedTypes, true)
            || ($envelope['schemaVersion'] ?? null) !== 1
            || ($envelope['producer'] ?? null) !== 'payment-service'
            || !is_string($tenantId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1
            || !is_array($data) || array_is_list($data)
        ) {
            throw new InvalidArgumentException('Payment event envelope is invalid or unsupported.');
        }

        if ($messageId !== $eventId || $messageType !== $eventType) {
            throw new InvalidArgumentException('RabbitMQ metadata does not match the Payment event envelope.');
        }

        $paymentId = $data['paymentId'] ?? null;
        $orderId = $data['orderId'] ?? null;
        if (!is_string($paymentId) || !Uuid::isValid($paymentId)
            || !is_string($orderId) || !Uuid::isValid($orderId)
        ) {
            throw new InvalidArgumentException('Payment event requires valid paymentId and orderId UUIDs.');
        }

        return new self(
            $eventId,
            $eventType,
            $tenantId,
            $paymentId,
            $orderId,
            hash('sha256', $body),
        );
    }
}
