<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
use AutoFlow\CoreBundle\Health\HealthController;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testReadinessFailsWhenNoChecksAreRegistered(): void
    {
        $response = (new HealthController([]))->ready();

        self::assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('unavailable', $payload['status']);
    }

    public function testReadinessDoesNotExposeInternalCheckDetails(): void
    {
        $check = new class () implements HealthCheckInterface {
            public function name(): string
            {
                return 'database';
            }

            public function check(): HealthCheckResult
            {
                return new HealthCheckResult(false, ['dsn' => 'postgres://secret']);
            }
        };

        $response = (new HealthController([$check]))->ready();
        $content = (string) $response->getContent();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('postgres://secret', $content);
    }
}
