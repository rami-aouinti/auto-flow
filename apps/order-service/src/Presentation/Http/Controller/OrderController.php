<?php

declare(strict_types=1);

namespace App\\Presentation\\Http\\Controller;

use App\\Application\\OrderApplicationService;
use App\\Application\\OrderNotFoundException;
use App\\Domain\\OrderStatus;
use AutoFlow\\CoreBundle\\Security\\AuthenticatedPrincipal;
use DomainException;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException;
use Symfony\\Component\\Routing\\Attribute\\Route;
use Throwable;

#[Route('/api/v1/orders')]
final readonly class OrderController
{
    public function __construct(private OrderApplicationService $orders)
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
            return new JsonResponse(
                $this->orders->create($this->tenant($principal), $principal->subject, $this->jsonBody($request), $key),
                JsonResponse::HTTP_CREATED,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
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
            return new JsonResponse(
                $this->orders->list($this->tenant($principal), max(1, min(100, $request->query->getInt('limit', 20))), max(0, $request->query->getInt('offset', 0))),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (Throwable) {
            return $this->problem(500, 'Orders could not be listed.');
        }
    }

    #[Route('/{orderId}', name: 'orders_get', requirements: ['orderId' => '[0-9a-fA-F-]{32,36}'], methods: ['GET'])]
    public function get(Request $request, string $orderId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'orders:read');
            return new JsonResponse($this->orders->get($this->tenant($principal), $orderId), 200, ['Cache-Control' => 'no-store']);
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'The required permission and tenant context are required.');
        } catch (OrderNotFoundException) {
            return $this->problem(404, 'Order not found.');
        } catch (Throwable) {
            return $this->problem(500, 'Order could not be retrieved.');
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
        return $this->change($request, $orderId, OrderStatus::REJECTED, isset($body['reason']) ? (string) $body['reason'] : null);
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

    private function change(Request $request, string $orderId, OrderStatus $target, ?string $reason = null): JsonResponse
    {
        try {
            $scope = in_array($target, [OrderStatus::ACCEPTED, OrderStatus::REJECTED, OrderStatus::UNDER_REVIEW, OrderStatus::CONTRACT_PENDING, OrderStatus::COMPLETED], true)
                ? 'orders:manage' : 'orders:write';
            $principal = $this->principal($request, $scope);
            return new JsonResponse(
                $this->orders->transition($this->tenant($principal), $principal->subject, $orderId, $target, $reason),
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
