<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

/** Records low-cardinality offer lifecycle business metrics. */
final class OfferMetrics
{
    private ?MeterProvider $meterProvider = null;

    public function recordLifecycleEvent(string $action): void
    {
        try {
            $meterProvider = $this->meterProvider();

            $meterProvider
                ->getMeter('autoflow.offer-service', '1.0.0')
                ->createCounter(
                    'offer.lifecycle.events',
                    'offers',
                    'Number of successful offer lifecycle actions.',
                )
                ->add(1, ['action' => $action]);

            // RoadRunner keeps PHP workers alive across requests. The PHP SDK's
            // metric reader does not run a background export loop, so flush
            // synchronously after recording to make the event visible promptly.
            if (!$meterProvider->forceFlush()) {
                error_log('[AutoFlow Offer metrics] OpenTelemetry metric export returned failure.');
            }
        } catch (Throwable $exception) {
            // Telemetry must never break offer creation or lifecycle transitions.
            error_log('[AutoFlow Offer metrics] ' . $exception->getMessage());
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
