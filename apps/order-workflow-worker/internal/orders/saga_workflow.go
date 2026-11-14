package orders

import (
	"time"

	"go.temporal.io/sdk/temporal"
	"go.temporal.io/sdk/workflow"
)

const SagaSignal = "order.saga"

type SagaWorkflowInput struct {
	TenantID         string
	OrderID          string
	VehicleVariantID string
	CorrelationID    string
}

// OrderFulfillmentSagaWorkflow coordinates physical stock, payment, contract and fulfillment.
// All external side effects are activities; waiting and branching remain deterministic in Temporal.
func OrderFulfillmentSagaWorkflow(ctx workflow.Context, input SagaWorkflowInput) error {
	logger := workflow.GetLogger(ctx)
	if input.TenantID == "" || input.OrderID == "" || input.VehicleVariantID == "" {
		return temporal.NewNonRetryableApplicationError("tenant, order and vehicle variant are required", "InvalidSagaInput", nil)
	}

	activityCtx := workflow.WithActivityOptions(ctx, workflow.ActivityOptions{
		StartToCloseTimeout: 15 * time.Second,
		RetryPolicy: &temporal.RetryPolicy{
			InitialInterval: time.Second,
			BackoffCoefficient: 2,
			MaximumInterval: time.Minute,
			MaximumAttempts: 8,
		},
	})
	seen := make(map[string]struct{})

	decision, err := waitForSagaEvent(ctx, map[string]bool{
		"OrderAccepted": true, "OrderRejected": true, "OrderCancelled": true,
	}, 48*time.Hour, seen)
	if err != nil {
		return err
	}
	if decision.EventType != "OrderAccepted" {
		logger.Info("Saga stopped before reservation", "orderId", input.OrderID, "decision", decision.EventType)
		return nil
	}

	hold := ReservationSagaCommand{
		TenantID: input.TenantID, OrderID: input.OrderID,
		VehicleVariantID: input.VehicleVariantID,
		ExpiresAt: workflow.Now(ctx).Add(23 * time.Hour).Format(time.RFC3339Nano),
		CorrelationID: input.CorrelationID,
	}
	if err := workflow.ExecuteActivity(activityCtx, "RequestReservationHold", hold).Get(ctx, nil); err != nil {
		return err
	}

	holdEvent, err := waitForSagaEvent(ctx, map[string]bool{
		"reservation.held.v1": true,
		"reservation.hold.rejected.v1": true,
		"OrderCancelled": true,
		"OrderPaymentFailed": true,
		"OrderPaymentRefunded": true,
		"OrderPaymentCancelled": true,
	}, 30*time.Minute, seen)
	if err != nil {
		return err
	}
	if holdEvent.EventType != "reservation.held.v1" {
		logger.Warn("Saga could not obtain a vehicle reservation", "orderId", input.OrderID, "event", holdEvent.EventType)
		return nil
	}
	reservationID := eventString(holdEvent.Data, "reservationId")
	if reservationID == "" {
		reservationID = eventString(holdEvent.Data, "id")
	}
	if reservationID == "" {
		return temporal.NewNonRetryableApplicationError("reservation.held event has no reservation identifier", "InvalidReservationEvent", nil)
	}

	// Payment and contract may arrive in either order. The reservation API caps holds at 24 hours.
	// Use one shared prerequisite deadline and compensate the physical hold on timeout.
	paymentCaptured := false
	contractSigned := false
	prerequisiteDeadline := workflow.Now(ctx).Add(22 * time.Hour)
	for !paymentCaptured || !contractSigned {
		remaining := prerequisiteDeadline.Sub(workflow.Now(ctx))
		if remaining <= 0 {
			remaining = time.Nanosecond
		}
		event, waitErr := waitForSagaEvent(ctx, map[string]bool{
			"OrderPaymentCaptured": true,
			"contract.signed.v1": true,
			"OrderCancelled": true,
			"OrderPaymentFailed": true,
			"OrderPaymentRefunded": true,
			"OrderPaymentCancelled": true,
			"reservation.expired.v1": true,
			"reservation.released.v1": true,
		}, remaining, seen)
		if waitErr != nil {
			if releaseErr := workflow.ExecuteActivity(activityCtx, "RequestReservationRelease", ReservationSagaCommand{
				TenantID: input.TenantID, OrderID: input.OrderID, ReservationID: reservationID,
				CorrelationID: input.CorrelationID,
			}).Get(ctx, nil); releaseErr != nil {
				return temporal.NewApplicationError("payment/contract prerequisite timed out and reservation compensation failed", "SagaCompensationRequired", releaseErr)
			}
			return waitErr
		}
		switch event.EventType {
		case "OrderPaymentCaptured":
			paymentCaptured = true
		case "contract.signed.v1":
			contractSigned = true
		default:
			if event.EventType == "OrderCancelled" || event.EventType == "OrderPaymentFailed" || event.EventType == "OrderPaymentRefunded" || event.EventType == "OrderPaymentCancelled" {
				if err := workflow.ExecuteActivity(activityCtx, "RequestReservationRelease", ReservationSagaCommand{
					TenantID: input.TenantID, OrderID: input.OrderID, ReservationID: reservationID,
					CorrelationID: input.CorrelationID,
				}).Get(ctx, nil); err != nil {
					return err
				}
				logger.Info("Saga compensated vehicle reservation", "orderId", input.OrderID, "reason", event.EventType)
				return nil
			}
			logger.Warn("Reservation ended before prerequisites were complete", "orderId", input.OrderID, "event", event.EventType)
			return nil
		}
	}

	if err := workflow.ExecuteActivity(activityCtx, "RequestReservationConfirm", ReservationSagaCommand{
		TenantID: input.TenantID, OrderID: input.OrderID, ReservationID: reservationID,
		CorrelationID: input.CorrelationID,
	}).Get(ctx, nil); err != nil {
		if releaseErr := workflow.ExecuteActivity(activityCtx, "RequestReservationRelease", ReservationSagaCommand{
			TenantID: input.TenantID, OrderID: input.OrderID, ReservationID: reservationID,
			CorrelationID: input.CorrelationID,
		}).Get(ctx, nil); releaseErr != nil {
			return temporal.NewApplicationError("reservation confirmation failed and compensation also failed", "SagaCompensationRequired", releaseErr)
		}
		return err
	}

	confirmed, err := waitForSagaEvent(ctx, map[string]bool{
		"reservation.confirmed.v1": true,
		"reservation.expired.v1": true,
		"reservation.released.v1": true,
	}, 10*time.Minute, seen)
	if err != nil {
		if releaseErr := workflow.ExecuteActivity(activityCtx, "RequestReservationRelease", ReservationSagaCommand{
			TenantID: input.TenantID, OrderID: input.OrderID, ReservationID: reservationID,
			CorrelationID: input.CorrelationID,
		}).Get(ctx, nil); releaseErr != nil {
			return temporal.NewApplicationError("reservation confirmation timed out and compensation failed", "SagaCompensationRequired", releaseErr)
		}
		return err
	}
	if confirmed.EventType != "reservation.confirmed.v1" {
		return temporal.NewApplicationError("reservation did not reach confirmed state", "ReservationNotConfirmed")
	}

	ready, err := waitForSagaEvent(ctx, map[string]bool{
		"fulfillment.ready_for_preparation.v1": true,
	}, 24*time.Hour, seen)
	if err != nil {
		return err
	}
	if ready.EventType != "fulfillment.ready_for_preparation.v1" {
		return temporal.NewApplicationError("fulfillment did not become ready", "FulfillmentNotReady")
	}

	logger.Info("Order fulfillment Saga completed", "orderId", input.OrderID, "reservationId", reservationID)
	return nil
}

