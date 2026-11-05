<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Globals;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Records low-cardinality HTTP server metrics.
 * Telemetry failures must never affect API availability.
 */
final class HttpMetricsSubscriber implements EventSubscriberInterface
{
    private const STARTED_AT = '_autoflow_metrics_started_at';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::STARTED_AT, hrtime(true));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $startedAt = $request->attributes->get(self::STARTED_AT);
        if (!is_int($startedAt)) {
            return;
        }

        try {
            $route = $request->attributes->get('_route');
            $attributes = [
                'http.request.method' => $request->getMethod(),
                'http.route' => is_string($route) && $route !== '' ? $route : 'unmatched',
                'http.response.status_code' => $event->getResponse()->getStatusCode(),
            ];
            $duration = max(0.0, (hrtime(true) - $startedAt) / 1_000_000_000);
            $meter = Globals::meterProvider()->getMeter('autoflow.http', '1.0.0');

            $meter->createCounter(
                'http.server.requests',
                '{request}',
                'Number of completed HTTP server requests.',
            )->add(1, $attributes);

            $meter->createHistogram(
                'http.server.request.duration',
                's',
                'Duration of completed HTTP server requests.',
            )->record($duration, $attributes);
        } catch (Throwable $exception) {
            error_log('[AutoFlow HTTP metrics] ' . $exception->getMessage());
        }
    }
}
