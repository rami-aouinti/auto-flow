<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\InvoiceLegalSnapshot;
use PHPUnit\Framework\TestCase;

final class InvoiceLegalSnapshotTest extends TestCase
{
    public function testItMarksInvoiceReadyOnlyWhenIssuerAndRecipientLegalDetailsAreComplete(): void
    {
        $snapshot = InvoiceLegalSnapshot::build(
            [
                'customerId' => 'customer-1',
                'customerType' => 'company',
                'legalName' => 'Example GmbH',
                'vatId' => 'DE123456789',
                'billingAddress' => [
                    'line1' => 'Musterstraße 1',
                    'postalCode' => '50667',
                    'city' => 'Köln',
                    'countryCode' => 'DE',
                ],
            ],
            [
                'legalName' => 'AutoFlow Demo Entity',
                'taxNumber' => '123/456/789',
                'address' => [
                    'line1' => 'Issuer Street 1',
                    'postalCode' => '10115',
                    'city' => 'Berlin',
                    'countryCode' => 'DE',
                ],
            ],
        );

        self::assertSame('issued', $snapshot['documentStatus']);
        self::assertTrue($snapshot['legalReadiness']['readyToIssue']);
        self::assertSame('Example GmbH', $snapshot['recipient']['displayName']);
    }

    public function testItKeepsInvoiceAsDraftWhenBillingAddressIsMissing(): void
    {
        $snapshot = InvoiceLegalSnapshot::build(
            ['customerId' => 'customer-2', 'customerType' => 'individual', 'firstName' => 'Ada', 'lastName' => 'Lovelace'],
            ['legalName' => 'AutoFlow Demo Entity', 'taxNumber' => '123/456/789'],
        );

        self::assertSame('draft', $snapshot['documentStatus']);
        self::assertFalse($snapshot['legalReadiness']['recipientComplete']);
        self::assertFalse($snapshot['legalReadiness']['readyToIssue']);
    }

    public function testItKeepsInvoiceAsDraftWhenIssuerDetailsAreMissing(): void
    {
        $snapshot = InvoiceLegalSnapshot::build(
            [
                'customerId' => 'customer-3',
                'customerType' => 'individual',
                'firstName' => 'Ada',
                'lastName' => 'Lovelace',
                'billingAddress' => [
                    'line1' => 'Street 1', 'postalCode' => '10115', 'city' => 'Berlin', 'countryCode' => 'DE',
                ],
            ],
            [],
        );

        self::assertSame('draft', $snapshot['documentStatus']);
        self::assertFalse($snapshot['legalReadiness']['issuerComplete']);
    }
}
