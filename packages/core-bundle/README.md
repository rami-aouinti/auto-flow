# AutoFlow CoreBundle v2

Shared **technical** foundation for AutoFlow Symfony microservices. The bundle standardizes cross-cutting mechanics without creating a shared business domain.

## Architecture

- **Domain:** no shared business model is defined here.
- **Application:** transport-neutral ports such as **SerializerInterface**, **ClockInterface** and **EventBusInterface**.
- **Infrastructure:** implementations using JSON, Symfony Messenger, HTTP and optional RoadRunner/Protobuf adapters.
- **Transport:** HTTP and gRPC protocol adapters. Generated Protobuf messages stay outside Domain and Application.

See [ADR-014](../../docs/adr/ADR-014-core-bundle-v2-clean-architecture.md).

## Implemented source capabilities

| Capability | Responsibility |
| --- | --- |
| Correlation context | Scoped request/message correlation with restoration |
| HTTP propagation | Adds the current correlation ID to outgoing Symfony HttpClient requests |
| Messenger propagation | Correlation stamp and middleware; enable it explicitly on each bus |
| Event bus | Messenger adapter for the shared integration-event envelope |
| Clock | Injectable system clock |
| Serialization | Application SerializerInterface, JSON adapter and optional Protobuf adapter |
| RoadRunner HTTP | Converts PSR-7 requests/responses to/from Symfony HttpFoundation |
| RoadRunner gRPC | Registers service-owned gRPC adapters |
| gRPC helpers | Text metadata validation/allow-listing, deadline value object and HTTP/gRPC status mapping |
| Health | Liveness and readiness endpoints; readiness fails closed without registered checks |
| JWT | RS256 verification against cached JWKS, issuer/audience/expiry validation and request principal |
| Problem Details | RFC 9457-style JSON response factory |
| Idempotency / transaction | Application ports only; persistence implementations remain service-owned |

This table describes source capabilities, not proof that every service has enabled or deployed them. Verify service configuration and integration tests before relying on a capability in production.

## Application ports

Use the interfaces from Application; do not inject Symfony, Doctrine or RoadRunner into Domain/Application handlers.

~~~php
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
~~~

The bundle aliases ClockInterface to SystemClock and EventBusInterface to the Messenger adapter. Replace these aliases in a service when a different implementation is required. IdempotencyStoreInterface and TransactionManagerInterface deliberately have no default persistence implementation.

**Important:** EventBusInterface::publish() dispatches a message; it is not a Transactional Outbox. If business state and an event must be committed atomically, the owning service must implement its Outbox and relay. An idempotency adapter must make claim() atomic across concurrent processes.

## Optional runtime adapters

The base bundle does not require RoadRunner or Protobuf runtime libraries. Install only the dependencies used by each service.

For the RoadRunner HTTP worker:

~~~bash
composer require roadrunner/http nyholm/psr7 symfony/psr-http-message-bridge
~~~

For the RoadRunner gRPC server and generated Protobuf messages:

~~~bash
composer require roadrunner/grpc google/protobuf
~~~

Each service must also include its own RoadRunner binary and configuration. Optional adapter classes are excluded from CoreBundle automatic service discovery; instantiate/register the relevant adapter explicitly in the service that uses it. The HTTP worker and gRPC runner are not considered production-ready in a service until its container boots and its HTTP/gRPC integration tests pass.

## Symfony registration

Register AutoFlow\CoreBundle\CoreBundle::class in each consuming application's config/bundles.php.

Import the health routes in that application's config/routes.yaml:

~~~yaml
autoflow_core:
    resource: '@CoreBundle/Resources/config/routes.yaml'
~~~

This import is required; defining routes inside a bundle does not automatically register them.

The bundle uses the configuration alias core:

~~~yaml
core:
    correlation_id: true
    correlation_header: X-Correlation-ID
~~~

## JWT scopes and tenant context

JWT authentication validates the RS256 signature against the Keycloak JWKS, issuer, audience and expiry before placing an AuthenticatedPrincipal on the current request. The principal contains the verified subject, optional `tenant_id` claim and OAuth `scope` values.

Protect controller actions declaratively:

~~~php
use AutoFlow\\CoreBundle\\Security\\RequiresScope;

#[RequiresScope('pricing:quote')]
#[Route('/api/pricing/quotes', methods: ['POST'])]
public function calculate(...): JsonResponse
{
    // ...
}
~~~

The ScopeAuthorizationSubscriber returns HTTP 403 when the authenticated principal is missing or does not contain the required scope. Keycloak must issue the relevant scopes (for example `offers:read` and `pricing:quote`) to the corresponding clients/users.

