<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Application\AuditPayloadRedactor;
use PHPUnit\Framework\TestCase;
final class AuditPayloadRedactorTest extends TestCase
{
    public function testRedactsSensitiveFieldsRecursively():void
    {
        $result=(new AuditPayloadRedactor())->redact(['status'=>'approved','access_token'=>'secret-value','customer'=>['email'=>'person@example.test','name'=>'Ada']]);
        self::assertSame('approved',$result['status']);
        self::assertSame('[REDACTED]',$result['access_token']);
        self::assertSame('[REDACTED]',$result['customer']['email']);
        self::assertSame('Ada',$result['customer']['name']);
    }
    public function testTruncatesLongStrings():void
    {
        $result=(new AuditPayloadRedactor())->redact(['description'=>str_repeat('x',2100)]);
        self::assertSame(2000,mb_strlen($result['description']));
    }
}
