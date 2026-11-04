<?php

declare(strict_types=1);

namespace App\Application\CreatePricingPolicy;

use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Domain\Model\PricingPolicy;
use DateTimeImmutable;

final readonly class CreatePricingPolicyHandler
{
    public function __construct(private PricingPolicyRepositoryInterface $repository)
    {
    }

    public function __invoke(
        string $tenantId,
        int $version,
        int $minimumDurationMonths,
        int $maximumDurationMonths,
        int $minimumAnnualRateBasisPoints,
        int $maximumAnnualRateBasisPoints,
        int $quoteValidityDays,
        string $calculationVersion,
        string $createdBy,
    ): PricingPolicy {
        $policy = new PricingPolicy(
            policyId: self::uuidV4(),
            tenantId: $tenantId,
            version: $version,
            status: 'draft',
            minimumDurationMonths: $minimumDurationMonths,
            maximumDurationMonths: $maximumDurationMonths,
            minimumAnnualRateBasisPoints: $minimumAnnualRateBasisPoints,
            maximumAnnualRateBasisPoints: $maximumAnnualRateBasisPoints,
            quoteValidityDays: $quoteValidityDays,
            calculationVersion: $calculationVersion,
            createdBy: $createdBy,
            createdAt: new DateTimeImmutable('now'),
        );
        $this->repository->save($policy);
        return $policy;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
