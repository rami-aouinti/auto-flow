<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

/** Records low-cardinality Pricing Service operational metrics. */
final class PricingMetrics
{
    private ?MeterProvider $meterProvider = null;

    public function recordCalculation(float $durationMilliseconds, string $result): void
    {
        try {
            $meterProvider = $this->meterProvider();
            $meter = $meterProvider->getMeter('autoflow.pricing-service', '1.0.0');

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

            // RoadRunner keeps PHP workers alive across requests. The PHP SDK's
            // metric reader does not run a background export loop, so flush
            // synchronously after recording to make the event visible promptly.
            if (!$meterProvider->forceFlush()) {
                error_log('[AutoFlow Pricing metrics] OpenTelemetry metric export returned failure.');
            }
        } catch (Throwable $exception) {
            // Telemetry must never break quote calculation or API availability.
            error_log('[AutoFlow Pricing metrics] ' . $exception->getMessage());
        }
    }

    private function meterProvider(): MeterProvider
    {
        if ($this->meterProvider instanceof MeterProvider) {
            return $this->meterProvider;
        }

        $endpoint = getenv('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT')
            ?: 'http://alloy:4318/v1/metrics';

        $transport = (new PsrTransportFactory())->create(
            $endpoint,
            'application/x-protobuf',
        );

        $reader = new ExportingReader(new MetricExporter($transport));
        $provider = MeterProvider::builder()
            ->setResource(ResourceInfoFactory::defaultResource())
            ->addReader($reader)
            ->build();

        if (!$provider instanceof MeterProvider) {
            throw new \RuntimeException('Could not initialize the OpenTelemetry meter provider.');
        }

        return $this->meterProvider = $provider;
    }
}
