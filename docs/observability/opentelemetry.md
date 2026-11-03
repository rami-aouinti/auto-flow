# Distributed tracing with OpenTelemetry

AutoFlow uses OpenTelemetry for vendor-neutral distributed tracing. Tempo receives OTLP
traces, and Grafana is the interface used to search and inspect them.

## Local demonstration services

Two small Symfony 7.4 applications demonstrate propagation across an HTTP boundary:

- Offer Service: http://localhost:8081
- Pricing Service: http://localhost:8082

The Offer Service calls the Pricing Service through Symfony HttpClient. Both applications
export traces to Tempo using OTLP over HTTP/protobuf.

These are deliberately small technical demonstration services. Their pricing response is
static demo data; they are not yet the production Offer or Pricing bounded contexts.

## Start and verify

Build and start the stack:

```bash
docker compose up -d --build
docker compose ps
```

Trigger a request that crosses both services:

```bash
curl http://localhost:8081/api/offers/demo-vehicle-001
```

Check the health endpoints:

```bash
curl http://localhost:8081/health
curl http://localhost:8082/health
```

Open Grafana at http://localhost:3000, then use Explore and select the Tempo data source.
Search for the service name `autoflow-offer-service` or `autoflow-pricing-service`.
Open a trace to inspect the incoming HTTP request, the manual offer/pricing spans and the
outgoing HTTP client span.

## How propagation works

The OpenTelemetry PHP extension enables zero-code hooks. The Symfony auto-instrumentation
package creates framework and HTTP client spans and propagates W3C Trace Context. The SDK
and OTLP exporter send the spans to Tempo.

Each application also creates a small business-operation span:

- `offer.compose`
- `pricing.calculate`

The active context is attached while the operation runs and detached in a `finally` block.
This keeps child spans correlated and prevents context leakage.

## Configuration

The Compose services set:

- `OTEL_PHP_AUTOLOAD_ENABLED=true`
- `OTEL_TRACES_EXPORTER=otlp`
- `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`
- `OTEL_EXPORTER_OTLP_ENDPOINT=http://tempo:4318`
- `OTEL_PROPAGATORS=tracecontext,baggage`
- `OTEL_METRICS_EXPORTER=none` and `OTEL_LOGS_EXPORTER=none`

Health-check URLs are excluded from tracing to avoid polluting the trace search.

## Production considerations

- Generate and commit a Composer lock file for each service before production deployment.
- Pin and regularly update PHP extension and Composer dependency versions.
- Use a collector between services and Tempo for buffering, sampling and routing at scale.
- Configure tail-based or parent-based sampling deliberately.
- Never attach personal data, credentials, tokens or full request bodies to spans.
- Keep trace attributes low-cardinality; do not use arbitrary user IDs as metric labels.
- Add instrumentation for database, cache, Messenger and external API clients as those
  integrations are introduced.
