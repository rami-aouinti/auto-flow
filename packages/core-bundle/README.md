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
| gRPC metadata | Validates and allow-lists operational metadata; rejects implicit credential forwarding |
| gRPC deadline | Transport-neutral positive RPC timeout value |
| Serialization port | Application-facing `SerializerInterface` |
| JSON / Protobuf | Safe JSON encode/decode and generated Protobuf message serialization |
| Protocol error mapping | Maps HTTP status and gRPC status values |
| Health endpoints | Liveness and readiness; readiness fails closed without registered checks |
| Messenger propagation | Correlation ID stamp and middleware with `finally` restoration |
| Event bus | Messenger adapter for publishing the shared integration-event envelope |
| Clock | Injectable system clock for deterministic application tests |
| Idempotency port | Atomic claim/replay contract; storage implementation remains service-owned |
| Transaction port | Application transaction boundary without Doctrine coupling |
| HTTP response factory | Small JSON success envelope helper |
| Problem Details | RFC 9457-style response factory |

## Application ports

Use the interfaces from Application; do not inject Symfony, Doctrine or RoadRunner into Domain/Application handlers.

```php
use AutoFlow\\CoreBundle\\Application\\Clock\\ClockInterface;
use AutoFlow\\CoreBundle\\Application\\Messaging\\EventBusInterface;

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

The bundle requires:
- `roadrunner/http` and `nyholm/psr7` for the RoadRunner HTTP worker;
- `roadrunner/grpc` for the gRPC server runner;
- `symfony/psr-http-message-bridge` for PSR-7 / HttpFoundation conversion.

Each service must include its own RoadRunner binary and configuration. The package does not silently replace a service's HTTP runtime. Runtime migration is complete only after that service has a registered gRPC adapter and HTTP/gRPC integration tests.

## RoadRunner HTTP worker

A service entry point can delegate HTTP handling to the shared worker:

```php
use App\\Kernel;
use AutoFlow\\CoreBundle\\Infrastructure\\RoadRunner\\SymfonyHttpWorker;

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
use AutoFlow\\CoreBundle\\Infrastructure\\RoadRunner\\GrpcServerRunner;

(new GrpcServerRunner())->run([
    GeneratedPricingServiceInterface::class => $pricingGrpcAdapter,
]);
```

The gRPC adapter maps Protobuf request messages to application commands and maps application results to Protobuf response messages. Never pass generated messages into Domain methods. Keep protocol errors safe and map them to native gRPC status codes.

`GrpcMetadata::allowListed()` is provided for explicit propagation of operational metadata such as correlation IDs. Do not forward authorization metadata by default. `GrpcDeadline` validates a positive finite timeout duration; the service-specific client adapter must translate it to the selected gRPC client's deadline API.

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

## Serialization

Application code depends on `SerializerInterface`. Infrastructure supplies:
- `JsonSerializer` for REST payloads and JSON event envelopes;
- `ProtobufSerializer` for generated Protobuf messages at gRPC boundaries.

For Protobuf decoding, pass the expected generated message class as the second argument:

```php
$payload = $serializer->encode($requestMessage);
$request = $serializer->decode($payload, CalculateQuoteRequest::class);
```

Never deserialize an arbitrary class name supplied by an untrusted payload. Generated Protobuf classes must be mapped to application DTOs. Domain entities are never serialized directly.

## Correlation and worker safety

Correlation IDs are distinct from W3C trace IDs, tenant IDs and authenticated principals. The context supports scoped execution and restores the previous value in a `finally` block. HTTP context is also cleared at Symfony's `kernel.finish_request` event so a long-running RoadRunner worker cannot leak one request's ID into the next request.

Messenger middleware adds an ID when a message is dispatched without an HTTP request and restores the previous worker context after handling.

## Readiness checks

Implement `HealthCheckInterface` in a service-specific Infrastructure class. The bundle autoconfigures the tag. Readiness returns HTTP 503 if no checks are registered or any check fails. Public output includes only check names and status; internal exception messages and check details are not exposed.

## What stays outside this bundle

- Vehicle, Offer, Pricing, Customer, Contract, Order and Payment models/value objects.
- Domain events and business policies owned by each bounded context.
- Doctrine entities, repositories, migrations and database schemas.
- Transactional Outbox persistence and relay workers.
- Broker topology, routing keys, retry policies and dead-letter queues.
- Temporal workflows and SDK lifecycle.
- Authentication, authorization, tenant isolation and business-specific rate limits.
- Service-owned gRPC contracts, generated business classes and client implementations.

Temporal remains appropriate for durable, long-running workflows. Messenger remains appropriate for independent asynchronous jobs and event delivery.
