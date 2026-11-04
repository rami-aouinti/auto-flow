<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use PHPUnit\Framework\TestCase;

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
}
