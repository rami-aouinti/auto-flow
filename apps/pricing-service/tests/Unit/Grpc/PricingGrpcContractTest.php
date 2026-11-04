<?php

declare(strict_types=1);

namespace App\Tests\Unit\Grpc;

use App\Grpc\Generated\Pricing\V1\CalculateQuoteResponse;
use PHPUnit\Framework\TestCase;

final class PricingGrpcContractTest extends TestCase
{
    public function testQuoteSnapshotMetadataSurvivesProtobufSerialization(): void
    {
        $response = new CalculateQuoteResponse();
        $response->setQuoteId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $response->setVehicleId('vehicle-demo-001');
        $response->setCreatedAt('2026-11-04T12:00:00+00:00');
        $response->setExpiresAt('2026-11-11T12:00:00+00:00');
        $response->setPolicyId('123e4567-e89b-42d3-a456-426614174000');
        $response->setPolicyVersion(3);

        $decoded = new CalculateQuoteResponse();
        $decoded->mergeFromString($response->serializeToString());

        self::assertSame($response->getQuoteId(), $decoded->getQuoteId());
        self::assertSame($response->getVehicleId(), $decoded->getVehicleId());
        self::assertSame($response->getCreatedAt(), $decoded->getCreatedAt());
        self::assertSame($response->getExpiresAt(), $decoded->getExpiresAt());
        self::assertSame($response->getPolicyId(), $decoded->getPolicyId());
        self::assertSame(3, $decoded->getPolicyVersion());
    }
}
