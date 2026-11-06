package orders

import (
	"time"

	"go.temporal.io/sdk/temporal"\n\t"go.temporal.io/sdk/workflow"
)

const LifecycleSignal = "order.lifecycle"

type WorkflowInput struct {
	TenantID string
	OrderID  string
}

type SLAAlert struct {
	TenantID string
	OrderID  string
	Phase    string
	After    string
}

func OrderLifecycleWorkflow(ctx workflow.Context, input WorkflowInput) error {
	logger := workflow.GetLogger(ctx)
	logger.Info("Order lifecycle workflow started", "tenantId", input.TenantID, "orderId", input.OrderID)

	activityCtx := workflow.WithActivityOptions(ctx, workflow.ActivityOptions{
		StartToCloseTimeout: 15 * time.Second,
		RetryPolicy: &temporal.RetryPolicy{
			InitialInterval: time.Second,
			BackoffCoefficient: 2,
			MaximumInterval: time.Minute,
			MaximumAttempts: 8,
		},
	})

	decision, err := waitForLifecycleEvent(ctx, map[string]bool{
		"OrderAccepted": true, "OrderRejected": true, "OrderCancelled": true,
	}, 48*time.Hour)
	if err != nil {
		return err
	}
	if decision == "" {
		if err := workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, Phase: "human_review", After: "48h",
		}).Get(ctx, nil); err != nil {
			return err
		}
		decision, err = waitForLifecycleEvent(ctx, map[string]bool{
			"OrderAccepted": true, "OrderRejected": true, "OrderCancelled": true,
		}, 0)
		if err != nil {
			return err
		}
	}
	if decision == "OrderRejected" || decision == "OrderCancelled" {
		logger.Info("Order lifecycle finished without contract", "orderId", input.OrderID, "decision", decision)
		return nil
	}

	contractState, err := waitForLifecycleEvent(ctx, map[string]bool{
		"OrderContractPending": true, "OrderCancelled": true,
	}, 24*time.Hour)
	if err != nil {
		return err
	}
	if contractState == "" {
		if err := workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, Phase: "contract_preparation", After: "24h",
		}).Get(ctx, nil); err != nil {
			return err
		}
		contractState, err = waitForLifecycleEvent(ctx, map[string]bool{
			"OrderContractPending": true, "OrderCancelled": true,
		}, 0)
		if err != nil {
			return err
		}
	}
	if contractState == "OrderCancelled" {
		return nil
	}

	fulfilment, err := waitForLifecycleEvent(ctx, map[string]bool{
		"OrderCompleted": true, "OrderCancelled": true,
	}, 7*24*time.Hour)
	if err != nil {
		return err
	}
	if fulfilment == "" {
		return workflow.ExecuteActivity(activityCtx, "PublishSLAAlert", SLAAlert{
			TenantID: input.TenantID, OrderID: input.OrderID, Phase: "contract_fulfilment", After: "168h",
		}).Get(ctx, nil)
	}
	logger.Info("Order lifecycle workflow finished", "orderId", input.OrderID, "finalEvent", fulfilment)
	return nil
}

func waitForLifecycleEvent(ctx workflow.Context, accepted map[string]bool, timeout time.Duration) (string, error) {
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
		if accepted[event.EventType] {
			return event.EventType, nil
		}
	}
}
