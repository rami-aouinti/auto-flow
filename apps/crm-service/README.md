# AutoFlow CRM Service

Independent sales CRM bounded context. CRM owns the leasing application pipeline and advisor workspace. Customer Service remains the system of record for customer profiles; Offer, Pricing and Vehicle Catalog own their respective data. CRM stores opaque external identifiers and never performs cross-service database joins.

## Capabilities

- Tenant-scoped application submission with actor-scoped idempotency.
- Explicit application lifecycle: submitted, under_review, information_required, eligible, declined, approved, converted and withdrawn.
- Eligibility review status and bounded JSON result capture. This is a review record, not an automated credit decision.
- Sales advisor assignment.
- Advisor tasks with due dates and completion lifecycle.
- Customer interaction timeline for phone, email, meetings, notes and system activity.
- Immutable audit history and transactional versioned Outbox events.
- PostgreSQL row-level security on every CRM-owned table.

## API

All routes require a verified Keycloak bearer token, tenant claim and the permission shown below.

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| POST | /api/v1/crm/applications | crm:applications:write | Submit a leasing application; requires Idempotency-Key |
| GET | /api/v1/crm/applications | crm:applications:read | Filter and paginate applications |
| GET | /api/v1/crm/applications/{id} | crm:applications:read | Read an application |
| POST | /api/v1/crm/applications/{id}/convert | crm:applications:manage + orders:write | Create an Order from an approved, eligible application |
| PATCH | /api/v1/crm/applications/{id}/status | crm:applications:manage | Execute a permitted lifecycle transition |
| PUT | /api/v1/crm/applications/{id}/assignment | crm:applications:assign | Assign a sales advisor |
| PUT | /api/v1/crm/applications/{id}/eligibility | crm:eligibility:manage | Record eligibility review outcome |
| POST/GET | /api/v1/crm/applications/{id}/tasks | crm:tasks:write / crm:tasks:read | Create and list advisor tasks |
| PATCH | /api/v1/crm/applications/{id}/tasks/{taskId} | crm:tasks:write | Update task status |
| POST/GET | /api/v1/crm/applications/{id}/interactions | crm:interactions:write / crm:interactions:read | Record and list customer interactions |
| GET | /api/v1/crm/applications/{id}/history | crm:applications:read | Read audit history |

Application submission body:

```json
{
  "customerId": "UUID-from-Customer-Service",
  "offerId": "optional-offer-UUID",
  "vehicleId": "vehicle-variant-id",
  "quoteId": "quote-reference",
  "message": "Optional applicant message"
}
```

## Events

The transactional Outbox publishes persistent messages to the `autoflow.crm.events` topic exchange. Current event types:

- `crm.application.submitted.v1`
- `crm.application.status_changed.v1`
- `crm.application.advisor_assigned.v1`
- `crm.application.eligibility_updated.v1`

Each envelope contains `eventId`, `eventType`, `schemaVersion`, `occurredAt`, `tenantId`, `producer` and `payload`. Publisher confirms are required before an Outbox row is marked published; failures are retried with bounded exponential backoff.

## Local development

```bash
docker compose --env-file .env.example up -d --build crm-service crm-outbox-publisher
curl http://localhost:8094/health/ready
```

The local Keycloak realm includes the `autoflow-crm` audience and CRM permissions for the demo user. Local-only database credentials are not production secrets.

## Explicit integration work still required

- Customer, offer, quote and vehicle references are validated synchronously through their owning APIs using the caller token; no cross-service database access is used.
- Implement automated eligibility-provider adapters and policy governance; current API records human/system review results only.
- Customer/offer lifecycle event consumers and Inbox deduplication remain future work; current submission/conversion paths revalidate references synchronously.
- Add full PostgreSQL RLS and RabbitMQ end-to-end integration tests, including duplicate publication and retry recovery.
