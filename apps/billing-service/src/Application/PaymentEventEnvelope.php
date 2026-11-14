<?php

declare(strict_types=1);

namespace App\Application;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class PaymentEventEnvelope
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public string $eventId,
        public string $eventType,
        public string $tenantId,
        public string $paymentId,
        public string $orderId,
        public array $data,
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

        if (!is_string($eventId) || !Uuid::isValid($eventId)
            || !is_string($eventType) || !in_array($eventType, ['PaymentCaptured', 'PaymentRefunded', 'PaymentPartiallyRefunded'], true)
            || ($envelope['schemaVersion'] ?? null) !== 1
            || ($envelope['producer'] ?? null) !== 'payment-service'
            || !is_string($tenantId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1
            || !is_array($data) || array_is_list($data)
        ) {
            throw new InvalidArgumentException('Payment event envelope is invalid or unsupported.');
        }

        if ($messageId !== $eventId || $messageType !== $eventType) {
            throw new InvalidArgumentException('RabbitMQ metadata does not match the payment event envelope.');
        }

        $paymentId = $data['paymentId'] ?? null;
        $orderId = $data['orderId'] ?? null;
        if (!is_string($paymentId) || !Uuid::isValid($paymentId)
            || !is_string($orderId) || !Uuid::isValid($orderId)
        ) {
            throw new InvalidArgumentException('Payment event requires valid paymentId and orderId UUIDs.');
        }

        if ($eventType === 'PaymentCaptured') {
            $amount = filter_var($data['amountMinor'] ?? null, FILTER_VALIDATE_INT);
            $currency = $data['currency'] ?? null;
            if (!is_int($amount) || $amount < 1 || !is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new InvalidArgumentException('PaymentCaptured requires a positive amountMinor and ISO currency.');
            }
        } else {
            $refund = filter_var($data['refundAmountMinor'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($refund) || $refund < 1) {
                throw new InvalidArgumentException('Payment refund events require a positive refundAmountMinor.');
            }
            $currency = $data['currency'] ?? null;
            if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
                throw new InvalidArgumentException('Payment refund currency must be a three-letter uppercase ISO code.');
            }
        }

        return new self(
            $eventId,
            $eventType,
            $tenantId,
            $paymentId,
            $orderId,
            $data,
            hash('sha256', $body),
        );
    }
}
