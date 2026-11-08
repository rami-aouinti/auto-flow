package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"syscall"

	"autoflow/order-workflow-worker/internal/orders"
	"go.temporal.io/sdk/client"
	"go.temporal.io/sdk/worker"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	temporalClient, err := client.Dial(client.Options{
		HostPort:  env("TEMPORAL_ADDRESS", "temporal:7233"),
		Namespace: env("TEMPORAL_NAMESPACE", "default"),
	})
	if err != nil {
		log.Fatalf("connect to Temporal: %v", err)
	}
	defer temporalClient.Close()

	taskQueue := env("TEMPORAL_TASK_QUEUE", "autoflow-order-workflows")
	temporalWorker := worker.New(temporalClient, taskQueue, worker.Options{})
	temporalWorker.RegisterWorkflow(orders.OrderLifecycleWorkflow)
	temporalWorker.RegisterWorkflow(orders.OrderFulfillmentSagaWorkflow)
	temporalWorker.RegisterActivity(&orders.Activities{})
	if err := temporalWorker.Start(); err != nil {
		log.Fatalf("start Temporal worker: %v", err)
	}
	defer temporalWorker.Stop()

	log.Printf("Order workflow worker started; taskQueue=%s", taskQueue)
	go orders.ConsumeOrderEvents(ctx, temporalClient)
	go orders.ConsumeSagaEvents(ctx, temporalClient)
	<-ctx.Done()
	log.Print("Order workflow worker stopping")
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
