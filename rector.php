<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/apps',
        __DIR__ . '/packages',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php84: true);
