package orders

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

type ReservationSagaCommand struct {
	TenantID         string
	OrderID          string
	VehicleVariantID string
	ReservationID    string
	CorrelationID    string
}

func (a *Activities) RequestReservationHold(ctx context.Context, command ReservationSagaCommand) error {
	return publishReservationCommand(ctx, command, "reservation.hold.requested.v1", map[string]any{
		"orderId": command.OrderID,
		"vehicleVariantId": command.VehicleVariantID,
		"expiresAt": time.Now().UTC().Add(23 * time.Hour).Format(time.RFC3339Nano),
	})
}

func (a *Activities) RequestReservationConfirm(ctx context.Context, command ReservationSagaCommand) error {
	return publishReservationCommand(ctx, command, "reservation.confirm.requested.v1", map[string]any{
		"orderId": command.OrderID, "reservationId": command.ReservationID,
	})
}

func (a *Activities) RequestReservationRelease(ctx context.Context, command ReservationSagaCommand) error {
	return publishReservationCommand(ctx, command, "reservation.release.requested.v1", map[string]any{
		"orderId": command.OrderID, "reservationId": command.ReservationID,
	})
}

func publishReservationCommand(ctx context.Context, command ReservationSagaCommand, eventType string, data map[string]any) error {
	connection, err := amqp.Dial(rabbitURL())
	if err != nil {
		return fmt.Errorf("connect to RabbitMQ for reservation command: %w", err)
	}
	defer connection.Close()
	channel, err := connection.Channel()
	if err != nil {
		return fmt.Errorf("open RabbitMQ channel: %w", err)
	}
	defer channel.Close()
	if err := channel.ExchangeDeclare("autoflow.vehicle-reservation.commands", "topic", true, false, false, false, nil); err != nil {
		return err
	}
	if err := channel.Confirm(false); err != nil {
		return err
	}
	confirmations := channel.NotifyPublish(make(chan amqp.Confirmation, 1))
	eventID := newEventUUID(command.TenantID, command.OrderID, eventType)
	now := time.Now().UTC()
	envelope := map[string]any{
		"eventId": eventID, "eventType": eventType, "schemaVersion": 1,
		"producer": "order-workflow-worker",
		"aggregate": map[string]string{"type": "Order", "id": command.OrderID},
		"tenantId": command.TenantID, "correlationId": command.CorrelationID,
		"occurredAt": now.Format(time.RFC3339Nano), "data": data,
	}
	body, err := json.Marshal(envelope)
	if err != nil {
		return err
	}
	if err := channel.PublishWithContext(ctx, "autoflow.vehicle-reservation.commands", eventType, false, false, amqp.Publishing{
		ContentType: "application/json", DeliveryMode: amqp.Persistent,
		Type: eventType, MessageId: eventID, Timestamp: now, Body: body,
	}); err != nil {
		return err
	}
	select {
	case confirmation := <-confirmations:
		if !confirmation.Ack {
			return fmt.Errorf("RabbitMQ negatively acknowledged reservation command %s", eventID)
		}
		return nil
	case <-ctx.Done():
		return ctx.Err()
	case <-time.After(5 * time.Second):
		return fmt.Errorf("timed out waiting for publisher confirm for %s", eventID)
	}
}

func newEventUUID(tenantID, orderID, eventType string) string {
	sum := sha256.Sum256([]byte(tenantID + ":" + orderID + ":" + eventType))
	bytes := sum[:16]
	bytes[6] = (bytes[6] & 0x0f) | 0x50
	bytes[8] = (bytes[8] & 0x3f) | 0x80
	encoded := hex.EncodeToString(bytes)
	return encoded[:8] + "-" + encoded[8:12] + "-" + encoded[12:16] + "-" + encoded[16:20] + "-" + encoded[20:]
}
