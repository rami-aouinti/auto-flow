<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Tests\Unit;

use AutoFlow\Notification\Domain\Notification\OfferPublishedNotification;
use AutoFlow\Notification\Infrastructure\Pdf\OfferPdfGenerator;
use PHPUnit\Framework\TestCase;

final class AttachmentGeneratorTest extends TestCase
{
    public function testItReturnsACompleteDocument(): void
    {
        $event = new OfferPublishedNotification(
            'evt-2',
            'tenant-demo',
            'offer-2',
            'user-1',
            'vehicle-2',
            'quote-2',
            '2026-12-01T10:00:00+00:00',
            [
                'monthlyAmount' => '299.00',
                'totalInstallmentsAmount' => '10764.00',
                'totalCostIncludingDownPayment' => '15764.00',
                'residualValue' => '5000.00',
                'currency' => 'EUR',
                'durationMonths' => 36,
                'annualInterestRateBasisPoints' => 350,
                'calculationVersion' => 'v1',
            ],
        );

        $document = (new OfferPdfGenerator())->generate($event);
        self::assertTrue(str_starts_with($document, chr(37) . 'PDF-1.4'));
        self::assertStringContainsString('%%EOF', $document);
    }
}
