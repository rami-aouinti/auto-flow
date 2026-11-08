package orders

import (
	"context"
	"testing"
	"time"

	"go.temporal.io/sdk/activity"
	"go.temporal.io/sdk/testsuite"
)

func TestOrderFulfillmentSagaWorkflowHappyPath(t *testing.T) {
	var suite testsuite.WorkflowTestSuite
	env := suite.NewTestWorkflowEnvironment()
	var holds, confirms, releases int
	env.RegisterActivityWithOptions(func(_ context.Context, _ ReservationSagaCommand) error {
		holds++
		return nil
	}, activity.RegisterOptions{Name: "RequestReservationHold"})
	env.RegisterActivityWithOptions(func(_ context.Context, _ ReservationSagaCommand) error {
		confirms++
		return nil
	}, activity.RegisterOptions{Name: "RequestReservationConfirm"})
	env.RegisterActivityWithOptions(func(_ context.Context, _ ReservationSagaCommand) error {
		releases++
		return nil
	}, activity.RegisterOptions{Name: "RequestReservationRelease"})

	signal := func(eventType, eventID string, data map[string]any) {
		env.SignalWorkflow(SagaSignal, Event{EventID: eventID, EventType: eventType, Data: data})
	}
	env.RegisterDelayedCallback(func() { signal("OrderAccepted", "evt-accepted", nil) }, time.Second)
	env.RegisterDelayedCallback(func() {
		signal("reservation.held.v1", "evt-held", map[string]any{"id": "reservation-1"})
	}, 2*time.Second)
	env.RegisterDelayedCallback(func() { signal("contract.signed.v1", "evt-contract", nil) }, 3*time.Second)
	env.RegisterDelayedCallback(func() { signal("OrderPaymentCaptured", "evt-payment", nil) }, 4*time.Second)
	env.RegisterDelayedCallback(func() { signal("reservation.confirmed.v1", "evt-confirmed", nil) }, 5*time.Second)
	env.RegisterDelayedCallback(func() {
		signal("fulfillment.ready_for_preparation.v1", "evt-ready", nil)
	}, 6*time.Second)

	env.ExecuteWorkflow(OrderFulfillmentSagaWorkflow, SagaWorkflowInput{
		TenantID: "tenant-demo", OrderID: "order-1",
		VehicleVariantID: "variant-1", CorrelationID: "corr-1",
	})
	if err := env.GetWorkflowError(); err != nil {
		t.Fatalf("Saga workflow returned an error: %v", err)
	}
	if holds != 1 || confirms != 1 || releases != 0 {
		t.Fatalf("unexpected activity counts: hold=%d confirm=%d release=%d", holds, confirms, releases)
	}
}

func TestOrderFulfillmentSagaCompensatesWhenPaymentFails(t *testing.T) {
	var suite testsuite.WorkflowTestSuite
	env := suite.NewTestWorkflowEnvironment()
	var releases int
	env.RegisterActivityWithOptions(func(_ context.Context, _ ReservationSagaCommand) error {
		return nil
	}, activity.RegisterOptions{Name: "RequestReservationHold"})
	env.RegisterActivityWithOptions(func(_ context.Context, _ ReservationSagaCommand) error {
		releases++
		return nil
	}, activity.RegisterOptions{Name: "RequestReservationRelease"})

	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(SagaSignal, Event{EventID: "evt-accepted", EventType: "OrderAccepted"})
	}, time.Second)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(SagaSignal, Event{
			EventID: "evt-held", EventType: "reservation.held.v1",
			Data: map[string]any{"id": "reservation-1"},
		})
	}, 2*time.Second)
	env.RegisterDelayedCallback(func() {
		env.SignalWorkflow(SagaSignal, Event{EventID: "evt-payment-failed", EventType: "OrderPaymentFailed"})
	}, 3*time.Second)

	env.ExecuteWorkflow(OrderFulfillmentSagaWorkflow, SagaWorkflowInput{
		TenantID: "tenant-demo", OrderID: "order-2",
		VehicleVariantID: "variant-1",
	})
	if err := env.GetWorkflowError(); err != nil {
		t.Fatalf("Saga workflow returned an error: %v", err)
	}
	if releases != 1 {
		t.Fatalf("expected one reservation compensation, got %d", releases)
	}
}
