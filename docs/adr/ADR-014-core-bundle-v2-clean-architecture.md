# ADR-014: CoreBundle v2 — Clean Architecture and transport adapters

- **Status:** Accepted — implementation in progress
- **Date:** 2026-11-04
- **Decision owners:** AutoFlow engineering

## Context

The CoreBundle provides shared technical capabilities for Symfony microservices. The package includes correlation handling, health checks, HTTP client propagation, an integration-event envelope, Messenger publishing, JSON/Protobuf serializer adapters and RoadRunner HTTP/gRPC runners. It is still being hardened: health routes must be explicitly imported by consumers, optional runtimes must not burden every service, and runtime migration is not complete until each service has its own contract and integration tests.

The `bro-world-core` repository is a useful reference for capability breadth and separation of Domain, Application, Infrastructure and Transport. Its framework-coupled abstractions and application policies must not be copied into inner layers.

## Decision

1. **Domain** contains no Symfony, Doctrine, HTTP, RoadRunner, gRPC or Protobuf dependency. The CoreBundle defines no shared business model.
2. **Application** defines transport-neutral ports and contracts. It may depend on Domain, but not on adapters.
3. **Infrastructure** implements ports using Symfony, Messenger, HTTP clients, Protobuf, RoadRunner and observability libraries.
4. **Transport** maps HTTP/gRPC protocol messages to application inputs and outputs.
5. REST and gRPC adapters call the same Application handlers. Business rules are never duplicated in controllers or generated gRPC service classes.
6. Each bounded context owns its service-specific `.proto` files and generated code.
7. The CoreBundle contains technical cross-cutting capabilities only. It must not become a shared business-domain package.
8. RoadRunner and Protobuf integrations are optional dependencies. Consumers that do not use those adapters must still be able to compile their Symfony container.

## Dependency rules

```text
Transport / Infrastructure  --->  Application  --->  Domain
         |                           |
         +---- may depend on --------+
```

- Domain and Application must not import Symfony, Doctrine, PSR-7, RoadRunner or generated Protobuf types.
- Protobuf messages are mapped to application DTOs at the gRPC boundary.
- HTTP controllers and gRPC service adapters orchestrate, they do not own business rules.
- EventBus dispatch is not a Transactional Outbox.
- Database schemas, Outbox persistence, broker topology, retry/DLQ policy, idempotency storage and Temporal workflows remain owned by the appropriate service/platform context.

## Target package layout

```text
packages/core-bundle/src/
├── Application/       # ports and transport-neutral contracts
├── Context/           # scoped technical context (correlation)
├── Health/            # liveness/readiness contracts and controller
├── Http/              # HTTP-specific adapters and response factories
├── Infrastructure/    # Symfony, Messenger, serialization and runtime adapters
├── Messaging/         # integration envelope and correlation metadata
└── DependencyInjection/
```

## Runtime strategy

RoadRunner is the selected runtime for services that need HTTP and gRPC in the same deployment. Migration is service-by-service and must preserve existing HTTP routes. Each service must provide:
- an HTTP worker that converts PSR-7 at the outermost boundary;
- a gRPC worker that registers service-owned generated adapters;
- service-owned `.proto` contracts and generated code;
- explicit listener addresses, worker lifecycle and graceful shutdown;
- integration tests proving HTTP and gRPC invoke the same Application handler.

Do not make Domain or Application depend on RoadRunner's worker API. Do not expose internal gRPC listeners publicly without an explicit network/security design.

## Serialization

Application code depends on `SerializerInterface`. JSON returns native PHP values. Typed DTO hydration must use an explicit mapper/serializer; it must not silently ignore the requested type. Protobuf decoding requires an expected generated message class and must never select a PHP class from an untrusted payload.

## Correlation and tracing

Correlation ID, W3C trace ID, tenant ID and authenticated principal are distinct values. Correlation metadata is diagnostic only and must never be used for authorization or tenant isolation. HTTP and Messenger context must restore the previous value in a `finally`/request-finish boundary so long-running workers cannot leak state.

## Implementation status — 2026-11-04

The hardening branch:
- imports CoreBundle health routes in Offer and Pricing;
- makes JSON typed decode fail explicitly;
- restores previous correlation context after HTTP requests and Messenger handling;
- adds a Messenger correlation stamp/middleware;
- hardens RoadRunner HTTP response handling and worker reset;
- makes RoadRunner and Protobuf libraries optional;
- adds an RFC 9457-style Problem Details factory;
- validates the configured correlation header;
- adds unit tests for these contracts.

These are source changes, not test results. PHPUnit, Composer dependency resolution, PHPStan, PHPCS and real RoadRunner HTTP/gRPC integration still require CI verification before the bundle can be called production-ready.
