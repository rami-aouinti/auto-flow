# Local demo platform fixtures

This dataset is intended for the local AutoFlow development environment. It creates a
small, coherent set of fictional records for the main persisted business domains so the
Vue frontend can show customers, vehicles, offers, quotes, orders, payments, invoices,
contracts, inventory, reservations, fulfilment and CRM activity together.

## Apply

Start the local stack and wait until the PostgreSQL bootstrap job has completed:

```bash
docker compose --env-file .env.example up -d --build
docker compose --env-file .env.example ps
```

From the repository root, obtain the local demo user's Keycloak subject and apply the fixtures. Pricing quote ownership is tied to the authenticated subject, so the same subject is written into the seeded records:

```bash
TOKEN=$(curl -fsS -X POST http://localhost:8080/realms/autoflow/protocol/openid-connect/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'client_id=autoflow-local-client' \
  --data-urlencode 'username=demo' \
  --data-urlencode 'password=demo' | jq -r .access_token)

DEMO_SUBJECT=$(TOKEN="$TOKEN" php -r '$p=explode(".",getenv("TOKEN"))[1]; $p.=str_repeat("=", (4-strlen($p)%4)%4); $claims=json_decode(base64_decode(strtr($p,"-_","+/")), true, 512, JSON_THROW_ON_ERROR); echo $claims["sub"];')

docker compose --env-file .env.example exec -T postgres \
  psql -v ON_ERROR_STOP=1 -U autoflow -d autoflow \
  -v demo_subject="$DEMO_SUBJECT" \
  < infrastructure/fixtures/demo-platform.sql
```

The SQL uses stable IDs and `ON CONFLICT DO NOTHING`, so it can be run repeatedly.
It never truncates tables or deletes user data. It targets the local `tenant-demo`
tenant and uses fictional `.test` email addresses and clearly labelled demo payment
provider references.

## What is seeded

- Vehicle Catalog: four manufacturers, five models, five generations and seven variants.
- Customer: five customer profiles, six addresses and consent records.
- Pricing and Offer: seven quote snapshots, one active demo policy and five offers in
  different lifecycle states.
- Order: five orders linked to the seeded customer, offer, quote and vehicle snapshots.
- Payment and Billing: four payment states and one paid invoice.
- Contract: signed, awaiting-signature and draft contract records with immutable demo
  legal snapshots.
- Inventory and Vehicle Reservation: five physical stock units and three reservations.
- Fulfillment: delivered, scheduled and prerequisite-pending fulfilment records.
- CRM: four applications, three advisor tasks and three interaction timeline entries.

## Demo journeys

| Customer | Journey |
| --- | --- |
| Lena Schneider | Completed order, captured payment, paid invoice, signed contract, fulfilled reservation and delivered vehicle |
| Jonas Weber | Accepted order, payment requiring customer action, contract awaiting signature and scheduled delivery |
| Mia Fischer | Order under review, failed payment, draft contract and held reservation |
| Nordlicht Mobility GmbH | Submitted fleet order and pending payment |
| Noah Wagner | Rejected offer/order and declined CRM application |

## Important boundaries

These are read-model/demo records inserted directly into the owning service tables. The
script deliberately does **not** write to transactional Outbox tables: doing so could
replay synthetic business events into RabbitMQ and trigger real consumers/workflows.

The script does not create fake AI conversations, notification delivery receipts, media
binary objects or configuration records. Those capabilities are not represented by
the relational business tables seeded here: AI output must come from the configured
local Ollama model, notifications should be produced by actual application events,
and downloadable documents/media need real files in their configured storage.

The SQL runs as the local PostgreSQL bootstrap user so it can seed tenant-protected
tables without weakening Row-Level Security. Do not run this script against production
or a shared environment.
