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
| Correlation context | Request/message correlation with scoped restoration |
| HTTP propagation | Adds the current correlation ID to outgoing Symfony HttpClient requests |
| HTTP worker | Converts RoadRunner PSR-7 requests to Symfony HttpFoundation and back |
| gRPC server runner | Registers service-owned generated gRPC adapters in RoadRunner |
| Serialization port | Application-facing `SerializerInterface` |
| JSON / Protobuf | Safe JSON encode/decode and generated Protobuf message serialization |
| Health endpoints | Liveness and readiness; readiness fails closed without registered checks |
| Messenger propagation | Correlation ID stamp and middleware with scoped context restoration (must be enabled per bus) |
| Event bus | Messenger adapter for publishing the shared integration-event envelope |
| Clock | Injectable system clock for deterministic application tests |
| Idempotency port | Atomic claim/replay contract; storage implementation remains service-owned |
| Transaction port | Application transaction boundary without Doctrine coupling |
| Problem Details | RFC 9457-style response factory |
| JWT verification | RS256 signature validation against cached JWKS, issuer/audience/expiry checks and normalized request principal |

## Application ports

Use the interfaces from Application; do not inject Symfony, Doctrine or RoadRunner into Domain/Application handlers.

```php
use AutoFlow\CoreBundle\Application\Clock\ClockInterface;
use AutoFlow\CoreBundle\Application\Messaging\EventBusInterface;

final readonly class RegisterOfferHandler
{
    public function __construct(
        private ClockInterface $clock,
        private EventBusInterface $events,
    ) {
    }

    public function handle(): void
    {
        $now = $this->clock->now();
        // Build an application-owned event using $now, then publish it.
    }
}
```

The bundle aliases `ClockInterface` to `SystemClock` and `EventBusInterface` to the Messenger adapter. Replace these aliases in a service when a different implementation is required. `IdempotencyStoreInterface` and `TransactionManagerInterface` deliberately have no default persistence implementation.

**Important:** `EventBusInterface::publish()` dispatches a message; it is not a Transactional Outbox. If an operation must atomically save business state and an event, the owning service must implement its Outbox and relay. Likewise, an idempotency adapter must make `claim()` atomic across concurrent processes.

## Runtime dependencies

RoadRunner and Protobuf are optional adapters. The base bundle does not require their runtime libraries. Add only the packages needed by a service:

```bash
composer require roadrunner/http nyholm/psr7 symfony/psr-http-message-bridge
composer require roadrunner/grpc # only for the gRPC server runner
composer require google/protobuf # only for Protobuf messages
```

Their classes are excluded from automatic service discovery so a service that does not install these packages can still compile its Symfony container. Instantiate/register the relevant adapter explicitly in the service that uses it. Each service must include its own RoadRunner binary and configuration. Runtime migration is complete only after that service has a registered gRPC adapter and HTTP/gRPC integration tests.

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

The gRPC adapter maps Protobuf request messages to application commands and maps application results to Protobuf response messages. Never pass generated messages into Domain methods. Keep protocol errors safe and map them to native gRPC status codes.

Cross-service gRPC metadata allow-listing, deadlines, cancellation and HTTP-to-gRPC status mapping are not yet shared CoreBundle APIs. Keep those policies in the service-owned gRPC adapter until a tested common contract is introduced. Never forward authorization metadata implicitly.

The bundle uses the configuration alias `core`:

```yaml
core:
    correlation_id: true
    correlation_header: X-Correlation-ID
```

The shared `PlatformHealthService/Ping` contract is a transport smoke check only. Business RPCs, their `.proto` files and generated adapters remain service-owned and must delegate to that service's Application handlers. Do not expose internal gRPC listeners publicly without an explicit network/security requirement.

## HTTP Problem Details

Use `ProblemDetailsFactory` for a safe, standardized client error response:

```php
return $problemDetailsFactory->create(
    422,
    'Unprocessable Content',
    'The requested vehicle configuration is invalid.',
    'https://api.example.com/problems/invalid-vehicle-configuration',
    '/api/v1/quotes',
    ['errorCode' => 'INVALID_CONFIGURATION'],
);
```

The factory rejects non-error HTTP statuses, empty titles and extension members that overwrite standard Problem Details fields. Never expose stack traces, SQL errors, credentials or internal exception messages in public details.

## Serialization

Application code depends on `SerializerInterface`. Infrastructure supplies:
- `JsonSerializer` for REST payloads and JSON event envelopes;
- `ProtobufSerializer` for generated Protobuf messages at gRPC boundaries (register it explicitly when google/protobuf is installed).

`JsonSerializer::decode()` returns native PHP values. It rejects a non-null target class instead of silently ignoring it; typed DTO hydration belongs to an explicit mapper or a dedicated serializer adapter. Never deserialize an arbitrary class name from an untrusted payload. Generated Protobuf classes must be mapped to application DTOs; Domain entities are never serialized directly.


The default EventBus adapter dispatches through Symfony Messenger. It is not a Transactional Outbox. If business state and an event must be committed atomically, the owning service must implement its own Outbox table and relay. Idempotency persistence, transaction management and broker retry/dead-letter policies also belong to the owning service/platform adapter.

## Serialization

`SerializerInterface::decode()` returns native values for JSON. `JsonSerializer` deliberately rejects a non-null target class instead of silently ignoring it. Use an explicit DTO mapper or a dedicated Symfony Serializer adapter for typed hydration.

`ProtobufSerializer` requires the expected generated message class when decoding. It is excluded from automatic service discovery because google/protobuf is optional; register it explicitly in the service that installs google/protobuf. Never select a PHP class from an untrusted payload. Map generated Protobuf messages to application DTOs at the gRPC transport boundary.

## Messenger correlation propagation

The package provides `CorrelationIdStamp` and `CorrelationIdMiddleware`. Enable the middleware on each relevant bus while retaining Symfony's default middleware:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - id: AutoFlow\\CoreBundle\\Infrastructure\\Messenger\\CorrelationIdMiddleware
```

The middleware attaches a stamp when absent, reuses an incoming stamp and restores the previous context after handling. Configure it on every bus that needs propagation.

## Correlation and worker safety

Correlation IDs are distinct from W3C trace IDs, tenant IDs and authenticated principals. The HTTP subscriber stores and restores the previous context at Symfony's `kernel.finish_request` event so a long-running worker cannot leak one request's ID into the next request.


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
