<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order;

use App\Application\IdempotencyConflictException;
use App\Application\OrderApplicationService;
use App\Application\OrderNotFoundException;
use App\Domain\OrderStatus;
use AutoFlow\CoreBundle\Context\CorrelationContext;
use App\Infrastructure\Persistence\Doctrine\DoctrineOrderRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrderApplicationServiceTest extends TestCase
{
    private OrderApplicationService $orders;

    protected function setUp(): void
    {
        $databaseUrl = getenv('ORDER_TEST_DATABASE_URL');
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            self::markTestSkipped('ORDER_TEST_DATABASE_URL is required for PostgreSQL integration tests.');
        }

        $parts = parse_url($databaseUrl);
        if (!is_array($parts)) {
            self::fail('ORDER_TEST_DATABASE_URL is not a valid database URL.');
        }

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 5432,
            'dbname' => ltrim($parts['path'] ?? '/autoflow', '/'),
            'user' => rawurldecode($parts['user'] ?? ''),
            'password' => rawurldecode($parts['pass'] ?? ''),
        ]);
        $this->orders = new OrderApplicationService(new DoctrineOrderRepository($connection, new CorrelationContext()));
    }

    public function testCreationIsIdempotentAndLifecycleIsAudited(): void
    {
        $tenant = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $input = $this->validOrderInput();
        $key = 'integration-' . Uuid::v7()->toRfc4122();

        $created = $this->orders->create($tenant, $actor, $input, $key);
        $replayed = $this->orders->create($tenant, $actor, $input, $key);

        self::assertSame($created['orderId'], $replayed['orderId']);
        self::assertSame('draft', $created['status']);

        $submitted = $this->orders->transition(
            $tenant,
            $actor,
            $created['orderId'],
            OrderStatus::SUBMITTED,
        );
        self::assertSame('submitted', $submitted['status']);

        $history = $this->orders->history($tenant, $created['orderId']);
        self::assertCount(2, $history);
        self::assertSame('draft', $history[0]['to_status']);
        self::assertSame('submitted', $history[1]['to_status']);
    }

    public function testTenantCannotReadAnotherTenantsOrder(): void
    {
        $ownerTenant = Uuid::v7()->toRfc4122();
        $otherTenant = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $created = $this->orders->create(
            $ownerTenant,
            $actor,
            $this->validOrderInput(),
            'tenant-isolation-' . Uuid::v7()->toRfc4122(),
        );

        $this->expectException(OrderNotFoundException::class);
        $this->orders->get($otherTenant, $created['orderId']);
    }

    public function testIdempotencyKeyCannotBeReusedWithDifferentPayload(): void
    {
        $tenant = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $key = 'idempotency-' . Uuid::v7()->toRfc4122();
        $input = $this->validOrderInput();

        $this->orders->create($tenant, $actor, $input, $key);
        $input['quoteSnapshot']['monthlyAmount'] = '999.00';

        $this->expectException(IdempotencyConflictException::class);
        $this->orders->create($tenant, $actor, $input, $key);
    }

    /** @return array<string, mixed> */
    private function validOrderInput(): array
    {
        return [
            'offerId' => Uuid::v7()->toRfc4122(),
            'quoteId' => Uuid::v7()->toRfc4122(),
            'vehicleId' => Uuid::v7()->toRfc4122(),
            'offerSnapshot' => ['version' => 1, 'validUntil' => '2030-01-01T00:00:00Z'],
            'quoteSnapshot' => ['currency' => 'EUR', 'monthlyAmount' => '329.00', 'durationMonths' => 48],
            'vehicleSnapshot' => ['manufacturer' => 'Example Motors', 'model' => 'E-1'],
        ];
    }
}
