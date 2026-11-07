<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/apps', __DIR__ . '/packages', __DIR__ . '/tests'])
    ->name('*.php')
    ->ignoreVCSIgnored(true)
    // Symfony Flex may rewrite these generated manifests during Composer install.
    ->notName('bundles.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
