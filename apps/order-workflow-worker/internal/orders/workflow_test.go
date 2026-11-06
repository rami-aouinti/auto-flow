package orders

import (
	"context"
	"testing"
	"time"

	"go.temporal.io/sdk/activity"
	"go.temporal.io/sdk/testsuite"
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
