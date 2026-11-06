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
                self::assertSame('tenant-1', $tenantId);
                self::assertSame('payment-1', $paymentId);

                return $this->payment;
            }
        };

        self::assertSame($payment, (new GetPaymentHandler($repository))('tenant-1', 'payment-1'));
    }

    public function testItThrowsWhenThePaymentDoesNotExistInTheTenant(): void
    {
        $repository = new class implements PaymentRepositoryInterface {
            public function findByTenantAndId(string $tenantId, string $paymentId): ?array
            {
                return null;
            }
        };

        $this->expectException(PaymentNotFoundException::class);

        (new GetPaymentHandler($repository))('tenant-1', 'missing-payment');
    }
}
