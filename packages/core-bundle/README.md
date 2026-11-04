# AutoFlow CoreBundle v2

Shared **technical** foundation for AutoFlow Symfony microservices. The bundle standardizes cross-cutting mechanics without creating a shared business domain.

## Architecture

- **Domain:** this package deliberately defines no shared business model.
- **Application:** transport-neutral ports such as `SerializerInterface`, `ClockInterface` and `EventBusInterface`.
- **Infrastructure:** implementations using JSON, Protobuf, Symfony Messenger, HTTP and RoadRunner.
- **Transport:** HTTP and gRPC protocol adapters. Generated Protobuf classes stay outside Domain and Application.

See [ADR-014](../../docs/adr/ADR-014-core-bundle-v2-clean-architecture.md).

## Implemented capabilities (source-level)

| Capability | Current responsibility |
| --- | --- |
| Correlation context | Scoped value with previous-context restoration |
| HTTP correlation | Incoming header validation, response header and outgoing Symfony HttpClient propagation |
| Messenger | EventBus adapter plus correlation stamp/middleware; enable the middleware explicitly on each bus |
| Serialization | JSON native-value serializer and generated Protobuf message serializer |
| Health | Liveness/readiness controller and tagged health checks |
| RoadRunner HTTP | PSR-7 to HttpFoundation worker adapter |
| RoadRunner gRPC | Server runner that registers service-owned generated adapters |
| Clock | Injectable system clock |
| Problem Details | RFC 9457-style JSON response factory with reserved-member validation |

The table describes package source capabilities, not proof that each service has enabled or deployed them. Check service configuration and integration tests before relying on a capability in production.

## Dependency direction

Application and Domain must not depend on Symfony, Doctrine, RoadRunner, PSR-7 or generated Protobuf types. Infrastructure and Transport are the outer adapters. The CoreBundle must not contain vehicle, offer, pricing, customer, contract, order or payment rules.

## Registration

Register `AutoFlow\\CoreBundle\\CoreBundle::class` in each consuming Symfony application's `config/bundles.php`. Import the health routes explicitly in that application's `config/routes.yaml`:

```yaml
autoflow_core:
    resource: '@CoreBundle/Resources/config/routes.yaml'
```

This import is required; defining `Resources/config/routes.yaml` inside a bundle does not automatically register the routes.

RoadRunner and Protobuf are **optional adapters**. The base package does not require their runtime libraries. Add the suggested packages only in a service that actually enables those adapters:

```bash
composer require roadrunner/http nyholm/psr7 symfony/psr-http-message-bridge
# Add this only when using the RoadRunner gRPC runner:
composer require roadrunner/grpc
# Add this only when using Protobuf messages:
composer require google/protobuf
```

Their classes are excluded from automatic service discovery so a service that does not install these packages can still compile its Symfony container. Instantiate/register the relevant adapter explicitly in the service that uses it.

The bundle uses the configuration alias `core`:

```yaml
core:
    correlation_id: true
    correlation_header: X-Correlation-ID
```

Set `correlation_id: false` to disable the request subscriber and HTTP client decorator.

## Application ports

Application code should depend on ports, not concrete adapters:

```php
use AutoFlow\\CoreBundle\\Application\\Clock\\ClockInterface;
use AutoFlow\\CoreBundle\\Application\\Messaging\\EventBusInterface;

final readonly class CreateQuoteHandler
{
    public function __construct(
        private ClockInterface $clock,
        private EventBusInterface $events,
    ) {
    }
}
```

The default EventBus adapter dispatches through Symfony Messenger. It is not a Transactional Outbox. If business state and an event must be committed atomically, the owning service must implement its own Outbox table and relay. Idempotency persistence, transaction management and broker retry/dead-letter policies also belong to the owning service/platform adapter.

## Serialization

`SerializerInterface::decode()` returns native values for JSON. `JsonSerializer` deliberately rejects a non-null target class instead of silently ignoring it. Use an explicit DTO mapper or a dedicated Symfony Serializer adapter for typed hydration.

`ProtobufSerializer` requires the expected generated message class when decoding. It is excluded from automatic service discovery because google/protobuf is optional; register it explicitly in the service that installs google/protobuf. Never select a PHP class from an untrusted payload. Map generated Protobuf messages to application DTOs at the gRPC transport boundary.

## RoadRunner

`SymfonyHttpWorker` adapts PSR-7 requests/responses at the edge and calls the Symfony Kernel. `GrpcServerRunner` registers service-owned generated gRPC service adapters. Each service owns its `.proto` files, generated code, listener security, worker configuration and integration tests. The shared runner does not automatically migrate an application's web entry point or prove that its gRPC business API is ready.

Use independent HTTP and gRPC worker processes/listeners where operational isolation is required. Do not expose internal gRPC listeners publicly without an explicit security and networking design.

## Correlation and tracing

Correlation IDs, W3C trace IDs, tenant IDs and authenticated principals are different concepts. A correlation ID is diagnostic metadata and must never be used for authorization or tenant isolation. Long-running workers must restore request/message context in a `finally` block.

## What stays outside this bundle

- Bounded-context domain models, business events and use cases.
- Doctrine entities, repositories, migrations and database schemas.
- Transactional Outbox persistence and relay.
- Broker topology, retry/DLQ policy and business idempotency storage.
- Temporal workflows and SDK lifecycle.
- Identity lifecycle, password storage, token issuance and refresh-token handling.
- Business authorization policies, tenant membership, tenant isolation and business-specific rate limits.
- Service-owned gRPC contracts, generated business classes and client implementations.

Temporal is appropriate for durable long-running workflows. Messenger is appropriate for asynchronous messages and jobs; neither should be forced into the other role.
