# Temporal local development server

Docker Compose runs the official Temporal CLI development server:

- gRPC endpoint: `localhost:7233`
- Web UI: `http://localhost:8233`
- Namespace: `default`
- Persistent local state: Docker volume `temporal-data`, mounted at `/var/lib/temporal`

The command uses `temporal server start-dev` with a persistent SQLite file. This embedded development service is intended for local development and testing only. It is not a production Temporal cluster and does not provide production database topology, HA, TLS, authentication or operational guarantees. For production, deploy the supported Temporal Server with an external persistence database and managed schema lifecycle.

The PHP SDK package is `temporal/sdk`. Its client requires the PHP gRPC extension and its worker runtime uses RoadRunner. The existing service image does not yet include the PHP gRPC extension, so this change adds the Temporal server infrastructure only. A dedicated SDK worker image and a real VehicleOrder Workflow/Activities should be implemented and tested as a separate change rather than shipping a non-runnable placeholder.
