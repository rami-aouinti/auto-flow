<?php

declare(strict_types=1);

namespace App\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PricingPolicy
{
    public function __construct(
        public string $policyId,
        public string $tenantId,
        public int $version,
        public string $status,
        public int $minimumDurationMonths,
        public int $maximumDurationMonths,
        public int $minimumAnnualRateBasisPoints,
        public int $maximumAnnualRateBasisPoints,
        public int $quoteValidityDays,
        public string $calculationVersion,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $publishedAt = null,
    ) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $policyId)) {
            throw new InvalidArgumentException('Pricing policy ID must be a UUID.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)) {
            throw new InvalidArgumentException('Pricing policy tenant ID has an invalid format.');
        }
        if ($version < 1 || !in_array($status, ['draft', 'active', 'retired'], true)) {
            throw new InvalidArgumentException('Pricing policy version or status is invalid.');
        }
        if (
            $minimumDurationMonths < 12
            || $maximumDurationMonths > 72
            || $maximumDurationMonths < $minimumDurationMonths
        ) {
            throw new InvalidArgumentException('Pricing policy duration range is invalid.');
        }
        if (
            $minimumAnnualRateBasisPoints < 0
            || $maximumAnnualRateBasisPoints > 2000
            || $maximumAnnualRateBasisPoints < $minimumAnnualRateBasisPoints
        ) {
            throw new InvalidArgumentException('Pricing policy annual rate range is invalid.');
        }
        if ($quoteValidityDays < 1 || $quoteValidityDays > 90) {
            throw new InvalidArgumentException('Pricing policy validity must be between 1 and 90 days.');
        }
        if (trim($calculationVersion) === '' || trim($createdBy) === '') {
            throw new InvalidArgumentException('Pricing policy version and creator are required.');
        }
        if ($calculationVersion !== 'lease-annuity-v1') {
            throw new InvalidArgumentException('The selected pricing calculation algorithm is not supported.');
        }
        if (($status !== 'draft') !== ($publishedAt !== null)) {
            throw new InvalidArgumentException('Active and retired policies must retain their publication timestamp.');
        }
    }

    public function activate(DateTimeImmutable $publishedAt): self
    {
        if ($this->status !== 'draft') {
            throw new InvalidArgumentException('Only a draft pricing policy can be activated.');
        }
        return new self(
            policyId: $this->policyId,
            tenantId: $this->tenantId,
            version: $this->version,
            status: 'active',
            minimumDurationMonths: $this->minimumDurationMonths,
            maximumDurationMonths: $this->maximumDurationMonths,
            minimumAnnualRateBasisPoints: $this->minimumAnnualRateBasisPoints,
            maximumAnnualRateBasisPoints: $this->maximumAnnualRateBasisPoints,
            quoteValidityDays: $this->quoteValidityDays,
            calculationVersion: $this->calculationVersion,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            publishedAt: $publishedAt,
        );
    }

    public function retire(): self
    {
        if ($this->status !== 'active') {
            throw new InvalidArgumentException('Only an active pricing policy can be retired.');
        }
        return new self(
            policyId: $this->policyId,
            tenantId: $this->tenantId,
            version: $this->version,
            status: 'retired',
            minimumDurationMonths: $this->minimumDurationMonths,
            maximumDurationMonths: $this->maximumDurationMonths,
            minimumAnnualRateBasisPoints: $this->minimumAnnualRateBasisPoints,
            maximumAnnualRateBasisPoints: $this->maximumAnnualRateBasisPoints,
            quoteValidityDays: $this->quoteValidityDays,
            calculationVersion: $this->calculationVersion,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            publishedAt: $this->publishedAt,
        );
    }

    public function allows(int $durationMonths, int $annualRateBasisPoints): bool
    {
        return $durationMonths >= $this->minimumDurationMonths
            && $durationMonths <= $this->maximumDurationMonths
            && $annualRateBasisPoints >= $this->minimumAnnualRateBasisPoints
            && $annualRateBasisPoints <= $this->maximumAnnualRateBasisPoints;
    }
}
