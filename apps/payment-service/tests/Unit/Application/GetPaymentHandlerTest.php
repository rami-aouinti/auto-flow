<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\GetPayment\GetPaymentHandler;
use App\Application\PaymentNotFoundException;
use App\Application\Port\PaymentRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetPaymentHandlerTest extends TestCase
{
    public function testItReturnsThePaymentFromTheTenantScopedRepository(): void
    {
        $payment = ['payment_id' => 'payment-1', 'tenant_id' => 'tenant-1'];
        $repository = new class($payment) implements PaymentRepositoryInterface {
            public function __construct(private array $payment)
            {
            }

            public function findByTenantAndId(string $tenantId, string $paymentId): ?array
            {
                if ($tenantId !== 'tenant-1' || $paymentId !== 'payment-1') {
                    throw new \RuntimeException('Unexpected tenant or payment identifier.');
                }

                return $this->payment;
            }

            public function listByTenant(string $tenantId, int $limit, int $offset): array
            {
                return ['items' => [], 'total' => 0];
            }
        };

        $handler = new GetPaymentHandler($repository);
        self::assertSame($payment, $handler('tenant-1', 'payment-1'));
        self::assertSame(
            ['items' => [], 'total' => 0, 'limit' => 20, 'offset' => 0],
            $handler->list('tenant-1'),
        );
    }


    public function testItRejectsMissingTenantAndInvalidPagination(): void
    {
        $repository = new class implements PaymentRepositoryInterface {
            public function findByTenantAndId(string $tenantId, string $paymentId): ?array
            {
                return null;
            }

            public function listByTenant(string $tenantId, int $limit, int $offset): array
            {
                throw new \\LogicException('Repository must not be called for invalid input.');
            }
        };
        $handler = new GetPaymentHandler($repository);

        try {
            $handler->list('', 20, 0);
            self::fail('An empty tenant must be rejected.');
        } catch (\\DomainException) {
            self::assertTrue(true);
        }

        try {
            $handler->list('tenant-1', 101, 0);
            self::fail('A limit above 100 must be rejected.');
        } catch (\\DomainException) {
            self::assertTrue(true);
        }
    }

    public function testItThrowsWhenThePaymentDoesNotExistInTheTenant(): void
    {
        $repository = new class implements PaymentRepositoryInterface {
            public function findByTenantAndId(string $tenantId, string $paymentId): ?array
            {
                return null;
            }

            public function listByTenant(string $tenantId, int $limit, int $offset): array
            {
                return ['items' => [], 'total' => 0];
            }
        };

        $this->expectException(PaymentNotFoundException::class);

        (new GetPaymentHandler($repository))('tenant-1', 'missing-payment');
    }
}
