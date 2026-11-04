# AutoFlow CoreBundle v2

Shared **technical** foundation for AutoFlow Symfony microservices. The bundle standardizes cross-cutting mechanics without creating a shared business domain.

## Architecture

The package follows Clean Architecture dependency direction:

- **Domain:** no shared business model is imposed by this package.
- **Application:** defines transport-neutral ports such as `SerializerInterface`.
- **Infrastructure:** implements those ports with JSON, Protobuf, Symfony HTTP and RoadRunner.
- **Transport:** maps HTTP and gRPC protocol concepts without putting protocol types in Application or Domain.

See [ADR-014](../../docs/adr/ADR-014-core-bundle-v2-clean-architecture.md) and [ADR-015](../../docs/adr/ADR-015-road-runner-runtime.md).

## Capabilities

| Capability | Responsibility |
| --- | --- |
| Correlation context | Request/message correlation with scoped restoration |
| HTTP propagation | Adds the current correlation ID to outgoing Symfony HttpClient requests |
| HTTP worker | Converts RoadRunner PSR-7 requests to Symfony HttpFoundation and back |
| gRPC server runner | Registers service-owned generated gRPC adapters in RoadRunner |
| Serialization port | Application-facing `SerializerInterface` |
| JSON serialization | Safe JSON encode/decode with explicit exceptions |
| Protobuf serialization | Generated Protobuf message encode/decode |
| Protocol error mapping | Maps HTTP status and gRPC status values |
| Health endpoints | Liveness and readiness; readiness fails closed without registered checks |
| Messenger propagation | Correlation ID stamp and middleware with `finally` restoration |
| Integration event envelope | Versioned event metadata, correlation and causation IDs |
| Problem Details | RFC 9457-style response factory |

## Runtime dependencies

The bundle requires:
- `roadrunner/http` and `nyholm/psr7` for the RoadRunner HTTP worker;
- `roadrunner/grpc` for the gRPC server runner;
- `symfony/psr-http-message-bridge` for PSR-7 / HttpFoundation conversion.

Each service must include its own RoadRunner binary and configuration. The package does not silently replace a service's HTTP runtime. Runtime migration is complete only after that service has a registered gRPC adapter and HTTP/gRPC integration tests.

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

Correlation IDs are distinct from W3C trace IDs, tenant IDs and authenticated principals. The context supports scoped execution and restores the previous value in a `finally` block. HTTP context is also cleared at Symfony's `kernel.finish_request` event so a long-running RoadRunner worker cannot leak one request's ID into the next.

Messenger middleware adds an ID when a message is dispatched without an HTTP request and restores the previous worker context after handling.

## Readiness checks

Implement `HealthCheckInterface` in a service-specific Infrastructure class. The bundle autoconfigures the tag. Readiness returns HTTP 503 if no checks are registered or any check fails. Public output includes only check names and status; internal exception messages and check details are not exposed.

## Existing integration-event contract

The `IntegrationEvent` envelope carries event ID, name, aggregate ID, occurrence time, schema version, payload, correlation ID and causation ID. Payloads must contain stable primitives/arrays, not Doctrine entities, Symfony requests, framework objects or domain classes from another bounded context.

An event envelope is not an Outbox. Reliable publication still requires a transactional Outbox and idempotent consumers.

## What stays outside this bundle

- Vehicle, Offer, Pricing, Customer, Contract, Order and Payment models/value objects.
- Domain events and business policies owned by each bounded context.
- Doctrine entities, repositories, migrations and database schemas.
- Transactional Outbox persistence and relay workers.
- Broker topology, routing keys, retry policies and dead-letter queues.
- Temporal workflows and SDK lifecycle.
- Authentication, authorization, tenant isolation and business-specific rate limits.

Temporal remains appropriate for durable, long-running workflows. Messenger remains appropriate for independent asynchronous jobs and event delivery.
