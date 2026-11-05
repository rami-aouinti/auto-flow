<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$offerAutoload = dirname(__DIR__) . '/apps/offer-service/vendor/autoload.php';

if (is_file($offerAutoload)) {
    require_once $offerAutoload;
}
