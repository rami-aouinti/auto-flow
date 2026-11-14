package orders

import (
	"context"
	"errors"
	"log"
	"os"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
	"go.temporal.io/api/enums/v1"
	"go.temporal.io/api/serviceerror"
	"go.temporal.io/sdk/client"
)

func ConsumeOrderEvents(ctx context.Context, temporal client.Client) {
	for ctx.Err() == nil {
		if err := consumeOnce(ctx, temporal); err != nil && ctx.Err() == nil {
			log.Printf("order event consumer disconnected: %v; retrying", err)
			select {
			case <-ctx.Done():
				return
			case <-time.After(3 * time.Second):
			}
		}
	}
}

func consumeOnce(ctx context.Context, temporal client.Client) error {
	connection, err := amqp.Dial(rabbitURL())
	if err != nil {
		return err
	}
	defer connection.Close()
	channel, err := connection.Channel()
	if err != nil {
		return err
	}
	defer channel.Close()

	if err := channel.ExchangeDeclare("autoflow.order.events", "topic", true, false, false, false, nil); err != nil {
		return err
	}
	if err := channel.ExchangeDeclare("autoflow.order.workflow.dlx", "direct", true, false, false, false, nil); err != nil {
		return err
	}
	if _, err := channel.QueueDeclare("autoflow.order.workflow.dead", true, false, false, false, nil); err != nil {
		return err
	}
	if err := channel.QueueBind("autoflow.order.workflow.dead", "malformed", "autoflow.order.workflow.dlx", false, nil); err != nil {
		return err
	}
	queue, err := channel.QueueDeclare("autoflow.order.workflow", true, false, false, false, amqp.Table{
		"x-dead-letter-exchange": "autoflow.order.workflow.dlx",
		"x-dead-letter-routing-key": "malformed",
	})
	if err != nil {
		return err
	}
	for _, key := range []string{
		"OrderCreated", "OrderSubmitted", "OrderUnderReview", "OrderAccepted", "OrderRejected",
		"OrderCancelled", "OrderContractPending", "OrderCompleted",
		"OrderPaymentAuthorized", "OrderPaymentCaptured", "OrderPaymentFailed",
		"OrderPaymentRefunded", "OrderPaymentPartiallyRefunded", "OrderPaymentCancelled",
	} {
		if err := channel.QueueBind(queue.Name, key, "autoflow.order.events", false, nil); err != nil {
			return err
		}
	}
	if err := channel.Qos(10, 0, false); err != nil {
		return err
	}
	deliveries, err := channel.Consume(queue.Name, "order-workflow-worker", false, false, false, false, nil)
	if err != nil {
		return err
	}

	for {
		select {
		case <-ctx.Done():
			return nil
		case delivery, ok := <-deliveries:
			if !ok {
				return errors.New("RabbitMQ delivery channel closed")
			}
			event, decodeErr := DecodeEvent(delivery.Body)
			if decodeErr != nil {
				log.Printf("rejecting malformed order event: %v", decodeErr)
				_ = delivery.Nack(false, false)
				continue
			}
			if err := forwardToTemporal(ctx, temporal, event); err != nil {
				log.Printf("could not forward event %s to Temporal: %v", event.EventID, err)
				_ = delivery.Nack(false, true)
				select {
				case <-ctx.Done():
					return nil
				case <-time.After(time.Second):
				}
				continue
			}
			if err := delivery.Ack(false); err != nil {
				return err
			}
		}
	}
}

func forwardToTemporal(ctx context.Context, temporal client.Client, event Event) error {
	id := WorkflowID(event)
	input := WorkflowInput{TenantID: event.TenantID, OrderID: event.Aggregate.ID, CorrelationID: event.CorrelationID}
	if event.EventType == "OrderCreated" || event.EventType == "OrderSubmitted" {
		_, err := temporal.ExecuteWorkflow(ctx, client.StartWorkflowOptions{
			ID: id,
			TaskQueue: env("TEMPORAL_TASK_QUEUE", "autoflow-order-workflows"),
			WorkflowIDReusePolicy: enums.WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
			WorkflowExecutionErrorWhenAlreadyStarted: true,
		}, OrderLifecycleWorkflow, input)
		if err != nil && !isAlreadyStarted(err) {
			return err
		}
		return nil
	}
	err := temporal.SignalWorkflow(ctx, id, "", LifecycleSignal, event)
	if err != nil {
		var notFound *serviceerror.NotFound
		if errors.As(err, &notFound) {
			// The workflow has already reached a terminal state; late duplicate events are harmless.
			return nil
		}
	}
	return err
}

func isAlreadyStarted(err error) bool {
	if err == nil {
		return false
	}
	var alreadyStarted *serviceerror.WorkflowExecutionAlreadyStarted
	return errors.As(err, &alreadyStarted)
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
