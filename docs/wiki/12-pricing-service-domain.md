# 12 — Pricing Service: domain model and calculation

## 1. Scope of this implementation

The Pricing Service now has a first executable business capability: calculate an illustrative fixed-rate lease instalment from explicit inputs.

Implemented in this branch:
- A Money value object using integer minor units and an explicit currency.
- A LeaseTerms value object enforcing the input invariants.
- A domain LeasePaymentCalculator.
- An application command and handler.
- A POST /api/pricing/quotes HTTP endpoint.
- Unit tests for the calculation and important invalid inputs.
- The previous GET endpoint remains temporarily available for the Offer tracing demo and is explicitly marked as legacy demo data.

Not implemented yet:
- Pricing policy persistence or administrative configuration.
- Vehicle catalogue lookup or validation against a source of truth.
- Customer-specific or campaign-specific pricing.
- Taxes, fees, mileage, insurance, maintenance or partner commissions.
- Quote persistence, expiry, approval and audit history.
- Production financial/legal validation.

This is a first vertical slice, not a complete commercial leasing engine.

## 2. Request contract

POST /api/pricing/quotes

~~~json
{
  "vehicleId": "vehicle-001",
  "vehiclePrice": "30000.00",
  "downPayment": "3000.00",
  "residualValue": "9000.00",
  "currency": "EUR",
  "durationMonths": 36,
  "annualInterestRateBasisPoints": 500
}
~~~

Money values are decimal strings rather than JSON floating-point numbers. This avoids introducing binary floating-point rounding before the domain receives the amount.

The response uses decimal strings for money too:

~~~json
{
  "vehicleId": "vehicle-001",
  "monthlyAmount": "576.98",
  "totalInstallmentsAmount": "20771.28",
  "totalCostIncludingDownPayment": "23771.28",
  "residualValue": "9000.00",
  "currency": "EUR",
  "durationMonths": 36,
  "annualInterestRateBasisPoints": 500,
  "calculationVersion": "lease-annuity-v1"
}
~~~

The response is marked Cache-Control: no-store. Pricing results may depend on time-sensitive policy and should not accidentally be cached by an intermediary.

## 3. Domain invariants

LeaseTerms rejects:
- Different currencies between vehicle price, down payment and residual value.
- A down payment greater than the vehicle price.
- A residual value greater than the price remaining after down payment.
- A duration outside the current demonstration range of 12–72 months.
- An annual nominal rate outside 0–2,000 basis points.

These ranges are explicit demo policy assumptions, not market rules. A real business must own and approve them.

Money stores amounts as integer minor units. The API does not accept negative values or more than two decimal places. The current implementation assumes two minor-unit digits and therefore must be extended before supporting currencies with different exponents.

## 4. Calculation model

Let:
- P = vehicle price in minor units
- D = down payment in minor units
- R = residual value in minor units
- n = number of monthly instalments
- i = annual nominal interest rate as a decimal
- r = i / 12 = monthly rate

For a zero-interest contract:

~~~text
monthly payment = (P - D - R) / n
~~~

For a positive monthly rate:

~~~text
monthly payment =
  (P - D - R / (1 + r)^n)
  * r
  / (1 - (1 + r)^(-n))
~~~

The result is rounded once to the nearest minor unit using half-up rounding. The total instalments are calculated from the rounded monthly amount, so the displayed monthly amount multiplied by the number of instalments equals the displayed total instalments.

This is a simplified annuity model with a balloon/residual amount at the end. It is not a bank offer, APR disclosure or regulated financial quote. Real leasing calculations may use different day-count conventions, payment timing, fees, tax treatment, rate conventions, rounding and residual-value rules.

## 5. Why these layers exist

~~~text
Presentation
  Controller / HTTP
      |
      v
Application
  CalculateQuoteCommand
  CalculateQuoteHandler
      |
      v
Domain
  Money
  LeaseTerms
  LeasePaymentCalculator
  PricingQuote
~~~

- Presentation translates JSON and HTTP status codes. It does not calculate money.
- Application coordinates the use case and turns external input into domain values.
- Domain owns invariants and the calculation. It has no Symfony, HTTP, database or broker dependency.
- Infrastructure is intentionally small at this stage because the calculation is deterministic and has no persistence requirement yet.

The important dependency direction is inward. Symfony calls the application; the application calls the domain. The domain does not know that it is running inside a Symfony service.

## 6. Why no repository or PostgreSQL table yet?

A repository is justified when the use case needs to load or save a business object. The current calculation receives all required inputs and deterministically returns a result. Adding a repository now would create an abstraction with no real responsibility.

The next persistence increment should introduce a versioned PricingPolicy owned by Pricing, loaded through a repository. A later quote snapshot can preserve:
- The exact input values used.
- The policy/calculation version.
- The calculated amounts and currency.
- The creation time and validity deadline.
- The identity of the requesting business operation, without copying unnecessary customer data.

PostgreSQL should persist policy versions and quote snapshots when those lifecycle requirements are agreed. It should not be used merely because the Compose stack already contains PostgreSQL.

## 7. Compatibility endpoint

GET /api/pricing/{vehicleId} is retained only so the existing Offer → Pricing tracing demonstration continues to work. Its 329.00 EUR amount and response body remain unchanged for compatibility; the endpoint sends a deprecation header and remains static demonstration data.

New integrations must call POST /api/pricing/quotes and supply explicit lease terms. The Offer service has not yet been migrated to the new contract.

## 8. Next engineering increments

1. Add API-level tests for valid requests, malformed JSON and domain validation responses.
2. Define the business owner and lifecycle of pricing policies.
3. Persist immutable, versioned pricing policies in a Pricing-owned PostgreSQL schema.
4. Add quote snapshots and validity/expiry semantics.
5. Migrate Offer to a typed Pricing API contract and explicit input source.
6. Add OpenAPI documentation, authentication/authorization and rate/timeout policies.
7. Add metrics for calculation volume, validation failures and latency.

Do not add RabbitMQ or Temporal to this calculation path without an asynchronous business requirement. A deterministic calculation needed immediately by Offer is a reasonable synchronous HTTP use case.


## 9. Running the unit tests

The normal service image installs production dependencies only. To build a temporary test image with PHPUnit, run from the repository root:

~~~bash
docker build -f docker/php/service.Dockerfile \
  --build-arg SERVICE_PATH=apps/pricing-service \
  --build-arg COMPOSER_NO_DEV= \
  -t autoflow-pricing-test .

docker run --rm --entrypoint vendor/bin/phpunit autoflow-pricing-test
~~~

The build uses the same PHP 8.4 service image and Composer manifest, but includes development dependencies. The normal Compose Pricing image remains free of PHPUnit.
