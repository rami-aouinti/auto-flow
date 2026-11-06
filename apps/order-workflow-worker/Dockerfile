FROM golang:1.23-alpine AS build
WORKDIR /src
COPY apps/order-workflow-worker/go.mod apps/order-workflow-worker/go.sum* ./
RUN go mod download
COPY apps/order-workflow-worker/ ./
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/order-workflow-worker ./cmd/worker

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/order-workflow-worker /usr/local/bin/order-workflow-worker
USER nonroot:nonroot
ENTRYPOINT ["/usr/local/bin/order-workflow-worker"]
