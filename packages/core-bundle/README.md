# AutoFlow CoreBundle v2

Shared **technical** foundation for AutoFlow Symfony microservices. The bundle standardizes cross-cutting mechanics without creating a shared business domain.

## Architecture

The package follows Clean Architecture dependency direction:

- **Domain:** no shared business model is imposed by this package.
- **Application:** defines transport-neutral ports such as `SerializerInterface`, `ClockInterface`, `EventBusInterface`, `IdempotencyStoreInterface` and `TransactionManagerInterface`.
- **Infrastructure:** implements those ports with JSON, Protobuf, Symfony Messenger, HTTP and RoadRunner.
- **Transport:** maps HTTP and gRPC protocol concepts without putting protocol types in Application or Domain.

See [ADR-014](../../docs/adr/ADR-014-core-bundle-v2-clean-architecture.md), [ADR-015](../../docs/adr/ADR-015-road-runner-runtime.md) and [ADR-016](../../docs/adr/ADR-016-core-bundle-cross-cutting-ports.md).

## Capabilities

| Capability | Responsibility |
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

## RoadRunner HTTP worker

A service entry point can delegate HTTP handling to the shared worker:

```php
use App\Kernel;
use AutoFlow\CoreBundle\Infrastructure\RoadRunner\SymfonyHttpWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel(
    $_SERVER['APP_ENV'] ?? 'prod',
    (bool) ($_SERVER['APP_DEBUG'] ?? false),
);

(new SymfonyHttpWorker())->run($kernel);
```

The worker converts PSR-7 to HttpFoundation only at the transport boundary. The Symfony kernel, controllers and application handlers remain ordinary Symfony code. It calls `Kernel::terminate()` after successful handling and returns a generic Problem Details response for unexpected failures.

## RoadRunner gRPC worker

Each service owns its `.proto` files and generated classes. Implement the generated interface in a transport adapter and delegate to the same Application handler used by REST.

```php
use AutoFlow\CoreBundle\Infrastructure\RoadRunner\GrpcServerRunner;

(new GrpcServerRunner())->run([
    GeneratedPricingServiceInterface::class => $pricingGrpcAdapter,
]);
```

The gRPC adapter maps Protobuf request messages to application commands and maps application results to Protobuf response messages. Never pass generated messages into Domain methods. Keep protocol errors safe and map them to native gRPC status codes.

Cross-service gRPC metadata allow-listing, deadlines, cancellation and HTTP-to-gRPC status mapping are not yet shared CoreBundle APIs. Keep those policies in the service-owned gRPC adapter until a tested common contract is introduced. Never forward authorization metadata implicitly.

A typical RoadRunner configuration has independent HTTP and gRPC listeners and worker pools:

```yaml
version: "3"

server:
  command: "php bin/http-worker.php"

http:
  address: "0.0.0.0:8080"

grpc:
  listen: "tcp://0.0.0.0:9001"
  proto: ["proto/service.proto"]
  pool:
    command: "php bin/grpc-worker.php"
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


## JWT authentication boundary

When enabled by service configuration, the bundle protects configured HTTP path prefixes. It retrieves the issuer's JWKS, accepts RS256 signing keys only, validates the token signature and checks issuer, audience, subject and expiration. JWKS keys are cached in-process for a configurable short TTL. The subscriber attaches an immutable `AuthenticatedPrincipal` to the request under `AuthenticatedPrincipal::REQUEST_ATTRIBUTE`; it contains the verified subject, optional tenant ID and scopes.

The bundle does not issue tokens, store passwords, own user or tenant records, decide business permissions, or authorize resource ownership. Use an external OpenID Connect provider (Keycloak in local AutoFlow development) to issue tokens. Each service must keep its own expected audience and enforce its own authorization policies. A missing/invalid token returns 401; unavailable JWKS returns 503. Health and development documentation paths are excluded explicitly.

Offer uses a dedicated OAuth2 Client Credentials identity to call Pricing. It requests a Pricing-audience token and caches it until shortly before expiry; it does not forward an incoming user's bearer token. Production deployments must inject unique secrets through a secret manager and use TLS.

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


## Readiness checks

Implement `HealthCheckInterface` in a service-specific Infrastructure class. The bundle autoconfigures the tag. Readiness returns HTTP 503 if no checks are registered or any check fails. Public output includes only check names and status; internal exception messages and check details are not exposed.

## What stays outside this bundle

- Vehicle, Offer, Pricing, Customer, Contract, Order and Payment models/value objects.
- Domain events and business policies owned by each bounded context.
- Doctrine entities, repositories, migrations and database schemas.
- Transactional Outbox persistence and relay workers.
- Broker topology, routing keys, retry policies and dead-letter queues.
- Temporal workflows and SDK lifecycle.
- Identity lifecycle, password storage, token issuance and refresh-token handling.
- Business authorization policies, tenant membership, tenant isolation and business-specific rate limits.
- Service-owned gRPC contracts, generated business classes and client implementations.

Temporal remains appropriate for durable, long-running workflows. Messenger remains appropriate for independent asynchronous jobs and event delivery.
