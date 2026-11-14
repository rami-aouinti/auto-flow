# Search service tests

Manual smoke checks after indexing:

```bash
curl -fsS 'http://localhost:9200/autoflow-vehicles-v1/_count'
curl -fsS 'http://localhost:8097/health/ready'
```

Integration tests against the Compose Elasticsearch container are still required before production rollout.
