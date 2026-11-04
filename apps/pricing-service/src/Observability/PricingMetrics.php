<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Globals;
use Throwable;

/** Records low-cardinality Pricing Service operational metrics. */
final class PricingMetrics
{
    public function recordCalculation(float $durationMilliseconds, string $result): void
    {
        try {
            $meter = Globals::meterProvider()->getMeter('autoflow.pricing-service', '1.0.0');
            $meter->createCounter(
                'pricing.quote.calculations',
                'quotes',
                'Number of quote calculation requests by outcome.',
            )->add(1, ['result' => $result]);
            $meter->createHistogram(
                'pricing.quote.calculation.duration',
                'ms',
                'Quote calculation request duration in milliseconds.',
            )->record($durationMilliseconds, ['result' => $result]);
        } catch (Throwable $exception) {
            // Telemetry must never break quote calculation or API availability.
            error_log('[AutoFlow Pricing metrics] ' . $exception->getMessage());
        }
    }
}
