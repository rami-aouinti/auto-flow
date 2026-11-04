# 09 — Code walkthrough: Offer and Pricing

This chapter follows the actual two Symfony applications in the repository. It deliberately describes the current technical demonstration, not a complete production domain model.

## 1. The two applications are separate projects

Relevant paths:

- `apps/offer-service/composer.json`
- `apps/offer-service/config/services.yaml`
- `apps/offer-service/src/Controller/OfferController.php`
- `apps/pricing-service/composer.json`
- `apps/pricing-service/config/services.yaml`
- `apps/pricing-service/src/Controller/PricingController.php`
- `docker/php/service.Dockerfile`
- `compose.yaml`

Each service has its own Composer manifest and Symfony Kernel. The shared Dockerfile is a build template; its `SERVICE_PATH` argument selects which application is copied into an image. Sharing a Dockerfile does not mean sharing a PHP runtime process or a business model.

## 2. Dependency injection in Offer

The current `apps/offer-service/config/services.yaml` defines:

~~~yaml
parameters:
    app.pricing_service_url: '%env(resolve:PRICING_SERVICE_URL)%'

services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\\:
        resource: '../src/'
        exclude:
            - '../src/Kernel.php'

    App\\Controller\\OfferController:
        arguments:
            $pricingServiceUrl: '%app.pricing_service_url%'
~~~

The URL is configuration, not a hard-coded PHP dependency. In Compose, the value is `http://pricing-service:8080`.

Why this matters:
- The same application image can be configured differently per environment.
- The controller receives a dependency rather than looking up container services itself.
- The service can be tested with a controlled HTTP client and URL.
- Docker DNS keeps the internal address independent from the developer's host port.

## 3. Offer controller — current behaviour

The controller injects Symfony's `HttpClientInterface` and a configured Pricing URL. The route is `GET /api/offers/{vehicleId}`.

Simplified excerpt matching the current implementation:

~~~php
$pricingResponse = $this->httpClient->request(
    'GET',
    sprintf(
        '%s/api/pricing/%s',
        rtrim($this->pricingServiceUrl, '/'),
        rawurlencode($vehicleId),
    ),
);

$pricing = $pricingResponse->toArray();

return new JsonResponse([
    'offerId' => sprintf('demo-%s', $vehicleId),
    'vehicleId' => $vehicleId,
    'status' => 'calculated',
    'pricing' => $pricing,
]);
~~~

The controller currently performs three responsibilities:
1. Receives the route parameter.
2. Calls the Pricing API.
3. Composes a JSON response.

That is acceptable for a deliberately small proof of connectivity. It is not the final design for a growing Offer bounded context. A more mature implementation would move orchestration into an application use case and keep the controller as a transport adapter.

## 4. Pricing controller — current and new behaviour

The original route is GET /api/pricing/{vehicleId}. It remains temporarily available for the Offer tracing demonstration and returns a fixed 329.00 EUR response labelled legacy-demo-only. It is not the calculation API and must not be used for new integrations.

The new route is POST /api/pricing/quotes. It accepts explicit inputs: vehicle ID, vehicle price, down payment, residual value, currency, duration and annual interest rate in basis points.

The request is translated into an application command. The handler creates Money and LeaseTerms value objects, then delegates the calculation to the domain LeasePaymentCalculator. The controller returns monetary values as decimal strings, together with the calculation version.

The new implementation has real domain validation and a deterministic annuity calculation, but it is still an illustrative fixed-rate model. Pricing policy persistence, taxes, fees, mileage, customer-specific rules, quote expiry and regulated financial validation are not implemented.

## 5. Why HTTP is used here

Offer cannot compose its current response until Pricing returns a result. Synchronous HTTP therefore fits this narrow demonstration.

The consequence is coupling in time: if Pricing is unavailable or slow, Offer cannot complete the request. A production implementation should set a short connection/request timeout, map downstream failures to stable API errors, define a latency budget, and decide whether any retry is safe.

