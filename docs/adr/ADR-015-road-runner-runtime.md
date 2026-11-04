# ADR-015: RoadRunner as the HTTP and gRPC runtime

- **Status:** Accepted for implementation
- **Date:** 2026-11-04

## Decision

Use RoadRunner for services that need to expose both HTTP REST and gRPC. Keep protocol listeners explicit and configurable per service. HTTP remains the public REST interface; gRPC is an additional internal service-to-service interface.

## Boundaries

- RoadRunner owns process lifecycle, worker supervision and protocol listeners.
- Symfony HttpKernel remains the HTTP application kernel.
- Generated gRPC service adapters translate Protobuf messages into Application inputs.
- Application handlers own use-case execution and are shared by both adapters.
- Domain and Application must not import RoadRunner, PSR-7, Symfony HttpFoundation, gRPC or generated Protobuf classes.

## Operational requirements

- Configure HTTP and gRPC listen addresses independently.
- Use deadlines and bounded timeouts for outbound gRPC calls.
- Propagate W3C trace context and a separate correlation ID.
- Map failures to protocol-native status codes without exposing stack traces.
- Support graceful shutdown and worker recycling.
- Expose liveness and readiness separately; readiness must fail when required checks are absent or unhealthy.
- Do not publish gRPC ports externally unless the deployment topology requires it.

## Migration gate

A service must pass its HTTP and gRPC integration suites using the RoadRunner runtime before its Docker Compose command or production deployment is switched from the existing HTTP runtime.
