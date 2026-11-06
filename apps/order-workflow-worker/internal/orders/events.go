package orders

import (
	"encoding/json"
	"errors"
	"fmt"
)

type Event struct {
	EventID   string         `json:"eventId"`
	EventType string         `json:"eventType"`
	TenantID  string         `json:"tenantId"`
	Aggregate struct {
		Type string `json:"type"`
		ID   string `json:"id"`
	} `json:"aggregate"`
	OccurredAt string         `json:"occurredAt"`
	Data       map[string]any `json:"data"`
}

func DecodeEvent(body []byte) (Event, error) {
	var event Event
	if err := json.Unmarshal(body, &event); err != nil {
		return Event{}, fmt.Errorf("decode order event: %w", err)
	}
	if event.EventID == "" || event.TenantID == "" || event.Aggregate.ID == "" {
		return Event{}, errors.New("order event is missing eventId, tenantId or aggregate.id")
	}
	if event.Aggregate.Type != "Order" {
		return Event{}, fmt.Errorf("unsupported aggregate type %q", event.Aggregate.Type)
	}
	switch event.EventType {
	case "OrderSubmitted", "OrderUnderReview", "OrderAccepted", "OrderRejected",
		"OrderCancelled", "OrderContractPending", "OrderCompleted":
		return event, nil
	default:
		return Event{}, fmt.Errorf("unsupported order event type %q", event.EventType)
	}
}

func WorkflowID(event Event) string {
	return "order-lifecycle-" + event.TenantID + "-" + event.Aggregate.ID
}
