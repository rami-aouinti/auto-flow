<?php

declare(strict_types=1);

use App\Kernel;
use AutoFlow\CoreBundle\Infrastructure\RoadRunner\SymfonyHttpWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

(new SymfonyHttpWorker())->run(new Kernel(
    $_SERVER['APP_ENV'] ?? 'prod',
    filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
));
