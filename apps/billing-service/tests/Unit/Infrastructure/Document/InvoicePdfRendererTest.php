<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Document;

use App\Infrastructure\Document\InvoicePdfRenderer;
use DomainException;
use PHPUnit\Framework\TestCase;

final class InvoicePdfRendererTest extends TestCase
{
    public function testItRendersAValidIssuedInvoiceAsPdf(): void
    {
        $pdf = (new InvoicePdfRenderer())->render([
            'status' => 'issued',
            'invoice_number' => 'AF-2026-000001',
            'issued_at' => '2026-11-14T00:00:00+00:00',
            'order_id' => 'order-1',
            'payment_id' => 'payment-1',
            'currency' => 'EUR',
            'gross_minor' => 11900,
            'net_minor' => 10000,
            'tax_minor' => 1900,
            'tax_rate_basis_points' => 1900,
            'billing_snapshot' => [
                'documentStatus' => 'issued',
                'legalReadiness' => ['readyToIssue' => true],
                'issuer' => ['legalName' => 'AutoFlow GmbH', 'taxNumber' => '123/456/789', 'address' => [
                    'line1' => 'Issuer Street 1', 'postalCode' => '10115', 'city' => 'Berlin', 'countryCode' => 'DE',
                ]],
                'recipient' => ['displayName' => 'Example GmbH', 'vatId' => 'DE123456789', 'address' => [
                    'line1' => 'Customer Street 1', 'postalCode' => '50667', 'city' => 'Köln', 'countryCode' => 'DE',
                ]],
            ],
        ]);

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testItRefusesToRenderAnInvoiceWithoutLegalReadiness(): void
    {
        $this->expectException(DomainException::class);
        (new InvoicePdfRenderer())->render([
            'status' => 'draft',
            'billing_snapshot' => ['legalReadiness' => ['readyToIssue' => false]],
        ]);
    }
}
