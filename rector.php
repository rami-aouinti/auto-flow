<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/apps',
        __DIR__ . '/packages',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php84: true)
    ->withSkip([
        NewMethodCallWithoutParenthesesRector::class,
    ]);
