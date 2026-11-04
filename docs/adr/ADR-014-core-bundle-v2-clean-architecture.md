# ADR-014: CoreBundle v2 — Clean Architecture and transport adapters

- **Status:** Proposed implementation
- **Date:** 2026-11-04
- **Decision owners:** AutoFlow engineering

## Context

The first CoreBundle iteration provides correlation IDs, health routes, an HTTP client decorator, a basic integration-event envelope and Messenger middleware. It is not yet a complete shared platform foundation. In particular, serialization is not an explicit port, transport concerns are not separated into adapters, and a PHP gRPC server runtime has not been wired into the services.

The existing `bro-world-core` repository is a useful structural reference because it separates Domain, Application, Infrastructure and Transport. Its Symfony-coupled abstractions must not be copied into the inner layers.

## Decision

Evolve the package around these dependency rules:

1. **Domain** contains no Symfony, Doctrine, HTTP, RoadRunner, gRPC or Protobuf dependency.
2. **Application** defines ports and use-case contracts. It may depend on Domain, but not on adapters.
3. **Infrastructure** implements ports using Symfony, Messenger, HTTP clients, Protobuf, OpenTelemetry and persistence libraries.
4. **Transport** contains HTTP controllers/request-response mapping and gRPC service adapters/interceptors. It translates protocol messages into application inputs and application outputs into protocol responses.
5. REST and gRPC adapters call the same Application handlers. Business rules are never duplicated in controllers or generated gRPC service classes.
6. Each bounded context owns its service-specific `.proto` files and generated code. Only genuinely cross-cutting contracts may be shared.
7. The CoreBundle contains technical cross-cutting capabilities only. It must not become a shared business-domain package.

## Target package layout

```text
packages/core-bundle/src/
├── Domain/                 # intentionally no shared business model
├── Application/
│   ├── Contract/
│   ├── Messaging/
│   └── Serialization/
├── Infrastructure/
│   ├── Grpc/
│   ├── Http/
│   ├── Logging/
│   ├── Messenger/
│   ├── OpenTelemetry/
│   └── Serialization/
└── Transport/
    ├── Grpc/
    │   ├── Interceptor/
    │   ├── Mapper/
    │   └── Service/
    └── Http/
        ├── Controller/
        ├── Mapper/
        ├── Request/
        └── Response/
```

The Domain directory is intentionally empty until a truly shared domain concept exists. Correlation IDs, serialization and transport metadata are technical concerns, not business entities.

## HTTP and gRPC runtime

RoadRunner is the selected process runtime for services that expose both protocols. It can host the HTTP worker and gRPC server in one service process. The current PHP built-in server command is a development shortcut and is not the target runtime.

Migration must be performed service by service and preserve the existing public HTTP routes. Each service must provide:
- a RoadRunner HTTP worker that boots its Symfony Kernel and converts PSR-7 requests/responses at the outermost adapter boundary;
- a gRPC worker that registers generated service adapters;
- a service-owned `.proto` contract and generated PHP message/service classes;
- bounded worker lifecycle, graceful shutdown, health checks and explicit listen addresses;
- integration tests that exercise HTTP and gRPC against the same application handler.

Do not make Domain or Application depend on RoadRunner's worker API. RoadRunner is an Infrastructure/Transport concern.

## Serialization

Application code depends on `SerializerInterface`. Infrastructure supplies JSON and Protobuf implementations. JSON is suitable for REST and message envelopes; Protobuf is used at gRPC boundaries. Generated Protobuf classes must be mapped to application DTOs. Domain entities are never serialized directly.

Protobuf decoding requires the expected generated message class; the wire payload does not select arbitrary PHP classes.

## Correlation and tracing

Correlation ID, W3C trace ID, tenant ID and authenticated principal are distinct values. Correlation metadata may be propagated through HTTP and Messenger, but must not be used for authorization or tenant isolation. Long-running workers must restore the previous context in a `finally` block after each message/request.

## Rollout plan

1. Introduce architecture ports, JSON/Protobuf adapters and safe context scoping.
2. Add RoadRunner HTTP worker and gRPC server bootstrap with a minimal service-owned proto contract.
3. Migrate one service and run HTTP/gRPC integration tests before migrating the second.
4. Add standardized error mapping, metadata propagation, deadlines, cancellation and observability.
5. Only then update deployment defaults and mark the bundle stable.

## Consequences

- REST and gRPC share use cases without sharing transport models.
- Service contracts remain independently versionable.
- The bundle stays framework-aware only in its outer adapters.
- A runtime migration is explicit and test-gated; adding Composer packages alone is not considered gRPC support.
