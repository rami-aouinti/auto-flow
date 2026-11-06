<?php

declare(strict_types=1);

namespace App\\Infrastructure\\Observability;

use OpenTelemetry\\API\\Globals;
use OpenTelemetry\\API\\Metrics\\CounterInterface;

final class OrderMetrics
{
    private CounterInterface $created;
    private CounterInterface $transitions;

    public function __construct()
    {
        $meter = Globals::meterProvider()->getMeter('autoflow.order-service', '1.0.0');
        $this->created = $meter->createCounter('orders.created', '{order}', 'Orders created');
        $this->transitions = $meter->createCounter('orders.transitions', '{transition}', 'Order lifecycle transitions');
    }

    public function recordCreated(): void
    {
        $this->created->add(1);
    }

    public function recordTransition(string $status): void
    {
        $this->transitions->add(1, ['status' => $status]);
    }
}
