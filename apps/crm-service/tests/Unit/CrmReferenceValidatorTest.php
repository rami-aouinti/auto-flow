<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Upstream\CrmReferenceValidator;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CrmReferenceValidatorTest extends TestCase
{
    private const CUSTOMER_ID = 'c4f0f45a-9f7a-4c87-9db0-9c5db6b7b001';

    public function testAcceptsAnActiveCustomerWithoutCommercialReferences(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'customer_id' => self::CUSTOMER_ID,
                'status' => 'active',
                'archived_at' => null,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $validator = new CrmReferenceValidator($client, 'http://customer', 'http://offer', 'http://pricing', 'http://catalog');

        $validator->validate(self::CUSTOMER_ID, null, null, null, 'tenant-demo', 'header.payload.signature');

        self::assertSame(1, $client->getRequestsCount());
    }

    public function testRejectsAnUnknownCustomer(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"detail":"not found"}', ['http_code' => 404]),
        ]);
        $validator = new CrmReferenceValidator($client, 'http://customer', 'http://offer', 'http://pricing', 'http://catalog');

        $this->expectException(DomainException::class);
        $validator->validate(self::CUSTOMER_ID, null, null, null, 'tenant-demo', 'header.payload.signature');
    }

    public function testRequiresCommercialReferencesAsACompleteSet(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'customer_id' => self::CUSTOMER_ID,
                'status' => 'active',
                'archived_at' => null,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $validator = new CrmReferenceValidator($client, 'http://customer', 'http://offer', 'http://pricing', 'http://catalog');

        $this->expectException(DomainException::class);
        $validator->validate(self::CUSTOMER_ID, 'offer-id', null, null, 'tenant-demo', 'header.payload.signature');
    }

    public function testValidatesOfferQuoteAndPublishedVehicle(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'customer_id' => self::CUSTOMER_ID,
                'status' => 'active',
                'archived_at' => null,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'offerId' => 'offer-id',
                'quoteId' => 'quote-id',
                'vehicleId' => 'vehicle-id',
                'status' => 'published',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'quoteId' => 'quote-id',
                'vehicleId' => 'vehicle-id',
                'expired' => false,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'variantId' => 'vehicle-id',
                'status' => 'published',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $validator = new CrmReferenceValidator($client, 'http://customer', 'http://offer', 'http://pricing', 'http://catalog');

        $validator->validate(self::CUSTOMER_ID, 'offer-id', 'quote-id', 'vehicle-id', 'tenant-demo', 'header.payload.signature');

        self::assertSame(4, $client->getRequestsCount());
    }
}
