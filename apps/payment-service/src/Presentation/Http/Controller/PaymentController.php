<?php

declare(strict_types=1);

namespace App\\Presentation\\Http\\Controller;

use App\\Application\\PaymentApplicationService;
use App\\Application\\PaymentNotFoundException;
use AutoFlow\\CoreBundle\\Security\\AuthenticatedPrincipal;
use DomainException;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\Routing\\Attribute\\Route;
use Throwable;

#[Route('/api/v1/payments')]
final readonly class PaymentController
{
    public function __construct(private PaymentApplicationService $payments)
    {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'payments:write');
            $key = $request->headers->get('Idempotency-Key', '');
            $result = $this->payments->create($this->tenant($principal), $principal->subject, $key, $this->body($request));
            return new JsonResponse($result, 201, ['Cache-Control'=>'no-store']);
        } catch (DomainException $e) {
            return $this->problem(422, $e->getMessage());
        } catch (\\Throwable) {
            return $this->problem(503, 'Payment could not be created. Check provider status before retrying.');
        }
    }

    #[Route('/{paymentId}', methods: ['GET'], requirements: ['paymentId'=>'[0-9a-fA-F-]{36}'])]
    public function get(Request $request, string $paymentId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'payments:read');
            return new JsonResponse($this->payments->get($this->tenant($principal), $paymentId), 200, ['Cache-Control'=>'no-store']);
        } catch (PaymentNotFoundException) {
            return $this->problem(404, 'Payment not found.');
        } catch (\\Throwable) {
            return $this->problem(403, 'Payment access denied.');
        }
    }

    #[Route('/{paymentId}/capture', methods: ['POST'], requirements: ['paymentId'=>'[0-9a-fA-F-]{36}'])]
    public function capture(Request $request, string $paymentId): JsonResponse
    {
        return $this->transition($request, $paymentId, 'payments:capture', 'capture');
    }

    #[Route('/{paymentId}/cancel', methods: ['POST'], requirements: ['paymentId'=>'[0-9a-fA-F-]{36}'])]
    public function cancel(Request $request, string $paymentId): JsonResponse
    {
        return $this->transition($request, $paymentId, 'payments:write', 'cancel');
    }

    #[Route('/{paymentId}/refund', methods: ['POST'], requirements: ['paymentId'=>'[0-9a-fA-F-]{36}'])]
    public function refund(Request $request, string $paymentId): JsonResponse
    {
        try {
            $body = $this->body($request);
            $amount = $body['amountMinor'] ?? null;
            if (!is_int($amount)) {
                return $this->problem(400, 'amountMinor must be an integer.');
            }
            return $this->transition($request, $paymentId, 'payments:refund', 'refund', $amount);
        } catch (Throwable) {
            return $this->problem(400, 'A valid JSON object is required.');
        }
    }

    private function transition(Request $request, string $paymentId, string $scope, string $operation, ?int $amount = null): JsonResponse
    {
        try {
            $principal = $this->principal($request, $scope);
            $key = $request->headers->get('Idempotency-Key', '');
            $result = $this->payments->transition($this->tenant($principal), $principal->subject, $paymentId, $key, $operation, $amount);
            return new JsonResponse($result, 200, ['Cache-Control'=>'no-store']);
        } catch (PaymentNotFoundException) {
            return $this->problem(404, 'Payment not found.');
        } catch (DomainException $e) {
            return $this->problem(409, $e->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Payment provider operation failed.');
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new DomainException('A JSON object is required.');
        }
        return $data;
    }

    private function principal(Request $request, string $scope): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope($scope)) {
            throw new DomainException('Permission denied.');
        }
        return $principal;
    }

    private function tenant(AuthenticatedPrincipal $principal): string
    {
        if ($principal->tenantId === null) {
            throw new DomainException('Tenant context is required.');
        }
        return $principal->tenantId;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['type'=>'about:blank','title'=>match($status){400=>'Bad Request',403=>'Forbidden',404=>'Not Found',409=>'Conflict',422=>'Unprocessable Entity',default=>'Service Unavailable'},'status'=>$status,'detail'=>$detail], $status, ['Content-Type'=>'application/problem+json','Cache-Control'=>'no-store']);
    }
}
