<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Tests\Integration;

use AutoFlow\Notification\Infrastructure\Persistence\NotificationDeliveryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryRepositoryTest extends TestCase
{
    public function testACompletedEventCannotBeDeliveredTwice(): void
    {
        $url = getenv('NOTIFICATION_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set NOTIFICATION_DATABASE_URL to run PostgreSQL integration tests.');
        }

        $parts = parse_url($url);
        self::assertIsArray($parts);
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 5432, ltrim((string) ($parts['path'] ?? '/autoflow'), '/')),
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $repository = new NotificationDeliveryRepository($pdo);
        $eventId = 'integration-' . bin2hex(random_bytes(8));

        self::assertTrue($repository->begin($eventId, 'tenant-integration', 'offer-integration'));
        $repository->markSent($eventId, 'integration@example.test');
        self::assertFalse($repository->begin($eventId, 'tenant-integration', 'offer-integration'));

        $delete = $pdo->prepare('DELETE FROM notification_deliveries WHERE event_id = :event_id');
        $delete->execute(['event_id' => $eventId]);
    }
}
