package orders

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"os"
	"testing"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
	"go.temporal.io/api/enums/v1"
	"go.temporal.io/sdk/client"
)

func TestLiveRabbitMQTemporalSagaStartsAndPublishesReservationHold(t *testing.T) {
	if os.Getenv("AUTOFLOW_TEMPORAL_RABBITMQ_E2E") != "1" {
		t.Skip("set AUTOFLOW_TEMPORAL_RABBITMQ_E2E=1 to run the live RabbitMQ/Temporal integration test")
	}

	ctx, cancel := context.WithTimeout(context.Background(), 45*time.Second)
	defer cancel()

	temporalClient, err := client.Dial(client.Options{
		HostPort:  env("TEMPORAL_ADDRESS", "127.0.0.1:7233"),
		Namespace: env("TEMPORAL_NAMESPACE", "default"),
	})
	if err != nil {
		t.Fatalf("connect to Temporal: %v", err)
	}
	defer temporalClient.Close()

	connection, err := amqp.Dial(rabbitURL())
	if err != nil {
		t.Fatalf("connect to RabbitMQ: %v", err)
	}
	defer connection.Close()
	channel, err := connection.Channel()
	if err != nil {
		t.Fatalf("open RabbitMQ channel: %v", err)
	}
	defer channel.Close()

	if err := channel.ExchangeDeclare("autoflow.order.events", "topic", true, false, false, false, nil); err != nil {
		t.Fatalf("declare Order exchange: %v", err)
	}
	if err := channel.ExchangeDeclare("autoflow.vehicle-reservation.commands", "topic", true, false, false, false, nil); err != nil {
		t.Fatalf("declare Reservation command exchange: %v", err)
	}
	queue, err := channel.QueueDeclare("", false, true, true, false, nil)
	if err != nil {
		t.Fatalf("declare temporary Reservation command queue: %v", err)
	}
	if err := channel.QueueBind(queue.Name, "reservation.hold.requested.v1", "autoflow.vehicle-reservation.commands", false, nil); err != nil {
		t.Fatalf("bind Reservation hold command: %v", err)
	}
	deliveries, err := channel.Consume(queue.Name, "temporal-saga-e2e", true, false, false, false, nil)
	if err != nil {
		t.Fatalf("consume Reservation hold command: %v", err)
	}

	tenantID := "temporal-e2e-" + integrationUUID()[:8]
	orderID := integrationUUID()
	variantID := integrationUUID()
	workflowIdentity := Event{TenantID: tenantID}
	workflowIdentity.Aggregate.Type = "Order"
	workflowIdentity.Aggregate.ID = orderID
	workflowID := sagaWorkflowID(workflowIdentity)

	publishOrderEvent := func(eventType string, data map[string]any) {
		t.Helper()
		eventID := integrationUUID()
		envelope := map[string]any{
			"eventId": eventID,
			"eventType": eventType,
			"schemaVersion": 1,
			"producer": "order-service",
			"aggregate": map[string]string{"type": "Order", "id": orderID},
			"tenantId": tenantID,
			"occurredAt": time.Now().UTC().Format(time.RFC3339Nano),
			"data": data,
		}
		body, marshalErr := json.Marshal(envelope)
		if marshalErr != nil {
			t.Fatalf("marshal %s event: %v", eventType, marshalErr)
		}
		if publishErr := channel.PublishWithContext(ctx, "autoflow.order.events", eventType, true, false, amqp.Publishing{
			ContentType: "application/json",
			DeliveryMode: amqp.Persistent,
			MessageId: eventID,
			Type: eventType,
			Body: body,
		}); publishErr != nil {
			t.Fatalf("publish %s event: %v", eventType, publishErr)
		}
	}

	publishOrderEvent("OrderCreated", map[string]any{"orderId": orderID, "vehicleId": variantID})
	publishOrderEvent("OrderAccepted", map[string]any{"orderId": orderID, "status": "accepted"})

	select {
	case delivery, ok := <-deliveries:
		if !ok {
			t.Fatal("Reservation command delivery channel closed")
		}
		var envelope struct {
			EventID   string         `json:"eventId"`
			EventType string         `json:"eventType"`
			TenantID  string         `json:"tenantId"`
			Data      map[string]any `json:"data"`
		}
		if err := json.Unmarshal(delivery.Body, &envelope); err != nil {
			t.Fatalf("decode Reservation command: %v", err)
		}
		if envelope.EventType != "reservation.hold.requested.v1"
			|| envelope.TenantID != tenantID
			|| eventString(envelope.Data, "orderId") != orderID
			|| eventString(envelope.Data, "vehicleVariantId") != variantID {
			t.Fatalf("unexpected Reservation command: %+v", envelope)
		}
	case <-ctx.Done():
		t.Fatalf("timed out waiting for Temporal Saga to publish Reservation hold command: %v", ctx.Err())
	}

	// Close the test Saga cleanly after proving that the live activity ran.
	publishOrderEvent("OrderCancelled", map[string]any{"orderId": orderID, "status": "cancelled"})
	for {
		description, describeErr := temporalClient.DescribeWorkflowExecution(ctx, workflowID, "")
		if describeErr == nil && description.GetWorkflowExecutionInfo().GetStatus() == enums.WORKFLOW_EXECUTION_STATUS_COMPLETED {
			break
		}
		select {
		case <-ctx.Done():
			t.Fatalf("Saga did not complete after cancellation: %v (describe error: %v)", ctx.Err(), describeErr)
		case <-time.After(250 * time.Millisecond):
		}
	}
}

func integrationUUID() string {
	bytes := make([]byte, 16)
	if _, err := rand.Read(bytes); err != nil {
		panic(fmt.Sprintf("generate integration UUID: %v", err))
	}
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", bytes[0:4], bytes[4:6], bytes[6:8], bytes[8:10], bytes[10:16])
}
