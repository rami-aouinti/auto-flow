# 08 — Architecture diagrams

This chapter uses diagrams to make boundaries and runtime behaviour easier to discuss. A diagram is a model, not proof that every box is implemented. Each section labels the current demonstration or the target design.

## 1. System context — C4 Level 1

The system context shows AutoFlow and the actors or external systems around it.

~~~mermaid
flowchart LR
    Customer[Customer]
    Operations[Operations team]
    AutoFlow[AutoFlow Mobility Platform]
    Partners[External partner systems]
    Email[Email provider]
    Customer -->|Browse and submit leasing request| AutoFlow
    Operations -->|Review operational cases| AutoFlow
    AutoFlow -->|Partner API calls| Partners
    AutoFlow -->|Transactional notifications| Email
~~~

This is a conceptual target context. The current repository does not implement the complete customer journey, operations portal or partner integrations.

## 2. Container view — C4 Level 2

~~~mermaid
flowchart TB
    Browser[Customer browser / client]
    subgraph Platform[AutoFlow platform]
      Offer[Offer Service\nSymfony application]
      Pricing[Pricing Service\nSymfony application]
      Catalog[Vehicle Catalog Service\nSymfony application]
      Broker[RabbitMQ]
      DB[(PostgreSQL)]
      CatalogDB[(Catalogue-owned PostgreSQL tables)]
      Cache[(Redis)]
      Search[(Elasticsearch)]
      Hub[Mercure Hub]
      Temporal[Temporal Server\nTarget]
    end
    Browser -->|HTTP| Offer
    Offer -->|Synchronous HTTP| Pricing
    Catalog -->|Owns catalogue records| CatalogDB
    Catalog -.->|Transactional Outbox events| Broker
    Offer -.->|Future async commands/events| Broker
    Pricing -.->|Future persistence| DB
    Offer -.->|Future cache use| Cache
    Browser -.->|Future live updates| Hub
    Broker -.->|Future workflow signal / activity trigger| Temporal
    Offer -.->|Future search read model| Search
~~~

**Important:** solid arrows represent the implemented Offer → Pricing HTTP path and client-to-Offer entry point. Dashed arrows are target integration possibilities, not current business flows. PostgreSQL, Redis, Elasticsearch, RabbitMQ, Mercure and Temporal are not all used by the two demo controllers.

## 3. Current deployment view

~~~mermaid
flowchart TB
    subgraph Host[Developer workstation]
      Client[curl / browser]
      subgraph Compose[Docker Compose network]
        Offer[offer-service\ncontainer :8080]
        Pricing[pricing-service\ncontainer :8080]
        Tempo[Tempo\nOTLP HTTP :4318]
        Grafana[Grafana\n:3000]
        Prom[Prometheus\n:9090]
        Loki[Loki\n:3100]
        Alloy[Grafana Alloy]
        Exporters[Infrastructure exporters]
      end
    end
    Client -->|localhost:8081| Offer
    Client -->|localhost:8082| Pricing
    Offer -->|pricing-service:8080| Pricing
    Offer -->|OTLP| Tempo
    Pricing -->|OTLP| Tempo
    Grafana --> Tempo
    Grafana --> Prom
    Grafana --> Loki
    Alloy -->|container logs| Loki
    Exporters -->|scrape targets| Prom
~~~

The host port and container port are different: the Offer host port 8081 maps to container port 8080; Pricing host port 8082 maps to container port 8080. Service-to-service traffic uses Docker DNS and the container port.

## 4. Sequence — GET an offer

~~~mermaid
sequenceDiagram
    autonumber
    actor Client
    participant O as Offer Service
    participant P as Pricing Service
    participant T as Tempo

    Client->>O: GET /api/offers/{vehicleId}
    Note over O: Start span offer.compose
    O->>P: GET /api/pricing/{vehicleId}<br/>traceparent propagated
    Note over P: Start span pricing.calculate
    P-->>O: JSON pricing result
    Note over P: End pricing.calculate
    O-->>Client: Composed offer JSON
    Note over O: End offer.compose
    O-->>T: OTLP trace export (asynchronous SDK export)
    P-->>T: OTLP trace export (asynchronous SDK export)
~~~

This represents the current demo request path. The exact order and batching of OTLP export depend on SDK/exporter behaviour; the diagram emphasizes that spans are exported to Tempo rather than returned in the HTTP response.

## 5. Sequence — target order submission and orchestration

~~~mermaid
sequenceDiagram
    autonumber
    actor Customer
    participant API as Order API
    participant DB as Order DB
    participant Relay as Outbox Publisher
    participant MQ as RabbitMQ
    participant WF as Workflow Engine
    participant Partner as External Partner
    participant Notify as Notification capability

    Customer->>API: Submit order (idempotency key)
    API->>DB: Transaction: persist order + outbox event
    DB-->>API: Commit
    API-->>Customer: Accepted / order reference
    Relay->>DB: Read unpublished outbox records
    Relay->>MQ: Publish OrderSubmitted (eventId)
    MQ-->>WF: Consume / start or signal workflow
    WF->>Partner: Activity: request validation
    Partner-->>WF: Result or transient failure
    WF->>WF: Retry / wait / apply business transition
    WF->>Notify: Activity or integration command
    Notify-->>WF: Delivery outcome
~~~

This is a proposed target workflow, not an implemented AutoFlow feature. A real design must specify event ownership, workflow identity, retry policy, timeout, compensation, idempotency and customer-visible state transitions.

## 6. Sequence — observability context propagation

~~~mermaid
sequenceDiagram
    participant Client
    participant Offer
    participant Pricing
    participant Tempo
    Client->>Offer: HTTP request
    Offer->>Offer: Create offer.compose span
    Offer->>Pricing: HTTP request + traceparent
    Pricing->>Pricing: Continue trace, create pricing.calculate span
    Pricing-->>Offer: HTTP response
    Offer-->>Client: Offer response
    Offer->>Tempo: OTLP export
    Pricing->>Tempo: OTLP export
~~~

Trace context connects spans into one distributed trace. A correlation ID alone can connect log records but does not provide span timing, parent/child relationships or dependency latency.

## 7. Diagram maintenance rules

- Label every diagram as current, target or conceptual.
- Use actual service names and ports from Compose.
- Do not draw direct database access between services.
- Draw an event only when its producer, owner and consumer are known.
- Update diagrams in the same pull request as material architecture changes.
- Prefer a small number of accurate diagrams over a large diagram that mixes all concerns.