<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Globals;
use Throwable;

/** Records low-cardinality offer lifecycle business metrics. */
final class OfferMetrics
{
    public function recordLifecycleEvent(string $action): void
    {
        try {
            Globals::meterProvider()
                ->getMeter('autoflow.offer-service', '1.0.0')
                ->createCounter(
                    'offer.lifecycle.events',
                    'offers',
                    'Number of successful offer lifecycle actions.',
                )
                ->add(1, ['action' => $action]);
        } catch (Throwable $exception) {
            // Telemetry must never break offer creation or lifecycle transitions.
            error_log('[AutoFlow Offer metrics] ' . $exception->getMessage());
        }
    }
}
