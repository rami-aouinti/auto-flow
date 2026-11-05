# AutoFlow observability operations

## Signal pipeline

- Traces: PHP OpenTelemetry SDKs export OTLP/HTTP to Tempo at tempo:4318.
- Metrics: PHP OpenTelemetry SDKs export OTLP/HTTP to Alloy at alloy:4318/v1/metrics; Alloy forwards to Prometheus remote-write.
- Infrastructure metrics: Prometheus scrapes Prometheus, RabbitMQ, PostgreSQL, Redis, Elasticsearch, cAdvisor, node-exporter, Tempo, Loki and Alloy.
- Logs: Alloy tails Docker stdout/stderr and pushes to Loki.
- Grafana provisions Prometheus, Loki and Tempo data sources plus the AutoFlow overview dashboard.

The CoreBundle X-Correlation-ID is an application support identifier, not a W3C trace ID. Distributed trace continuity uses traceparent/tracestate and the configured tracecontext,baggage propagator.

## Start and verify

    docker compose up -d --build
    docker compose ps
    curl -fsS http://localhost:9090/-/ready
    curl -fsS http://localhost:3200/ready
    curl -fsS http://localhost:3100/ready
    curl -fsS http://localhost:3000/api/health
    curl -fsS http://localhost:9090/api/v1/targets

Tempo and Loki can return HTTP 503 briefly while their ingesters wait through the startup readiness grace period. Retry after 20-30 seconds before treating this as a persistent failure.

## Grafana and alert rules

Open http://localhost:3000 and select AutoFlow - Platform Overview in the AutoFlow folder. It includes infrastructure target health, HTTP request rate and latency, Pricing quote calculations, RabbitMQ backlog and Docker logs.

Prometheus evaluates the rules in infrastructure/observability/prometheus/alerts.yml: failed/missing scrape targets, high host CPU, high container memory, PostgreSQL connection saturation and RabbitMQ backlog. Notification delivery still requires an Alertmanager or Grafana contact point configured for the target environment.

## Application metrics and cardinality

Each service owns its HTTP metrics subscriber and business metrics. HTTP metrics use route names and status codes rather than raw paths, IDs, tenant IDs or user IDs to keep label cardinality bounded. Pricing also records quote calculation count and duration.

Never add tokens, personal data, request bodies, raw vehicle IDs, offer IDs or tenant IDs as metric labels or span attributes.

## Troubleshooting

- If up is 0, inspect lastError in Prometheus Targets.
- If Tempo is not ready after startup, inspect docker compose logs --tail=200 tempo.
- If Loki is not ready after startup, inspect docker compose logs --tail=200 loki.
- If app metrics are missing, check OTEL_METRICS_EXPORTER, OTEL_EXPORTER_OTLP_METRICS_ENDPOINT, Alloy logs and the Prometheus remote-write receiver.
- For async trace continuity, producers must inject W3C trace context into message headers and consumers must extract it before starting their processing span. A correlation ID alone does not create a distributed trace.
