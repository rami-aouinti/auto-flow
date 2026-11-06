package orders

import (
	"time"

	"go.temporal.io/sdk/temporal"
	"go.temporal.io/sdk/workflow"
)

const LifecycleSignal = "order.lifecycle"

type WorkflowInput struct {
	TenantID      string
	OrderID       string
	CorrelationID string
}

type SLAAlert struct {
	TenantID      string
	OrderID       string
	CorrelationID string
	Phase         string
	After         string
}

func OrderLifecycleWorkflow(ctx workflow.Context, input WorkflowInput) error {
	logger := workflow.GetLogger(ctx)
	logger.Info("Order lifecycle workflow started", "tenantId", input.TenantID, "orderId", input.OrderID, "correlationId", input.CorrelationID)

	activityCtx := workflow.WithActivityOptions(ctx, workflow.ActivityOptions{
		StartToCloseTimeout: 15 * time.Second,
		RetryPolicy: &temporal.RetryPolicy{
			InitialInterval: time.Second,
			BackoffCoefficient: 2,
			MaximumInterval: time.Minute,
			MaximumAttempts: 0,
		},
	})
	// Temporal replays this deterministic state from workflow history. Keeping event IDs
	// here makes RabbitMQ redelivery safe across every lifecycle phase.
	seenEventIDs := make(map[string]struct{})

	decision, err := waitForLifecycleEvent(ctx, map[string]bool{
		"OrderAccepted": true, "OrderRejected": true, "OrderCancelled": true,
	}, 48*time.Hour, seenEventIDs)
	if err != nil {
		return err
	}
	if decision == "" {
		if err := workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, CorrelationID: input.CorrelationID, Phase: "human_review", After: "48h",
		}).Get(ctx, nil); err != nil {
			return err
		}
		decision, err = waitForLifecycleEvent(ctx, map[string]bool{
			"OrderAccepted": true, "OrderRejected": true, "OrderCancelled": true,
		}, 0, seenEventIDs)
		if err != nil {
			return err
		}
	}
	if decision == "OrderRejected" || decision == "OrderCancelled" {
		logger.Info("Order lifecycle finished without contract", "orderId", input.OrderID, "decision", decision)
		return nil
	}

	// Payment authorization is the gate that advances an accepted order to contract preparation.
	contractState, err := waitForLifecycleEvent(ctx, map[string]bool{
		"OrderContractPending": true, "OrderPaymentAuthorized": true,
		"OrderPaymentFailed": true, "OrderPaymentRefunded": true, "OrderCancelled": true,
	}, 24*time.Hour, seenEventIDs)
	if err != nil {
		return err
	}
	if contractState == "" {
		if err := workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, CorrelationID: input.CorrelationID,
			Phase: "payment_or_contract_preparation", After: "24h",
		}).Get(ctx, nil); err != nil {
			return err
		}
		contractState, err = waitForLifecycleEvent(ctx, map[string]bool{
			"OrderContractPending": true, "OrderPaymentAuthorized": true,
			"OrderPaymentFailed": true, "OrderPaymentRefunded": true, "OrderCancelled": true,
		}, 0, seenEventIDs)
		if err != nil {
			return err
		}
	}
	if contractState == "OrderCancelled" || contractState == "OrderPaymentRefunded" {
		return nil
	}
	if contractState == "OrderPaymentFailed" {
		if err := workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, CorrelationID: input.CorrelationID,
			Phase: "payment_failure", After: "immediate",
		}).Get(ctx, nil); err != nil {
			return err
		}
		recovery, err := waitForLifecycleEvent(ctx, map[string]bool{
			"OrderPaymentAuthorized": true, "OrderPaymentRefunded": true, "OrderCancelled": true,
		}, 24*time.Hour, seenEventIDs)
		if err != nil || recovery == "OrderPaymentRefunded" || recovery == "OrderCancelled" {
			return err
		}
	}

	// Capture confirms settlement but does not mean the vehicle/order has been fulfilled.
	var fulfilment string
	for {
		fulfilment, err = waitForLifecycleEvent(ctx, map[string]bool{
			"OrderCompleted": true, "OrderCancelled": true, "OrderPaymentCaptured": true,
			"OrderPaymentFailed": true, "OrderPaymentRefunded": true,
		}, 7*24*time.Hour, seenEventIDs)
		if err != nil {
			return err
		}
		if fulfilment == "OrderPaymentCaptured" {
			logger.Info("Payment captured; awaiting order fulfilment", "orderId", input.OrderID)
			continue
		}
		if fulfilment == "OrderPaymentFailed" {
			if err := workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
				TenantID: input.TenantID, OrderID: input.OrderID, CorrelationID: input.CorrelationID,
				Phase: "payment_failure", After: "immediate",
			}).Get(ctx, nil); err != nil {
				return err
			}
			continue
		}
		break
	}
	if fulfilment == "OrderPaymentRefunded" || fulfilment == "OrderCancelled" {
		return nil
	}
	if fulfilment == "" {
		return workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, CorrelationID: input.CorrelationID, Phase: "contract_fulfilment", After: "168h",
		}).Get(ctx, nil)
	}
	logger.Info("Order lifecycle workflow finished", "orderId", input.OrderID, "finalEvent", fulfilment)
	return nil
}

func waitForLifecycleEvent(
	ctx workflow.Context,
	accepted map[string]bool,
	timeout time.Duration,
	seenEventIDs map[string]struct{},
) (string, error) {
	signals := workflow.GetSignalChannel(ctx, LifecycleSignal)
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
			selector.AddFuture(timer, func(workflow.Future) {
				timedOut = true
			})
			selector.Select(ctx)
			if timedOut && !received {
				return "", nil
			}
		}

		if event.EventID != "" {
			if _, duplicate := seenEventIDs[event.EventID]; duplicate {
				continue
			}
			seenEventIDs[event.EventID] = struct{}{}
		}
		if accepted[event.EventType] {
			return event.EventType, nil
		}
	}
}
