package orders

import "testing"

func TestDecodeSagaEventUsesOrderReferenceFromReservationAggregate(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"reservation.held.v1","schemaVersion":1,"producer":"vehicle-reservation-service","aggregate":{"type":"VehicleReservation","id":"reservation-1"},"tenantId":"tenant-a","data":{"id":"reservation-1","orderId":"order-1"}}`)
	event, err := DecodeSagaEvent(body)
	if err != nil {
		t.Fatalf("DecodeSagaEvent() error = %v", err)
	}
	if event.Aggregate.Type != "Order" || event.Aggregate.ID != "order-1" {
		t.Fatalf("unexpected normalized aggregate: %+v", event.Aggregate)
	}
}

func TestDecodeSagaEventRejectsReservationWithoutOrderReference(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"reservation.held.v1","schemaVersion":1,"producer":"vehicle-reservation-service","aggregate":{"type":"VehicleReservation","id":"reservation-1"},"tenantId":"tenant-a","data":{"id":"reservation-1"}}`)
	if _, err := DecodeSagaEvent(body); err == nil {
		t.Fatal("expected missing order reference to be rejected")
	}
}

func TestDecodeSagaEventRejectsUnsupportedEvent(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"CustomerDeleted","aggregate":{"type":"Customer","id":"customer-1"},"tenantId":"tenant-a","data":{"orderId":"order-1"}}`)
	if _, err := DecodeSagaEvent(body); err == nil {
		t.Fatal("expected unsupported Saga event to be rejected")
	}
}

func TestDecodeSagaEventAcceptsActualOrderCreatedEnvelope(t *testing.T) {
	body := []byte(`{"eventId":"evt-created","eventType":"OrderCreated","schemaVersion":1,"producer":"order-service","aggregate":{"type":"Order","id":"order-42"},"tenantId":"tenant-a","data":{"orderId":"order-42","vehicleId":"variant-42"}}`)
	event, err := DecodeSagaEvent(body)
	if err != nil {
		t.Fatalf("DecodeSagaEvent() error = %v", err)
	}
	if event.EventType != "OrderCreated" || eventString(event.Data, "vehicleId") != "variant-42" {
		t.Fatalf("unexpected OrderCreated event: %+v", event)
	}
}

func TestDecodeSagaEventRejectsWrongProducer(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"reservation.held.v1","schemaVersion":1,"producer":"order-service","aggregate":{"type":"VehicleReservation","id":"reservation-1"},"tenantId":"tenant-a","data":{"orderId":"order-1"}}`)
	if _, err := DecodeSagaEvent(body); err == nil {
		t.Fatal("expected mismatched producer to be rejected")
	}
}

func TestDecodeSagaEventRejectsUnsupportedSchemaVersion(t *testing.T) {
	body := []byte(`{"eventId":"evt-1","eventType":"reservation.held.v1","schemaVersion":2,"producer":"vehicle-reservation-service","aggregate":{"type":"VehicleReservation","id":"reservation-1"},"tenantId":"tenant-a","data":{"orderId":"order-1"}}`)
	if _, err := DecodeSagaEvent(body); err == nil {
		t.Fatal("expected unsupported schema version to be rejected")
	}
}

func TestDecodeSagaEventNormalizesContractSignedEnvelopeToOrder(t *testing.T) {
	body := []byte(`{"eventId":"evt-contract","eventType":"contract.signed.v1","schemaVersion":1,"producer":"contract-service","aggregate":{"type":"Contract","id":"contract-1"},"tenantId":"tenant-a","data":{"contractId":"contract-1","orderId":"order-1","status":"signed"}}`)
	event, err := DecodeSagaEvent(body)
	if err != nil {
		t.Fatalf("DecodeSagaEvent() error = %v", err)
	}
	if event.Aggregate.Type != "Order" || event.Aggregate.ID != "order-1" {
		t.Fatalf("contract event was not normalized to its order: %+v", event.Aggregate)
	}
}
