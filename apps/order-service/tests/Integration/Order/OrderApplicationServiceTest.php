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

        $transitionKey = 'submit-' . Uuid::v7()->toRfc4122();
        $submitted = $this->orders->transition(
            $tenant, $actor, $created['orderId'], OrderStatus::SUBMITTED, $transitionKey,
        );
        $replayedTransition = $this->orders->transition(
            $tenant, $actor, $created['orderId'], OrderStatus::SUBMITTED, $transitionKey,
        );
        self::assertSame('submitted', $submitted['status']);
        self::assertSame($this->sortKeysRecursively($submitted), $this->sortKeysRecursively($replayedTransition));

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

    public function testIdempotencyReplayUsesStableReferencesAndIgnoresRefreshedSnapshots(): void
    {
        $tenant = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $key = 'idempotency-' . Uuid::v7()->toRfc4122();
        $input = $this->validOrderInput();

        $created = $this->orders->create($tenant, $actor, $input, $key);
        $input['quoteSnapshot']['monthlyAmount'] = '999.00';

        $replayed = $this->orders->create($tenant, $actor, $input, $key);
        self::assertSame($created['orderId'], $replayed['orderId']);
        self::assertSame('329.00', $replayed['quoteSnapshot']['monthlyAmount']);

        $existing = $this->orders->findIdempotentCreate(
            $tenant,
            $actor,
            $key,
            $input['offerId'],
            $input['quoteId'],
            $input['vehicleId'],
        );
        self::assertSame($created['orderId'], $existing['orderId']);
    }

    public function testIdempotencyKeyCannotBeReusedWithDifferentReferences(): void
    {
        $tenant = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $key = 'idempotency-' . Uuid::v7()->toRfc4122();
        $input = $this->validOrderInput();

        $this->orders->create($tenant, $actor, $input, $key);
        $input['quoteId'] = Uuid::v7()->toRfc4122();

        $this->expectException(IdempotencyConflictException::class);
        $this->orders->findIdempotentCreate(
            $tenant,
            $actor,
            $key,
            $input['offerId'],
            $input['quoteId'],
            $input['vehicleId'],
        );
    }

    public function testTransitionIdempotencyKeyCannotBeReusedForDifferentCommand(): void
    {
        $tenant = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $created = $this->orders->create(
            $tenant, $actor, $this->validOrderInput(), 'create-' . Uuid::v7()->toRfc4122(),
        );
        $key = 'transition-' . Uuid::v7()->toRfc4122();
        $this->orders->transition($tenant, $actor, $created['orderId'], OrderStatus::SUBMITTED, $key);

        $this->expectException(IdempotencyConflictException::class);
        $this->orders->transition($tenant, $actor, $created['orderId'], OrderStatus::CANCELLED, $key);
    }


    /**
     * JSONB does not preserve object key order. Compare the persisted replay
     * with the original response by value, independently of associative-key order.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sortKeysRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) && !array_is_list($item)) {
                $value[$key] = $this->sortKeysRecursively($item);
            }
        }

        ksort($value);

        return $value;
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
