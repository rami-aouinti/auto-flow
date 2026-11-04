<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultCorrelationHeaderIsValid(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['correlation_id']);
        self::assertSame('X-Correlation-ID', $config['correlation_header']);
    }

    public function testInvalidCorrelationHeaderIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'correlation_header' => 'X Correlation ID',
        ]]);
    }
}