func waitForSagaEvent(ctx workflow.Context, accepted map[string]bool, timeout time.Duration, seen map[string]struct{}) (Event, error) {
	signals := workflow.GetSignalChannel(ctx, SagaSignal)
	var timer workflow.Future
	if timeout > 0 {
		timer = workflow.NewTimer(ctx, timeout)
	}
	for {
		var event Event
		if timeout <= 0 {
			signals.Receive(ctx, &event)
		} else {
			received := false
			timedOut := false
			selector := workflow.NewSelector(ctx)
			selector.AddReceive(signals, func(channel workflow.ReceiveChannel, more bool) {
				channel.Receive(ctx, &event)
				received = true
			})
			selector.AddFuture(timer, func(workflow.Future) { timedOut = true })
			selector.Select(ctx)
			if timedOut && !received {
				return Event{}, temporal.NewApplicationError("timed out waiting for Saga event", "SagaEventTimeout")
			}
		}
		if event.EventID != "" {
			if _, duplicate := seen[event.EventID]; duplicate {
				continue
			}
			seen[event.EventID] = struct{}{}
		}
		if accepted[event.EventType] {
			return event, nil
		}
	}
}

func eventString(data map[string]any, key string) string {
	value, ok := data[key].(string)
	if !ok {
		return ""
	}
	return value
}
