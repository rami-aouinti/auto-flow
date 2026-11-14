package orders

import (
	"testing"
)

func TestDecodeEventAcceptsOrderLifecycleEnvelope(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"OrderSubmitted","schemaVersion":1,"producer":"order-service","tenantId":"tenant-a","correlationId":"corr-123","aggregate":{"type":"Order","id":"order-1"},"occurredAt":"2026-11-06T08:00:00Z","data":{"status":"submitted"}}`)
	event, err := DecodeEvent(body)
	if err != nil {
		t.Fatalf("DecodeEvent() error = %v", err)
	}
	if event.EventID != "evt-1" || event.Aggregate.ID != "order-1" || event.CorrelationID != "corr-123" {
		t.Fatalf("unexpected event: %+v", event)
	}
	if got := WorkflowID(event); got != "order-lifecycle-tenant-a-order-1" {
		t.Fatalf("unexpected workflow ID %q", got)
	}
}

func TestDecodeEventRejectsUnsupportedType(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"CustomerDeleted","tenantId":"tenant-a","aggregate":{"type":"Order","id":"order-1"}}`)
	if _, err := DecodeEvent(body); err == nil {
		t.Fatal("expected unsupported event to be rejected")
	}
}

func TestDecodeEventRejectsMissingTenant(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"OrderSubmitted","schemaVersion":1,"producer":"order-service","aggregate":{"type":"Order","id":"order-1"}}`)
	if _, err := DecodeEvent(body); err == nil {
		t.Fatal("expected missing tenant to be rejected")
	}
}

func TestDecodeEventAcceptsActualOrderCreatedEnvelope(t *testing.T) {
	body := []byte(`{"eventId":"evt-created","eventType":"OrderCreated","schemaVersion":1,"producer":"order-service","tenantId":"tenant-a","aggregate":{"type":"Order","id":"order-42"},"data":{"orderId":"order-42","vehicleId":"variant-42"}}`)
	event, err := DecodeEvent(body)
	if err != nil {
		t.Fatalf("DecodeEvent() error = %v", err)
	}
	if event.EventType != "OrderCreated" || event.Producer != "order-service" {
		t.Fatalf("unexpected OrderCreated event: %+v", event)
	}
}

func TestDecodeEventRejectsWrongProducer(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"OrderCreated","schemaVersion":1,"producer":"untrusted-service","tenantId":"tenant-a","aggregate":{"type":"Order","id":"order-1"}}`)
	if _, err := DecodeEvent(body); err == nil {
		t.Fatal("expected wrong producer to be rejected")
	}
}
