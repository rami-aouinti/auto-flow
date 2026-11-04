<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CorrelationContextTest extends TestCase
{
    public function testContextCanBeSetAndCleared(): void
    {
        $context = new CorrelationContext();
        $context->set('request-123');

        self::assertSame('request-123', $context->get());

        $context->clear();

        self::assertNull($context->get());
    }

    public function testScopedContextRestoresPreviousValue(): void
    {
        $context = new CorrelationContext();
        $context->set('outer');

        $result = $context->run('inner', static function () use ($context): string {
            self::assertSame('inner', $context->get());

            return 'handled';
        });

        self::assertSame('handled', $result);
        self::assertSame('outer', $context->get());
    }

    public function testScopedContextRestoresPreviousValueAfterFailure(): void
    {
        $context = new CorrelationContext();
        $context->set('outer');

        /** @var callable(): mixed $work */
        $work = static function (): mixed {
            throw new RuntimeException('failure');
        };

        try {
            $context->run('inner', $work);
            self::fail('Expected callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('failure', $exception->getMessage());
        }

        self::assertSame('outer', $context->get());
    }
}
