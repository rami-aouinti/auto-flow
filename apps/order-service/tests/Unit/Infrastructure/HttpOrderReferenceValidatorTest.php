<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Application\OrderReferenceValidationException;
use App\Infrastructure\Upstream\HttpOrderReferenceValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpOrderReferenceValidatorTest extends TestCase
{
    public function testItBuildsSnapshotsOnlyFromVerifiedUpstreamResponses(): void
    {
        $expiry = gmdate(DATE_ATOM, time() + 3600);
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'offerId' => 'offer-1',
                'quoteId' => 'quote-1',
                'vehicleId' => 'vehicle-1',
                'status' => 'accepted',
                'expiresAt' => $expiry,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'quoteId' => 'quote-1',
                'vehicleId' => 'vehicle-1',
                'expired' => false,
                'expiresAt' => $expiry,
                'monthlyAmount' => '329.00',
                'currency' => 'EUR',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'variantId' => 'vehicle-1',
                'status' => 'published',
                'manufacturer' => ['name' => 'Example Motors'],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'customer_id' => 'customer-1',
                'customer_type' => 'individual',
                'first_name' => 'Rami',
                'last_name' => 'Example',
                'email' => 'rami@example.test',
                'status' => 'active',
                'archived_at' => null,
                'version' => 3,
                'addresses' => [
                    ['address_type' => 'billing', 'city' => 'Cologne', 'country_code' => 'DE'],
                    ['address_type' => 'shipping', 'city' => 'Bonn', 'country_code' => 'DE'],
                    ['address_type' => 'other', 'city' => 'Berlin', 'country_code' => 'DE'],
                ],
                'consents' => [['type' => 'marketing', 'granted' => true]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $validator = new HttpOrderReferenceValidator(
            $client,
            'http://offer-service',
            'http://pricing-service',
            'http://vehicle-catalog-service',
            'http://customer-service',
        );

        $result = $validator->validate('offer-1', 'quote-1', 'vehicle-1', 'customer-1', 'tenant-a', 'header.payload.signature');

        self::assertSame('accepted', $result['offerSnapshot']['status']);
        self::assertSame('329.00', $result['quoteSnapshot']['monthlyAmount']);
        self::assertSame('published', $result['vehicleSnapshot']['status']);
        self::assertSame(3, $result['customerVersion']);
        self::assertSame('customer-1', $result['customerSnapshot']['customerId']);
        self::assertCount(2, $result['customerSnapshot']['addresses']);
        self::assertArrayNotHasKey('consents', $result['customerSnapshot']);
    }

    public function testItRejectsAResponseForAnotherVehicleVariant(): void
    {
        $expiry = gmdate(DATE_ATOM, time() + 3600);
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'offerId' => 'offer-1', 'quoteId' => 'quote-1', 'vehicleId' => 'vehicle-1',
                'status' => 'accepted', 'expiresAt' => $expiry,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'quoteId' => 'quote-1', 'vehicleId' => 'vehicle-1', 'expired' => false,
                'expiresAt' => $expiry,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'variantId' => 'another-vehicle', 'status' => 'published',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $validator = new HttpOrderReferenceValidator(
            $client,
            'http://offer-service',
            'http://pricing-service',
            'http://vehicle-catalog-service',
            'http://customer-service',
        );

        $this->expectException(OrderReferenceValidationException::class);
        $validator->validate('offer-1', 'quote-1', 'vehicle-1', 'customer-1', 'tenant-a', 'header.payload.signature');
    }

    public function testItRejectsAnOfferThatReferencesAnotherQuote(): void
    {
        $expiry = gmdate(DATE_ATOM, time() + 3600);
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'offerId' => 'offer-1',
                'quoteId' => 'different-quote',
                'vehicleId' => 'vehicle-1',
                'status' => 'accepted',
                'expiresAt' => $expiry,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $validator = new HttpOrderReferenceValidator(
            $client,
            'http://offer-service',
            'http://pricing-service',
            'http://vehicle-catalog-service',
            'http://customer-service',
        );

        $this->expectException(OrderReferenceValidationException::class);
        $validator->validate('offer-1', 'quote-1', 'vehicle-1', 'customer-1', 'tenant-a', 'header.payload.signature');
    }
}
