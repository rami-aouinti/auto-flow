# Pricing demo fixtures

The SQL fixture is aligned with the seven curated Vehicle Catalog variants from `vehicle_catalog_demo.sql`.

| Quote ID | Vehicle variant | Price | Down payment | Residual | Duration | Rate |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `aaaa…aaaa` | BMW 320d | €42,000 | €5,000 | €8,000 | 48 months | 4.25% |
| `bbbb…bbbb` | VW ID.4 Pro | €38,990 | €5,000 | €9,500 | 48 months | 4.25% |
| `cccc…cccc` | BMW 330e | €35,990 | €4,000 | €7,200 | 48 months | 4.25% |
| `dddd…dddd` | Audi A4 40 TDI | €41,990 | €5,000 | €8,500 | 48 months | 4.25% |
| `eeee…eeee` | Mercedes C 220 d | €44,990 | €5,000 | €7,800 | 48 months | 4.25% |
| `ffff…ffff` | VW Golf 2.0 TDI | €30,000 | €5,000 | €12,000 | 48 months | 4.50% |
| `7777…7777` | VW Golf 1.5 eTSI | €25,000 | €4,000 | €7,500 | 36 months | 3.50% |

The fixture creates/activates one tenant-scoped policy and upserts the seven quote snapshots. The `created_by` value must be the actual Keycloak `sub` claim so the demo user can read their own quotes. The root `bin/load-demo-fixtures.sh` script extracts that claim and loads Catalog, Pricing and Offer data in the correct order.

The fixture is additive and repeatable. It does not truncate or delete rows.
