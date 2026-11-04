# 14 — Local developer tooling: Adminer and Swagger UI

## 1. Purpose

The local development environment provides a browser-based database console and an interactive REST API explorer. These are developer tools, not runtime dependencies of the business services.

- **Adminer** provides a lightweight web UI for inspecting and managing the local PostgreSQL database.
- **NelmioApiDocBundle** generates OpenAPI 3 documentation for each Symfony REST API.
- **Swagger UI** renders the OpenAPI contract and lets developers send requests to the local service.

## 2. Adminer

Adminer is defined in the root `compose.yaml`:

- Container image: `adminer:5.4.1`
- Browser URL: `http://localhost:8083`
- Default server: `postgres` (the Docker Compose service name)
- Port: `127.0.0.1:8083` by default; override with `ADMINER_PORT`
- Database: `autoflow` by default

Use the credentials configured in `.env`:

| Adminer field | Value |
| --- | --- |
| System | PostgreSQL |
| Server | `postgres` |
| Username | `POSTGRES_USER` |
| Password | `POSTGRES_PASSWORD` |
| Database | `POSTGRES_DB` |

Adminer connects to PostgreSQL over the private Compose network. Its host port is bound to loopback so it is not exposed to the LAN by default.

Adminer is for local development and must not be published as a production administration endpoint. Do not reuse the example database credentials outside a disposable local environment.

## 3. Swagger UI and OpenAPI

Each REST microservice owns its own API contract and documentation configuration. The CoreBundle does not own business endpoints or business schemas.

| Service | Swagger UI | OpenAPI JSON |
| --- | --- | --- |
| Offer | `http://localhost:8081/api/doc` | `http://localhost:8081/api/doc.json` |
| Pricing | `http://localhost:8082/api/doc` | `http://localhost:8082/api/doc.json` |

The UI is interactive. Select an endpoint, inspect its parameters and request body, choose **Try it out**, and execute it against the local service.

The current Pricing contract documents:
- `POST /api/pricing/quotes`, including all required request fields and the calculation response.
- `422` for invalid quote input.
- `GET /api/pricing/{vehicleId}` as a deprecated demonstration endpoint.

The Offer contract documents `GET /api/offers/{vehicleId}` and explains its downstream Pricing call.

OpenAPI documentation is intentionally REST-focused. gRPC contracts remain defined by service-owned `.proto` files and are tested with tools such as `grpcurl`; Swagger/OpenAPI is not a replacement for gRPC tooling.

## 4. Development-only dependency and route isolation

NelmioApiDocBundle, TwigBundle and Symfony Asset are installed in each service's `require-dev` dependencies. Their bundles are registered for the `dev` environment only.

Both the Nelmio configuration and the Swagger routes live under environment-specific directories:

- `config/packages/dev/nelmio_api_doc.yaml`
- `config/routes/dev/nelmio_api_doc.yaml`

The local Compose build sets `COMPOSER_NO_DEV` to an empty string so the development images include the Swagger dependencies. The service Dockerfile keeps `--no-dev` as its default, so a production image built without the Compose development override does not install these packages.

The API documentation routes are not registered in `prod`. Do not add them to global route configuration.

## 5. Local workflow

Start or rebuild the development stack:

```bash
cp .env.example .env
docker compose up -d --build
docker compose ps
```

Open the Swagger UI for the service being developed. To export both OpenAPI specifications from the running containers:

```bash
make api-docs
```

Open Adminer and connect to the PostgreSQL Compose service using the values from `.env`.

## 6. Adding a new REST microservice

When a new Symfony service exposes REST endpoints:

1. Add `nelmio/api-doc-bundle`, `symfony/asset` and `symfony/twig-bundle` to its `require-dev`.
2. Register NelmioApiDocBundle and TwigBundle for `dev` only in `config/bundles.php`.
3. Add the service-specific `config/packages/dev/nelmio_api_doc.yaml` with the API title, version, local server and path filter.
4. Add `config/routes/dev/nelmio_api_doc.yaml` for the JSON and Swagger UI routes.
5. Document request/response schemas and error cases from the actual service contract.
6. Verify the generated document and test the endpoints through Swagger UI.
7. Confirm that the documentation routes are absent from the production environment.

Never copy another service's business schemas into the CoreBundle. The shared package may standardize generic technical conventions, but each bounded context owns its OpenAPI contract.
