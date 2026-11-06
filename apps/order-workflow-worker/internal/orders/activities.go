package orders

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"net/url"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

type Activities struct {
}

func (a *Activities) PublishSLAAlert(ctx context.Context, alert SLAAlert) error {
	host := env("RABBITMQ_HOST", "rabbitmq")
	port := env("RABBITMQ_PORT", "5672")
	user := env("RABBITMQ_USER", "autoflow")
	password := env("RABBITMQ_PASSWORD", "autoflow")
	vhost := env("RABBITMQ_VHOST", "/")
	url := fmt.Sprintf("amqp://%s:%s@%s:%s/%s", user, password, host, port, vhost)
	connection, err := amqp.Dial(url)
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
	now := time.Now().UTC()
	envelope := map[string]any{
		"eventId": fmt.Sprintf("sla-%s-%d", alert.OrderID, now.UnixNano()),
		"eventType": "OrderWorkflowSlaBreached",
		"schemaVersion": 1,
		"producer": "order-workflow-worker",
		"aggregate": map[string]string{"type": "Order", "id": alert.OrderID},
		"tenantId": alert.TenantID,
		"occurredAt": now.Format(time.RFC3339Nano),
		"data": map[string]string{"phase": alert.Phase, "threshold": alert.After},
	}
	body, err := json.Marshal(envelope)
	if err != nil {
		return err
	}
	return channel.PublishWithContext(ctx, "autoflow.order.events", "OrderWorkflowSlaBreached", false, false, amqp.Publishing{
		ContentType: "application/json",
		DeliveryMode: amqp.Persistent,
		Type: "OrderWorkflowSlaBreached",
		MessageId: envelope["eventId"].(string),
		Timestamp: now,
		Body: body,
	})
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
