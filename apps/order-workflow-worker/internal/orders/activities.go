package orders

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

type Activities struct {
}

func (a *Activities) PublishSLAAlert(ctx context.Context, alert SLAAlert) error {
	connection, err := amqp.Dial(rabbitURL())
	if err != nil {
		return fmt.Errorf("connect to RabbitMQ for SLA alert: %w", err)
	}
	defer connection.Close()
	channel, err := connection.Channel()
	if err != nil {
		return fmt.Errorf("open RabbitMQ channel: %w", err)
	}
	defer channel.Close()

	if err := channel.ExchangeDeclare("autoflow.order.events", "topic", true, false, false, false, nil); err != nil {
		return err
	}
	queue, err := channel.QueueDeclare("autoflow.order.workflow.alerts", true, false, false, false, nil)
	if err != nil {
		return err
	}
	if err := channel.QueueBind(queue.Name, "OrderWorkflowSlaBreached", "autoflow.order.events", false, nil); err != nil {
		return err
	}
	if err := channel.Confirm(false); err != nil {
		return err
	}
	confirmations := channel.NotifyPublish(make(chan amqp.Confirmation, 1))

	now := time.Now().UTC()
	eventID := fmt.Sprintf("sla-%s-%s", alert.OrderID, alert.Phase)
	envelope := map[string]any{
		"eventId": eventID,
		"eventType": "OrderWorkflowSlaBreached",
		"schemaVersion": 1,
		"producer": "order-workflow-worker",
		"aggregate": map[string]string{"type": "Order", "id": alert.OrderID},
		"tenantId": alert.TenantID,\n\t\t"correlationId": alert.CorrelationID,
		"occurredAt": now.Format(time.RFC3339Nano),
		"data": map[string]string{"phase": alert.Phase, "threshold": alert.After},
	}
	body, err := json.Marshal(envelope)
	if err != nil {
		return err
	}
	if err := channel.PublishWithContext(ctx, "autoflow.order.events", "OrderWorkflowSlaBreached", false, false, amqp.Publishing{
		ContentType: "application/json",
		DeliveryMode: amqp.Persistent,
		Type: "OrderWorkflowSlaBreached",
		MessageId: eventID,
		Timestamp: now,
		Body: body,
	}); err != nil {
		return err
	}
	select {
	case confirmation := <-confirmations:
		if !confirmation.Ack {
			return fmt.Errorf("RabbitMQ negatively acknowledged SLA event %s", eventID)
		}
		return nil
	case <-ctx.Done():
		return ctx.Err()
	case <-time.After(5 * time.Second):
		return fmt.Errorf("timed out waiting for RabbitMQ publisher confirm for %s", eventID)
	}
}
