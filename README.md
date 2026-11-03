# AutoFlow Mobility Platform

Enterprise mobility and vehicle-leasing portfolio project built with Symfony microservices.

> Independent learning project inspired by a digital vehicle-leasing journey. It does not
> describe MeinAuto.de's internal architecture.

## Stack

- PHP 8.4 CLI and Xdebug 3
- Docker Compose
- PostgreSQL 16, RabbitMQ 4, Redis 7, Elasticsearch 8
- Mailpit for local email testing
- PHP-CS-Fixer, PHP_CodeSniffer, PHPStan, PHPMD, Rector and PHPUnit
- GitHub Actions quality workflow

## Start locally (WSL2)

Docker Desktop with WSL2 integration is required. Local PHP and Composer are not required.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php -v
docker compose exec php php -m | grep -E 'xdebug|intl|pdo_pgsql'
```

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

## Local service endpoints

- RabbitMQ Management: http://localhost:15672 (autoflow / autoflow)
- Mailpit: http://localhost:8025
- PostgreSQL: localhost:5432
- Redis: localhost:6379
- Elasticsearch: http://localhost:9200

Elasticsearch runs as a single local node with security disabled for development only.
Do not expose this configuration to a public network or reuse it in production.

Credentials are for local development only. Never reuse them outside your machine.

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
source of truth for transactional data.

The business microservices will be implemented incrementally. See
[`docs/architecture/overview.md`](docs/architecture/overview.md).
