<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Upstream;

use App\Application\OrderReferenceValidationException;
use App\Infrastructure\Upstream\HttpOrderReferenceValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpOrderReferenceValidatorTest extends TestCase
{
    private const CUSTOMER_ID = 'c4f0f45a-9f7a-4c87-9db0-9c5db6b7b001';

    public function testItCapturesActiveCustomerSnapshotAfterValidatingOtherReferences(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'offerId' => 'offer-123',
                'quoteId' => 'quote-456',
                'vehicleId' => 'variant-789',
                'status' => 'accepted',
                'expiresAt' => '2099-12-31T23:59:59+00:00',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'quoteId' => 'quote-456',
                'vehicleId' => 'variant-789',
                'expired' => false,
                'expiresAt' => '2099-12-31T23:59:59+00:00',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'variantId' => 'variant-789',
                'status' => 'published',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'customer_id' => self::CUSTOMER_ID,
                'customer_type' => 'individual',
                'first_name' => 'Rami',
                'last_name' => 'Aouinti',
                'legal_name' => null,
                'email' => 'rami@example.test',
                'phone' => '+49123456789',
                'vat_id' => null,
                'locale' => 'de-DE',
                'status' => 'active',
                'archived_at' => null,
                'version' => 3,
                'addresses' => [
                    ['address_type' => 'billing', 'city' => 'Berlin'],
                    ['address_type' => 'residential', 'city' => 'Berlin'],
                ],
                'consents' => [['purpose' => 'marketing', 'granted' => true]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $validator = new HttpOrderReferenceValidator(
            $client,
            'http://offer-service',
            'http://pricing-service',
            'http://catalog-service',
            'http://customer-service',
        );

        $result = $validator->validate(
            'offer-123',
            'quote-456',
            'variant-789',
            self::CUSTOMER_ID,
            'tenant-demo',
            'header.payload.signature',
        );

        self::assertSame(self::CUSTOMER_ID, $result['customerSnapshot']['customerId']);
        self::assertSame(3, $result['customerVersion']);
        self::assertSame('Rami', $result['customerSnapshot']['firstName']);
        self::assertSame('Rami Aouinti', $result['customerSnapshot']['displayName']);
        self::assertCount(1, $result['customerSnapshot']['addresses']);
        self::assertSame('billing', $result['customerSnapshot']['addresses'][0]['address_type']);
        self::assertArrayNotHasKey('consents', $result['customerSnapshot']);
    }

    public function testItRejectsAnArchivedCustomer(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'offerId' => 'offer-123',
                'quoteId' => 'quote-456',
                'vehicleId' => 'variant-789',
                'status' => 'accepted',
                'expiresAt' => '2099-12-31T23:59:59+00:00',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'quoteId' => 'quote-456',
                'vehicleId' => 'variant-789',
                'expired' => false,
                'expiresAt' => '2099-12-31T23:59:59+00:00',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'variantId' => 'variant-789',
                'status' => 'published',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'customer_id' => self::CUSTOMER_ID,
                'status' => 'archived',
                'archived_at' => '2026-11-07T12:00:00+00:00',
                'version' => 4,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $validator = new HttpOrderReferenceValidator(
            $client,
            'http://offer-service',
            'http://pricing-service',
            'http://catalog-service',
            'http://customer-service',
        );

        $this->expectException(OrderReferenceValidationException::class);

        $validator->validate(
            'offer-123',
            'quote-456',
            'variant-789',
            self::CUSTOMER_ID,
            'tenant-demo',
            'header.payload.signature',
        );
    }
}
