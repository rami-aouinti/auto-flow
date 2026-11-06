# Scalable local demo catalogue dataset

The existing `bin/load-fixtures.php` remains the small hand-curated smoke-test fixture.
For a larger deterministic and fictional dataset, run:

```bash
docker compose exec vehicle-catalog-service php bin/generate-demo-dataset.php
```

Defaults: 30 manufacturers, 300 models, 600 generations and 5,000 variants.
Optional positional arguments override those four counts in that order.

The generator:
- uses stable SHA-256-derived identifiers, so repeated runs are idempotent;
- inserts parent records before child records and runs in one transaction;
- uses only fictional demo names and synthetic technical attributes;
- preserves existing records and never truncates or deletes data;
- refuses production mode by default;
- enforces conservative upper bounds to avoid accidental huge local loads.

The generated catalogue is synthetic test data, not a factual vehicle specification database.
