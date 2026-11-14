package orders

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strings"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
	"go.temporal.io/api/enums/v1"
	"go.temporal.io/api/serviceerror"
	"go.temporal.io/sdk/client"
)

const sagaWorkflowIDPrefix = "order-fulfillment-saga-"

func ConsumeSagaEvents(ctx context.Context, temporal client.Client) {
	for ctx.Err() == nil {
		if err := consumeSagaEventsOnce(ctx, temporal); err != nil && ctx.Err() == nil {
			log.Printf("order Saga event consumer disconnected: %v; retrying", err)
			select {
			case <-ctx.Done():
				return
			case <-time.After(3 * time.Second):
			}
		}
	}
}

func consumeSagaEventsOnce(ctx context.Context, temporal client.Client) error {
	connection, err := amqp.Dial(rabbitURL())
	if err != nil { return err }
	defer connection.Close()
	channel, err := connection.Channel()
	if err != nil { return err }
	defer channel.Close()

	exchanges := []string{
		"autoflow.order.events", "autoflow.vehicle-reservation.events",
		"autoflow.contract.events", "autoflow.fulfillment.events",
	}
	for _, exchange := range exchanges {
		if err := channel.ExchangeDeclare(exchange, "topic", true, false, false, false, nil); err != nil {
			return err
		}
	}
	if err := channel.ExchangeDeclare("autoflow.order.saga.dlx", "direct", true, false, false, false, nil); err != nil {
		return err
	}
	if _, err := channel.QueueDeclare("autoflow.order.saga.dead", true, false, false, false, nil); err != nil {
		return err
	}
	if err := channel.QueueBind("autoflow.order.saga.dead", "invalid", "autoflow.order.saga.dlx", false, nil); err != nil {
		return err
	}
	queue, err := channel.QueueDeclare("autoflow.order.saga.events", true, false, false, false, amqp.Table{
		"x-dead-letter-exchange": "autoflow.order.saga.dlx",
		"x-dead-letter-routing-key": "invalid",
	})
	if err != nil { return err }

	bindings := []struct{ exchange, key string }{
		{"autoflow.order.events", "OrderCreated"},
		{"autoflow.order.events", "OrderSubmitted"},
		{"autoflow.order.events", "OrderAccepted"},
		{"autoflow.order.events", "OrderRejected"},
		{"autoflow.order.events", "OrderCancelled"},
		{"autoflow.order.events", "OrderPaymentCaptured"},
		{"autoflow.order.events", "OrderPaymentFailed"},
		{"autoflow.order.events", "OrderPaymentRefunded"},
		{"autoflow.order.events", "OrderPaymentCancelled"},
		{"autoflow.vehicle-reservation.events", "reservation.held.v1"},
		{"autoflow.vehicle-reservation.events", "reservation.hold.rejected.v1"},
		{"autoflow.vehicle-reservation.events", "reservation.confirmed.v1"},
		{"autoflow.vehicle-reservation.events", "reservation.expired.v1"},
		{"autoflow.vehicle-reservation.events", "reservation.released.v1"},
		{"autoflow.contract.events", "contract.signed.v1"},
		{"autoflow.fulfillment.events", "fulfillment.ready_for_preparation.v1"},
	}
	for _, binding := range bindings {
		if err := channel.QueueBind(queue.Name, binding.key, binding.exchange, false, nil); err != nil {
			return err
		}
	}
	if err := channel.Qos(10, 0, false); err != nil { return err }
	deliveries, err := channel.Consume(queue.Name, "order-fulfillment-saga-worker", false, false, false, false, nil)
	if err != nil { return err }

	for {
		select {
		case <-ctx.Done():
			return nil
		case delivery, ok := <-deliveries:
			if !ok { return errors.New("Saga RabbitMQ delivery channel closed") }
			event, decodeErr := DecodeSagaEvent(delivery.Body)
			if decodeErr != nil {
				log.Printf("dead-lettering invalid Saga event: %v", decodeErr)
				_ = delivery.Nack(false, false)
				continue
			}
			if delivery.Type != event.EventType || delivery.MessageId != event.EventID {
				log.Printf("dead-lettering Saga event with inconsistent RabbitMQ metadata: %s", event.EventID)
				_ = delivery.Nack(false, false)
				continue
			}
			if err := forwardSagaEventToTemporal(ctx, temporal, event); err != nil {
				log.Printf("could not forward Saga event %s: %v", event.EventID, err)
				_ = delivery.Nack(false, true)
				continue
			}
			if err := delivery.Ack(false); err != nil { return err }
		}
	}
}

