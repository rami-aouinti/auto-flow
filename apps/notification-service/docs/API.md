# Notification Service — API Reference

**Base URL:** No public HTTP base URL (background worker only)  
**Authentication:** RabbitMQ worker; no public HTTP API

## HTTP surface

This service is a background RabbitMQ notification worker and does not expose public REST endpoints. It consumes the `offer.published` event (schema version 2), resolves the offer owner's email, renders the notification and sends via SMTP. Mailpit is available at http://localhost:8025. Prometheus metrics are internal to the Docker network.

## Contract maintenance

This reference documents the service-owned API surface currently described by the service README. If an argument or response schema is not explicitly described in the source documentation, it is not invented here; update this file and the OpenAPI catalog together when changing controllers.
