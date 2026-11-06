<?php

declare(strict_types=1);

namespace App\\Presentation\\Http\\Controller;

use App\\Application\\IdempotencyConflictException;
use App\\Application\\OrderApplicationService;
use App\\Application\\OrderNotFoundException;
use App\\Domain\\OrderStatus;
use App\\Infrastructure\\Observability\\OrderMetrics;
use AutoFlow\\CoreBundle\\Security\\AuthenticatedPrincipal;
use DomainException;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException;
use Symfony\\Component\\Routing\\Attribute\\Route;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
#[Route(
    '/api/v1/orders',
    requirements: ['orderId' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
)]
final readonly class OrderController
{
    public function __construct(private OrderApplicationService $orders, private OrderMetrics $metrics)
    {
    }

    #[Route('', name: 'orders_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'orders:write');
            $key = $request->headers->get('Idempotency-Key', '');
            if (!is_string($key) || $key === '') {
                return $this->problem(400, 'Idempotency-Key is required.');
            }
            $result = $this->orders->create(
                $this->tenant($principal),
                $principal->subject,
                $this->jsonBody($request),
                $key,
            );
            $this->metrics->recordCreated();
            return new JsonResponse(
                $result,
                JsonResponse::HTTP_CREATED,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (IdempotencyConflictException $exception) {
            return $this->problem(409, $exception->getMessage());
        } catch (DomainException $exception) {
            return $this->problem(422, $exception->getMessage());
        } catch (\\JsonException|\\InvalidArgumentException) {
            return $this->problem(400, 'A valid JSON object is required.');
        } catch (Throwable) {
            return $this->problem(500, 'The order could not be created.');
        }
    }

    #[Route('', name: 'orders_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'orders:read');
            $limit = max(1, min(100, $request->query->getInt('limit', 20)));
            $offset = max(0, $request->query->getInt('offset', 0));
            return new JsonResponse(
                $this->orders->list($this->tenant($principal), $limit, $offset),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (Throwable) {
            return $this->problem(500, 'Orders could not be listed.');
        }
    }

    #[Route('/{orderId}', name: 'orders_get', methods: ['GET'])]
    public function get(Request $request, string $orderId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'orders:read');
            return new JsonResponse(
                $this->orders->get($this->tenant($principal), $orderId),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (OrderNotFoundException) {
            return $this->problem(404, 'Order not found.');
        } catch (Throwable) {
            return $this->problem(500, 'Order could not be retrieved.');
        }
    }

    #[Route('/{orderId}/history', name: 'orders_history', methods: ['GET'])]
    public function history(Request $request, string $orderId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'orders:read');
            return new JsonResponse(
                ['items' => $this->orders->history($this->tenant($principal), $orderId)],
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (OrderNotFoundException) {
            return $this->problem(404, 'Order not found.');
        } catch (Throwable) {
            return $this->problem(500, 'Order history could not be retrieved.');
        }
    }

    #[Route('/{orderId}/submit', name: 'orders_submit', methods: ['POST'])]
    public function submit(Request $request, string $orderId): JsonResponse
    {
        return $this->change($request, $orderId, OrderStatus::SUBMITTED);
    }

    #[Route('/{orderId}/review', name: 'orders_review', methods: ['POST'])]
    public function review(Request $request, string $orderId): JsonResponse
    {
        return $this->change($request, $orderId, OrderStatus::UNDER_REVIEW);
    }

    #[Route('/{orderId}/accept', name: 'orders_accept', methods: ['POST'])]
    public function accept(Request $request, string $orderId): JsonResponse
    {
        return $this->change($request, $orderId, OrderStatus::ACCEPTED);
    }

    #[Route('/{orderId}/reject', name: 'orders_reject', methods: ['POST'])]
    public function reject(Request $request, string $orderId): JsonResponse
    {
        try {
            $body = $this->jsonBody($request);
        } catch (Throwable) {
            return $this->problem(400, 'A valid JSON object is required.');
        }
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;
        return $this->change($request, $orderId, OrderStatus::REJECTED, $reason);
    }

    #[Route('/{orderId}/cancel', name: 'orders_cancel', methods: ['POST'])]
    public function cancel(Request $request, string $orderId): JsonResponse
    {
        return $this->change($request, $orderId, OrderStatus::CANCELLED);
    }

    #[Route('/{orderId}/contract-pending', name: 'orders_contract_pending', methods: ['POST'])]
    public function contractPending(Request $request, string $orderId): JsonResponse
    {
        return $this->change($request, $orderId, OrderStatus::CONTRACT_PENDING);
    }

    #[Route('/{orderId}/complete', name: 'orders_complete', methods: ['POST'])]
    public function complete(Request $request, string $orderId): JsonResponse
    {
        return $this->change($request, $orderId, OrderStatus::COMPLETED);
    }

    private function change(
        Request $request,
        string $orderId,
        OrderStatus $target,
        ?string $reason = null,
    ): JsonResponse
    {
        try {
            $managementStatuses = [
                OrderStatus::ACCEPTED,
                OrderStatus::REJECTED,
                OrderStatus::UNDER_REVIEW,
                OrderStatus::CONTRACT_PENDING,
                OrderStatus::COMPLETED,
            ];
            $scope = in_array($target, $managementStatuses, true)
                ? 'orders:manage'
                : 'orders:write';
            $principal = $this->principal($request, $scope);
            $result = $this->orders->transition(
                $this->tenant($principal),
                $principal->subject,
                $orderId,
                $target,
                $reason,
            );
            $this->metrics->recordTransition($target->value);
            return new JsonResponse(
                $result,
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (OrderNotFoundException) {
            return $this->problem(404, 'Order not found.');
        } catch (DomainException $exception) {
            return $this->problem(409, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(500, 'Order state could not be changed.');
        }
    }

    private function principal(Request $request, string $scope): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope($scope)) {
            throw new AccessDeniedHttpException();
        }
        return $principal;
    }

    private function tenant(AuthenticatedPrincipal $principal): string
    {
        if ($principal->tenantId === null || $principal->tenantId === '') {
            throw new AccessDeniedHttpException();
        }
        return $principal->tenantId;
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \\InvalidArgumentException('A JSON object is required.');
        }
        return $data;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        $title = match ($status) {
            400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found',
            409 => 'Conflict', 422 => 'Unprocessable Entity', default => 'Internal Server Error',
        };
        return new JsonResponse([
            'type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
