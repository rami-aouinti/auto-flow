<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testComposerProjectMetadataIsAvailable(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $composerFile = $projectRoot . '/composer.json';

        self::assertFileExists($composerFile);

        $composerConfig = json_decode(
            (string) file_get_contents($composerFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('rami-aouinti/auto-flow', $composerConfig['name'] ?? null);
        self::assertSame('project', $composerConfig['type'] ?? null);
    }
}
