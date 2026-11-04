<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CreatePricingPolicy\CreatePricingPolicyHandler;
use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Domain\Model\PricingPolicy;
use App\Tests\Fixtures\PricingPolicyFixture;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CreatePricingPolicyHandlerTest extends TestCase
{
    public function testCreatesOnlyAVersionNewerThanTheLatestTenantPolicy(): void
    {
        $repository = $this->createMock(PricingPolicyRepositoryInterface::class);
        $repository->method('findLatest')->with('tenant-demo')->willReturn(PricingPolicyFixture::draft(version: 4));
        $repository->expects(self::once())->method('save')->with(self::callback(
            static fn (PricingPolicy $policy): bool => $policy->version === 5 && $policy->status === 'draft',
        ));

        $policy = (new CreatePricingPolicyHandler($repository))(
            'tenant-demo', 5, 12, 72, 0, 2000, 7, 'lease-annuity-v1', 'admin-demo',
        );

        self::assertSame(5, $policy->version);
        self::assertSame('draft', $policy->status);
    }

    public function testRejectsDuplicateOrOlderPolicyVersion(): void
    {
        $repository = $this->createMock(PricingPolicyRepositoryInterface::class);
        $repository->method('findLatest')->willReturn(PricingPolicyFixture::draft(version: 4));
        $repository->expects(self::never())->method('save');

        $this->expectException(DomainException::class);
        (new CreatePricingPolicyHandler($repository))(
            'tenant-demo', 4, 12, 72, 0, 2000, 7, 'lease-annuity-v1', 'admin-demo',
        );
    }
}
