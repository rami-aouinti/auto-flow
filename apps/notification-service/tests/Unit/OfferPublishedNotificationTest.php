<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Tests\Unit;

use AutoFlow\Notification\Domain\Notification\OfferPublishedNotification;
use PHPUnit\Framework\TestCase;

final class OfferPublishedNotificationTest extends TestCase
{
    public function testItParsesTheOfferEvent(): void
    {
        $event = OfferPublishedNotification::fromEnvelope([
            'eventId' => 'evt-1',
            'eventType' => 'OfferPublished',
            'schemaVersion' => 2,
            'tenantId' => 'tenant-demo',
            'aggregate' => ['id' => 'offer-1'],
            'data' => [
                'createdBy' => 'user-1',
                'vehicleId' => 'vehicle-1',
                'quoteId' => 'quote-1',
                'expiresAt' => '2026-12-01T10:00:00+00:00',
                'pricing' => [
                    'monthlyAmount' => '299.00',
                    'totalInstallmentsAmount' => '10764.00',
                    'totalCostIncludingDownPayment' => '15764.00',
                    'residualValue' => '5000.00',
                    'currency' => 'EUR',
                    'durationMonths' => 36,
                    'annualInterestRateBasisPoints' => 350,
                    'calculationVersion' => 'v1',
                ],
            ],
        ]);

        self::assertSame('evt-1', $event->eventId);
        self::assertSame('offer-1', $event->offerId);
    }
}
