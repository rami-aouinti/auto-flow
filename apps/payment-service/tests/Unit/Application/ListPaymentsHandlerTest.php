<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\ListPayments\ListPaymentsHandler;
use App\Application\Port\PaymentRepositoryInterface;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ListPaymentsHandlerTest extends TestCase
{
    public function testItReturnsTenantScopedPaginatedPayments(): void
    {
        $result = [
            'items' => [['payment_id' => 'payment-1']],
            'total' => 1,
        ];
        $repository = $this->createMock(PaymentRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('listByTenant')
            ->with('tenant-1', 10, 20)
            ->willReturn($result);

        self::assertSame(
            ['items' => $result['items'], 'total' => 1, 'limit' => 10, 'offset' => 20],
            (new ListPaymentsHandler($repository))('tenant-1', 10, 20),
        );
    }

    public function testItRejectsMissingTenantAndInvalidPaginationBeforeRepositoryCall(): void
    {
        $repository = $this->createMock(PaymentRepositoryInterface::class);
        $repository->expects(self::never())->method('listByTenant');
        $handler = new ListPaymentsHandler($repository);

        try {
            $handler('', 20, 0);
            self::fail('An empty tenant must be rejected.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        try {
            $handler('tenant-1', 101, 0);
            self::fail('A limit above 100 must be rejected.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