func forwardSagaEventToTemporal(ctx context.Context, temporal client.Client, event Event) error {
	id := sagaWorkflowID(event)
	if event.EventType == "OrderCreated" || event.EventType == "OrderSubmitted" {
		variantID := eventString(event.Data, "vehicleVariantId")
		if variantID == "" {
			variantID = eventString(event.Data, "vehicleId")
		}
		if variantID == "" {
			return fmt.Errorf("%s event is missing data.vehicleVariantId/vehicleId", event.EventType)
		}
		_, err := temporal.ExecuteWorkflow(ctx, client.StartWorkflowOptions{
			ID: id, TaskQueue: env("TEMPORAL_TASK_QUEUE", "autoflow-order-workflows"),
			WorkflowIDReusePolicy: enums.WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
			WorkflowExecutionErrorWhenAlreadyStarted: true,
		}, OrderFulfillmentSagaWorkflow, SagaWorkflowInput{
			TenantID: event.TenantID, OrderID: event.Aggregate.ID,
			VehicleVariantID: variantID, CorrelationID: event.CorrelationID,
		})
		if err != nil && !isAlreadyStarted(err) { return err }
		return nil
	}
	err := temporal.SignalWorkflow(ctx, id, "", SagaSignal, event)
	if err != nil {
		var notFound *serviceerror.NotFound
		if errors.As(err, &notFound) {
			// Do not ACK an event that arrived before its OrderCreated start event.
			// RabbitMQ redelivery lets the consumer signal it after the workflow exists.
			return fmt.Errorf("Saga workflow %s is not started yet; retry event %s: %w", id, event.EventID, err)
		}
	}
	return err
}

func sagaWorkflowID(event Event) string {
	return sagaWorkflowIDPrefix + event.TenantID + "-" + event.Aggregate.ID
}

func DecodeSagaEvent(body []byte) (Event, error) {
	var event Event
	if err := json.Unmarshal(body, &event); err != nil {
		return Event{}, fmt.Errorf("decode Saga event: %w", err)
	}
	allowed := map[string]bool{
		"OrderCreated": true, "OrderSubmitted": true, "OrderAccepted": true, "OrderRejected": true,
		"OrderCancelled": true, "OrderPaymentCaptured": true, "OrderPaymentFailed": true,
		"OrderPaymentRefunded": true, "OrderPaymentCancelled": true, "reservation.held.v1": true,
		"reservation.hold.rejected.v1": true, "reservation.confirmed.v1": true,
		"reservation.expired.v1": true, "reservation.released.v1": true,
		"contract.signed.v1": true, "fulfillment.ready_for_preparation.v1": true,
	}
	if !allowed[event.EventType] {
		return Event{}, fmt.Errorf("unsupported Saga event type %q", event.EventType)
	}
	if event.Aggregate.Type != "Order" {
		orderID := eventString(event.Data, "orderId")
		if orderID == "" {
			return Event{}, fmt.Errorf("event %s does not identify its order", event.EventID)
		}
		event.Aggregate.Type = "Order"
		event.Aggregate.ID = orderID
	}
	if event.EventID == "" || event.TenantID == "" || event.Aggregate.ID == "" {
		return Event{}, errors.New("Saga event is missing eventId, tenantId or orderId")
	}
	if event.SchemaVersion != 1 {
		return Event{}, fmt.Errorf("unsupported Saga event schema version %d", event.SchemaVersion)
	}
	expectedProducer := ""
	switch {
	case event.EventType == "OrderCreated" || event.EventType == "OrderSubmitted" ||
		event.EventType == "OrderAccepted" || event.EventType == "OrderRejected" ||
		event.EventType == "OrderCancelled" || event.EventType == "OrderPaymentCaptured" ||
		event.EventType == "OrderPaymentFailed" || event.EventType == "OrderPaymentRefunded" || event.EventType == "OrderPaymentCancelled":
		expectedProducer = "order-service"
	case strings.HasPrefix(event.EventType, "reservation."):
		expectedProducer = "vehicle-reservation-service"
	case strings.HasPrefix(event.EventType, "contract."):
		expectedProducer = "contract-service"
	case strings.HasPrefix(event.EventType, "fulfillment."):
		expectedProducer = "fulfillment-service"
	}
	if expectedProducer == "" || event.Producer != expectedProducer {
		return Event{}, fmt.Errorf("unexpected producer %q for Saga event %q", event.Producer, event.EventType)
	}
	if len(event.TenantID) > 128 {
		return Event{}, errors.New("Saga event tenantId exceeds the supported length")
	}
	return event, nil
}