TenantContext reads the tenant only from the verified request principal; it never trusts a caller-supplied tenant header. Use `getTenantId()` for an optional tenant and `requireTenantId()` for operations that must be tenant-scoped. The latter throws MissingTenantContextException if there is no verified tenant.

Tenant enforcement can be enabled for selected route prefixes:

~~~yaml
core:
    authentication:
        tenant_required_path_prefixes:
            - '/api/tenant/'
~~~

The default list is empty to avoid breaking existing non-tenant endpoints. Add prefixes only after the identity provider issues tenant claims for those callers and tenant context is propagated securely across service boundaries. A service-to-service client-credentials token without a tenant claim must not be used for a tenant-required operation.

The bundle supplies the cross-cutting tenant context and scope gate, not tenant membership or resource-level business policy. Each service must still verify that the principal is entitled to access the specific tenant-owned resource.

## Messenger correlation

Enable the middleware on each relevant bus while retaining Symfony's default middleware:

~~~yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - id: AutoFlow\CoreBundle\Infrastructure\Messenger\CorrelationIdMiddleware
~~~

The middleware attaches a stamp when absent, reuses an incoming stamp and restores the previous context after handling. Configure it on every bus that needs propagation.

The default EventBus adapter dispatches through Symfony Messenger. It is not a Transactional Outbox. Broker topology, retry/dead-letter policy, business idempotency storage and transactional persistence belong to the owning service/platform adapter.

## HTTP Problem Details

Use ProblemDetailsFactory for standardized client errors:

~~~php
return $problemDetailsFactory->create(
    422,
    'Unprocessable Content',
    'The requested vehicle configuration is invalid.',
    'https://api.example.com/problems/invalid-vehicle-configuration',
    '/api/v1/quotes',
    ['errorCode' => 'INVALID_CONFIGURATION'],
);
~~~

The factory rejects non-error HTTP statuses, empty titles and extension members that overwrite standard Problem Details fields. Never expose stack traces, SQL errors, credentials or internal exception messages in public details.

## Serialization

Application code depends on SerializerInterface.

- JsonSerializer encodes/decodes JSON to native PHP values.
- ProtobufSerializer handles generated Protobuf messages at a gRPC boundary. It requires google/protobuf and must be registered explicitly.

JsonSerializer::decode() rejects a non-null target class instead of silently ignoring it. Typed DTO hydration belongs to an explicit mapper or a dedicated serializer adapter. Never deserialize an arbitrary class name from an untrusted payload. Map generated Protobuf messages to application DTOs; never serialize Domain entities directly.

## gRPC boundaries

The bundle provides helpers for textual metadata validation/allow-listing, a deadline value object and HTTP/gRPC status mapping. These helpers do not implement a gRPC client, automatic cross-service metadata propagation, transport cancellation or a service-specific authorization policy. Never forward authorization metadata implicitly.

The shared PlatformHealthService/Ping contract is a protocol smoke check only. Business RPCs, their .proto files and generated business adapters remain service-owned and must delegate to that service's Application handlers. Do not expose internal gRPC listeners publicly without an explicit network/security requirement.

## Correlation and worker safety

Correlation IDs are diagnostic metadata; they are distinct from W3C trace IDs, tenant IDs and authenticated principals. A correlation ID must never be used for authorization or tenant isolation.

The HTTP subscriber restores the previous context at Symfony's kernel.finish_request event. Messenger middleware restores it in a finally-safe scope. This is important for long-running workers so one request/message cannot leak its context into the next one.

## Dependency direction and service-owned responsibilities

Application and Domain must not depend on Symfony, Doctrine, RoadRunner, PSR-7 or generated Protobuf types. Infrastructure and Transport are the outer adapters. The CoreBundle must not contain vehicle, offer, pricing, customer, contract, order or payment rules.

The following remain outside this bundle:

- Bounded-context domain models, business events and use cases.
- Doctrine entities, repositories, migrations and database schemas.
- Transactional Outbox persistence and relay.
- Broker topology, retry/DLQ policy and business idempotency storage.
- Temporal workflows and SDK lifecycle.
- Identity lifecycle, password storage, token issuance and refresh-token handling.
- Business authorization policies, tenant membership, tenant isolation and business-specific rate limits.
- Service-owned gRPC contracts, generated business classes and client implementations.

Temporal is appropriate for durable long-running workflows. Messenger is appropriate for asynchronous messages and jobs; neither should be forced into the other role.
