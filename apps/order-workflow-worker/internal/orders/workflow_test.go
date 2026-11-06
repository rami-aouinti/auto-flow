package orders

import (
	"context"
	"fmt"
	"testing"
	"time"

	"go.temporal.io/sdk/activity"
	"go.temporal.io/sdk/testsuite"
	"go.temporal.io/sdk/workflow"
)

func TestOrderLifecycleWorkflowCompletesFromAuthoritativeEvents(t *testing.T) {
	var suite testsuite.WorkflowTestSuite
	env := suite.NewTestWorkflowEnvironment()
	env.RegisterActivity(&Activities{})

	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderAccepted"})
	}, time.Hour)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderContractPending"})
	}, 2*time.Hour)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderCompleted"})
	}, 3*time.Hour)

	env.ExecuteWorkflow(OrderLifecycleWorkflow, WorkflowInput{
		TenantID: "tenant-a", OrderID: "order-1", CorrelationID: "corr-1",
	})
	if !env.IsWorkflowCompleted() {
		t.Fatal("workflow did not complete")
	}
	if err := env.GetWorkflowError(); err != nil {
		t.Fatalf("workflow returned an error: %v", err)
	}
}

func TestOrderLifecycleWorkflowPublishesReviewSLAAfter48Hours(t *testing.T) {
	var suite testsuite.WorkflowTestSuite
	env := suite.NewTestWorkflowEnvironment()
	var alerts []SLAAlert
	env.RegisterActivityWithOptions(func(_ context.Context, alert SLAAlert) error {
		alerts = append(alerts, alert)
		return nil
	}, activity.RegisterOptions{Name: "PublishSLAAlert"})

	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderAccepted"})
	}, 49*time.Hour)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderContractPending"})
	}, 50*time.Hour)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderCompleted"})
	}, 51*time.Hour)

	env.ExecuteWorkflow(OrderLifecycleWorkflow, WorkflowInput{
		TenantID: "tenant-a", OrderID: "order-2",
	})
	if err := env.GetWorkflowError(); err != nil {
		t.Fatalf("workflow returned an error: %v", err)
	}
	if len(alerts) != 1 {
		t.Fatalf("expected one review SLA alert, got %d", len(alerts))
	}
	if alerts[0].Phase != "human_review" || alerts[0].After != "48h" {
		t.Fatalf("unexpected SLA alert: %+v", alerts[0])
	}
}


func TestWaitForLifecycleEventDeduplicatesRabbitRedelivery(t *testing.T) {
	var suite testsuite.WorkflowTestSuite
	env := suite.NewTestWorkflowEnvironment()

	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventID: "evt-contract", EventType: "OrderContractPending"})
	}, time.Second)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventID: "evt-contract", EventType: "OrderContractPending"})
	}, 2*time.Second)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventID: "evt-accepted", EventType: "OrderAccepted"})
	}, 3*time.Second)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventID: "evt-contract-2", EventType: "OrderContractPending"})
	}, 4*time.Second)

	env.ExecuteWorkflow(func(ctx workflow.Context) error {
		seen := make(map[string]struct{})
		first, err := waitForLifecycleEvent(ctx, map[string]bool{"OrderAccepted": true}, 0, seen)
		if err != nil {
			return err
		}
		if first != "OrderAccepted" {
			return fmt.Errorf("expected OrderAccepted, got %q", first)
		}
		second, err := waitForLifecycleEvent(ctx, map[string]bool{"OrderContractPending": true}, 0, seen)
		if err != nil {
			return err
		}
		if second != "OrderContractPending" {
			return fmt.Errorf("expected OrderContractPending, got %q", second)
		}
		return nil
	})
	if err := env.GetWorkflowError(); err != nil {
		t.Fatalf("workflow returned an error: %v", err)
	}
}

func TestOrderLifecycleWorkflowContinuesAfterPaymentAuthorization(t *testing.T) {
	var suite testsuite.WorkflowTestSuite
	env := suite.NewTestWorkflowEnvironment()
	env.RegisterActivity(&Activities{})

	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderAccepted"})
	}, time.Minute)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderPaymentAuthorized"})
	}, 2*time.Minute)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderPaymentCaptured"})
	}, 3*time.Minute)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(LifecycleSignal, Event{EventType: "OrderCompleted"})
	}, 4*time.Minute)

	env.ExecuteWorkflow(OrderLifecycleWorkflow, WorkflowInput{TenantID: "tenant-a", OrderID: "order-payment"})
	if err := env.GetWorkflowError(); err != nil {
		t.Fatalf("workflow returned an error: %v", err)
	}
}
