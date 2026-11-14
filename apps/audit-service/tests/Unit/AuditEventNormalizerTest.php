<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Application\AuditEventNormalizer;
use PHPUnit\Framework\TestCase;
final class AuditEventNormalizerTest extends TestCase
{
    public function testNormalizesCamelCaseProducerEnvelope(): void
    {
        $result = (new AuditEventNormalizer())->normalize([
            'eventId'=>'order-1001-created-v1','eventType'=>'order.created','tenantId'=>'tenant-demo',
            'aggregateType'=>'order','aggregateId'=>'order-1001','occurredAt'=>'2026-11-14T06:30:00Z',
            'payload'=>['status'=>'created'],
        ]);
        self::assertSame('tenant-demo',$result['tenantId']);
        self::assertSame('order-1001-created-v1',$result['event']['eventId']);
        self::assertSame('order.created',$result['event']['eventType']);
        self::assertSame('order',$result['event']['resourceType']);
        self::assertSame('order-1001',$result['event']['resourceId']);
        self::assertSame('system',$result['event']['actorType']);
    }
    public function testAcceptsSnakeCaseAndTenantHeader(): void
    {
        $result=(new AuditEventNormalizer())->normalize(['event_id'=>'payment-1001-captured-v1','event_type'=>'payment.captured','aggregate_id'=>'payment-1001','payload'=>[]],['tenant_id'=>'tenant-demo']);
        self::assertSame('tenant-demo',$result['tenantId']);
        self::assertSame('payment.captured',$result['event']['eventType']);
    }
    public function testRejectsMissingTenantInsteadOfGuessing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AuditEventNormalizer())->normalize(['eventId'=>'order-1001-created-v1','eventType'=>'order.created','payload'=>[]]);
    }
    public function testRejectsInvalidEventIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AuditEventNormalizer())->normalize(['eventId'=>'x','eventType'=>'order.created','tenantId'=>'tenant-demo','payload'=>[]]);
    }
}
