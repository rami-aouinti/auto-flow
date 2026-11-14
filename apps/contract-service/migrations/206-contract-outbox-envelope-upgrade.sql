-- Upgrade pending legacy Contract Outbox payloads to the versioned integration envelope.
-- Published rows are immutable history and are intentionally left untouched.
UPDATE contract_outbox
SET payload = jsonb_build_object(
    'eventId', event_id,
    'eventType', event_type,
    'schemaVersion', 1,
    'producer', 'contract-service',
    'aggregate', jsonb_build_object('type', 'Contract', 'id', contract_id),
    'tenantId', tenant_id,
    'occurredAt', to_jsonb(CURRENT_TIMESTAMP),
    'data', payload
)
WHERE published_at IS NULL
  AND jsonb_typeof(payload) = 'object'
  AND NOT (payload ? 'eventId' AND payload ? 'data');