A retry of a GET request is generally easier to reason about than retrying a command that creates an order or charges a payment. Even GET retries should be bounded and should not amplify an outage.

## 6. Distributed tracing in the controllers

Both controllers create a manual OpenTelemetry span:
- Offer: `offer.compose`
- Pricing: `pricing.calculate`

They attach `vehicle.id`, activate the span scope, record an exception and mark the span as an error if an exception escapes, then detach the scope and end the span in a `finally` block.

The OpenTelemetry Symfony auto-instrumentation and HTTP client instrumentation propagate W3C trace context. The downstream Pricing span can therefore appear as part of the same trace.

Avoid adding personal data, access tokens or full payloads as span attributes. A vehicle identifier is useful for this local demo; production attribute policies should consider cardinality and privacy.

## 7. What is intentionally missing?

The current controllers do not yet demonstrate:
- A Domain layer with aggregates and value objects.
- An Application use case / command handler.
- A Pricing repository or persistence model.
- Business validation beyond route-level parameter handling.
- Authentication and fine-grained authorization.
- A versioned OpenAPI contract.
- Explicit timeout and downstream error mapping policy.
- Resilience controls such as circuit breaker or bulkhead.
- Application metrics.
- An Order lifecycle, RabbitMQ consumer or Temporal workflow.

This is not a defect in the tracing proof of concept. It is a boundary between the current milestone and the next engineering milestone.

## 8. Recommended evolution — without overengineering

A sensible next iteration for Offer could be:

~~~text
Presentation/
  Http/
    OfferController.php
    OfferResponse.php
Application/
  Query/
    ComposeOffer/
      ComposeOfferQuery.php
      ComposeOfferHandler.php
      OfferPricingPort.php
Domain/
  Model/
    Offer.php
  ValueObject/
    VehicleId.php
Infrastructure/
  Pricing/
    HttpPricingAdapter.php
~~~

The Application layer owns the port because the use case defines what it needs. Infrastructure implements that port using Symfony HttpClient. The controller translates HTTP input/output and does not know how Pricing is called.

Only introduce the full structure when the use case and domain rules justify it. A folder tree without meaningful domain behaviour is architecture theatre.

## 9. Example application port and adapter — proposed, not yet committed

~~~php
<?php

declare(strict_types=1);

namespace App\\Application\\Query\\ComposeOffer;

interface OfferPricingPort
{
    /** @return array{vehicleId: string, monthlyAmount: float, currency: string, durationMonths: int} */
    public function calculate(string $vehicleId): array;
}
~~~

~~~php
<?php

declare(strict_types=1);

namespace App\\Infrastructure\\Pricing;

use App\\Application\\Query\\ComposeOffer\\OfferPricingPort;
use Symfony\\Contracts\\HttpClient\\HttpClientInterface;

final readonly class HttpPricingAdapter implements OfferPricingPort
{
    public function __construct(
        private HttpClientInterface $client,
        private string $pricingServiceUrl,
    ) {
    }

    public function calculate(string $vehicleId): array
    {
        return $this->client->request(
            'GET',
            sprintf('%s/api/pricing/%s', rtrim($this->pricingServiceUrl, '/'), rawurlencode($vehicleId)),
        )->toArray();
    }
}
~~~

This is an illustrative direction, not a drop-in patch. In production, prefer a typed PricingQuote DTO and explicit exceptions over a loosely shaped array. The adapter should enforce timeouts and translate transport errors into application-level failures.

## 10. How to explain this design in an interview

> We started with two independent Symfony applications to make the service boundary concrete. The Offer service does not import Pricing classes; it communicates through an HTTP contract. That gives us independent Composer dependencies and deployment boundaries. For the current proof of concept, the controller composes the response directly. As the business rules grow, I would move the orchestration into an application use case, define a port for pricing, and implement it through an HTTP adapter. The important point is to introduce architectural separation where it protects real business rules, not to create layers just for the sake of folders.