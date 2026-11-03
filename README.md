# AutoFlow Mobility Platform

Enterprise mobility and vehicle-leasing portfolio project built with Symfony microservices.

> Independent learning project inspired by a digital vehicle-leasing journey. It does not
> describe MeinAuto.de's internal architecture.

## Stack

- PHP 8.4 CLI and Xdebug 3
- Docker Compose
- PostgreSQL 16, RabbitMQ 4, Redis 7, Elasticsearch 8 and Kibana
- Mercure Hub for real-time Server-Sent Events
- Prometheus and Grafana for metrics and dashboards
- Loki and Grafana Alloy for centralized container logs
- Tempo and OpenTelemetry OTLP receivers for distributed traces
- PostgreSQL, Redis, Elasticsearch, RabbitMQ and container metrics exporters
- Mailpit for local email testing
- PHP-CS-Fixer, PHP_CodeSniffer, PHPStan, PHPMD, Rector and PHPUnit
- GitHub Actions quality workflow

## Start locally (WSL2)

Docker Desktop with WSL2 integration is required. Local PHP and Composer are not required.
The complete observability stack is resource-intensive; allocate at least 8 GB RAM to Docker Desktop.

```bash
cp .env.example .env
# Replace the local-only JWT and Grafana passwords in .env before use.
docker compose up -d --build
docker compose ps
docker compose exec php composer install
docker compose exec php php -v
```

## Local interfaces and endpoints

| Service | URL | Purpose |
| --- | --- | --- |
| RabbitMQ Management | http://localhost:15672 | Exchanges, queues, consumers, message rates and connections |
| Elasticsearch | http://localhost:9200 | Search and indexing API |
| Kibana | http://localhost:5601 | Elasticsearch indices, documents, queries and dashboards |
| Mercure Hub | http://localhost:3001/.well-known/mercure | Real-time SSE hub |
| Mercure health | http://localhost:3001/healthz | Hub health endpoint |
| Grafana | http://localhost:3000 | Central dashboards (credentials from .env) |
| Prometheus | http://localhost:9090 | Metrics, targets and PromQL |
| Loki | http://localhost:3100 | Log aggregation API (explore through Grafana) |
| Tempo | http://localhost:3200 | Distributed trace backend (explore through Grafana) |
| Alloy | http://localhost:12345 | Log collector status and component UI |
| Mailpit | http://localhost:8025 | Local email inbox |
| PostgreSQL | localhost:5432 | Relational database |
| Redis | localhost:6379 | Cache and ephemeral data |
| OTLP gRPC / HTTP | localhost:4317 / 4318 | OpenTelemetry trace ingestion |

RabbitMQ Management is the operational UI for the RabbitMQ transport used by Symfony Messenger:
it lets you inspect queues, ready/unacknowledged messages, consumers, exchanges and rates.
It is not a Symfony Messenger application dashboard. A Symfony-level failed-message/retry
screen can be added later in an application admin/API, while failed transports can be managed
with Messenger commands such as `messenger:failed:show` and `messenger:failed:retry`.

## Observability architecture

- Grafana is provisioned with Prometheus, Loki and Tempo data sources.
- Prometheus scrapes RabbitMQ, PostgreSQL, Redis, Elasticsearch, cAdvisor and Node Exporter.
- Grafana Alloy discovers Docker containers and ships their stdout/stderr logs to Loki.
- Applications can export OpenTelemetry traces to Tempo using OTLP gRPC (4317) or HTTP (4318).
- Kibana is version-pinned to the same 8.17.5 release as Elasticsearch.

The Docker socket is mounted read-only into Alloy so it can discover container logs. Access to
the Docker socket is highly privileged; this setup is intended for a trusted local development
machine only. The exporters and monitoring endpoints are also intended for local development.

## Mercure and real-time notifications

Symfony applications publish updates to the internal URL
`http://mercure/.well-known/mercure`. Browser clients subscribe through
`http://localhost:3001/.well-known/mercure`. Publisher and subscriber JWT keys are
configured separately in `.env`. Generate strong random values before sharing the setup.
Do not expose the local HTTP hub or development secrets to the public internet.

## Quality commands

Run these from the repository root:

| Command | Purpose |
| --- | --- |
| `make quality` | Run all quality checks and Composer audit |
| `make cs` / `make cs-fix` | Check / automatically fix formatting |
| `make phpcs` | Check PSR-12 coding standard |
| `make phpstan` | Static analysis, level 8 |
| `make phpmd` | Complexity, design and code-smell checks |
| `make rector` / `make rector-fix` | Preview / apply automated refactoring |
| `make test` | Run PHPUnit |
| `make coverage` | Run tests with Xdebug coverage |
| `make audit` | Audit Composer dependencies |
| `make shell` | Open a shell in the PHP container |
| `make down` | Stop the stack |

## Xdebug and PhpStorm

1. Configure the PHP CLI interpreter in PhpStorm to use the Docker Compose service `php`.
2. Enable listening for PHP debug connections and use port `9003`.
3. Add a PhpStorm server named `AutoFlow` with path mapping from the project root to
   `/var/www/auto-flow`.
4. Xdebug is configured with trigger mode. For a CLI debugging session, run:

```bash
docker compose exec -e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=1 php php -v
```

For coverage, the Composer coverage script activates `XDEBUG_MODE=coverage`.

## Architecture direction

This repository will contain independently deployable Symfony services in a monorepo.
Each service owns its database and domain model. REST is used for synchronous interactions;
RabbitMQ/Symfony Messenger for asynchronous messages; Temporal for durable long-running
workflows. Transactional Outbox and idempotent consumers will protect event delivery.

Elasticsearch will support vehicle search, filtering and indexing; PostgreSQL remains the
source of truth for transactional data. Mercure is for pushing live updates to clients, not
for replacing RabbitMQ or Temporal in backend workflows.

The business microservices will be implemented incrementally. See
[`docs/architecture/overview.md`](docs/architecture/overview.md).
