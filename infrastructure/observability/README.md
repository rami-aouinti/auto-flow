# AutoFlow observability

## Metrics pipeline

Application metrics use **OpenTelemetry OTLP push**, not Prometheus scraping of each
Symfony service:

```text
Offer / Pricing / Vehicle Catalog (PHP OpenTelemetry SDK)
        |
        | OTLP/HTTP protobuf (POST /v1/metrics)
        v
Grafana Alloy :4318
  - enriches datapoints with service_name and deployment_environment
  - batches metric data
  - converts OTLP metrics to Prometheus time series
        |
        | Prometheus remote-write
        v
Prometheus :9090  ---> Grafana :3002
```

The Symfony services intentionally do not expose a `/metrics` HTTP route. A request
to `http://localhost:8081/metrics`, `:8082/metrics`, or `:8084/metrics` is expected
to return 404. Do not add scrape jobs for those routes.

The OTLP receiver is reachable to containers on the Compose network at
`http://alloy:4318/v1/metrics`. The Prometheus remote-write receiver is enabled
with `--web.enable-remote-write-receiver`.

## PHP metric export cadence

The three HTTP-facing PHP services set:

- `OTEL_METRICS_EXPORTER=otlp`
- `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`
- `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://alloy:4318/v1/metrics`
- `OTEL_METRIC_EXPORT_INTERVAL=10000` (10 seconds for local feedback)
- `OTEL_METRIC_EXPORT_TIMEOUT=5000`

The shorter interval is intended for local development dashboards. For production,
choose an interval based on traffic, telemetry volume, and backend capacity.

## Main application metrics

| Instrument | Prometheus series | Meaning |
| --- | --- | --- |
| HTTP request counter | `http_server_requests_total` | Completed main HTTP requests |
| HTTP duration histogram | `http_server_request_duration_seconds_bucket` | Request latency in seconds |
| Pricing counter | `pricing_quote_calculations_total` | Quote calculations by result |
| Notification state metric | `autoflow_notification_deliveries_total` | Delivery records by current status |

HTTP labels include `service_name`, `http_route`, `http_request_method`, and
`http_response_status_code`. Keep route labels low-cardinality; never attach raw
URLs, customer IDs, offer IDs, email addresses, or tokens.

## Useful PromQL

Requests per second by service:

```promql
sum by (service_name) (rate(http_server_requests_total[5m]))
```

HTTP 5xx percentage:

```promql
100 * sum(rate(http_server_requests_total{http_response_status_code=~"5.."}[5m]))
/ clamp_min(sum(rate(http_server_requests_total[5m])), 0.000001)
```

HTTP p95 latency in seconds:

```promql
histogram_quantile(
  0.95,
  sum by (le, service_name) (rate(http_server_request_duration_seconds_bucket[5m]))
)
```

Container CPU cores:

```promql
sum by (name) (rate(container_cpu_usage_seconds_total{name!=""}[5m]))
```

Container memory working set:

```promql
sum by (name) (container_memory_working_set_bytes{name!=""})
```

RabbitMQ ready messages (the configured RabbitMQ exporter currently exposes an
aggregate series in this local setup, so the dashboard labels it as global):

```promql
sum(rabbitmq_queue_messages_ready)
```

## User interfaces

- Prometheus: http://localhost:9090
- Grafana: http://localhost:3002
- Alloy component UI: http://localhost:12345
- Tempo: http://localhost:3200

Grafana provisions Prometheus, Loki, and Tempo datasources and the
**AutoFlow - Platform Overview** dashboard from this directory tree.
