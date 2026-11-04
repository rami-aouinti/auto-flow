<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Application\PublishPricingPolicy\PublishPricingPolicyHandler;
use App\Domain\Model\PricingPolicy;
use App\Tests\Fixtures\PricingPolicyFixture;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PublishPricingPolicyHandlerTest extends TestCase
{
    public function testPublishesOnlyTheLatestDraftVersion(): void
    {
        $draft = PricingPolicyFixture::draft(version: 3);
        $repository = $this->createMock(PricingPolicyRepositoryInterface::class);
        $repository->method('findByVersion')->with('tenant-demo', 3)->willReturn($draft);
        $repository->method('findLatest')->with('tenant-demo')->willReturn($draft);
        $repository->expects(self::once())->method('save')->with(self::callback(
            static fn (PricingPolicy $policy): bool => $policy->status === 'active' && $policy->version === 3,
        ));

        $published = (new PublishPricingPolicyHandler($repository))('tenant-demo', 3);

        self::assertSame('active', $published->status);
        self::assertNotNull($published->publishedAt);
    }

    public function testRejectsPublishingAnOlderDraftVersion(): void
    {
        $oldDraft = PricingPolicyFixture::draft(version: 2);
        $latest = PricingPolicyFixture::draft(version: 3, policyId: '123e4567-e89b-42d3-a456-426614174001');
        $repository = $this->createMock(PricingPolicyRepositoryInterface::class);
        $repository->method('findByVersion')->willReturn($oldDraft);
        $repository->method('findLatest')->willReturn($latest);
        $repository->expects(self::never())->method('save');

        $this->expectException(DomainException::class);
        (new PublishPricingPolicyHandler($repository))('tenant-demo', 2);
    }
}
