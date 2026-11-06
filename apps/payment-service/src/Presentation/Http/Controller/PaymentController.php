<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\PaymentApplicationService;
use App\Application\PaymentNotFoundException;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use DomainException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
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
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'Payment permission or tenant context is missing.');
        } catch (DomainException $e) {
            return $this->problem(422, $e->getMessage());
        } catch (\Throwable) {
            return $this->problem(503, 'Payment could not be created. Check provider status before retrying.');
        }
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, Connection $db): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'payments:read');
            $tenant = $this->tenant($principal);
            $limit = filter_var($request->query->get('limit', '20'), FILTER_VALIDATE_INT);
            $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);
            if (!is_int($limit) || $limit < 1 || $limit > 100 || !is_int($offset) || $offset < 0) {
                return $this->problem(400, 'limit must be 1..100 and offset must be >= 0.');
            }

            $where = ['tenant_id = :tenant'];
            $params = ['tenant' => $tenant];
            $orderId = $request->query->get('orderId');
            if (is_string($orderId) && $orderId !== '') {
                if (!\Symfony\Component\Uid\Uuid::isValid($orderId)) {
                    return $this->problem(400, 'orderId must be a valid UUID.');
                }
                $where[] = 'order_id = :orderId';
                $params['orderId'] = $orderId;
            }
            $status = $request->query->get('status');
            if (is_string($status) && $status !== '') {
                if (!in_array($status, ['pending','requires_action','authorized','captured','failed','cancelled','partially_refunded','refunded'], true)) {
                    return $this->problem(400, 'Unsupported payment status filter.');
                }
                $where[] = 'status = :status';
                $params['status'] = $status;
            }
            $predicate = implode(' AND ', $where);
            $db->beginTransaction();
            try {
                $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
                $total = (int) $db->fetchOne('SELECT COUNT(*) FROM payments WHERE ' . $predicate, $params);
                $rows = $db->fetchAllAssociative(
                    'SELECT payment_id, order_id, provider, amount_minor, amount_refunded_minor, currency, status, created_at, updated_at
                     FROM payments WHERE ' . $predicate . ' ORDER BY created_at DESC, payment_id DESC LIMIT :limit OFFSET :offset',
                    $params + ['limit' => $limit, 'offset' => $offset],
                    ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
                );
                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->isTransactionActive()) $db->rollBack();
                throw $exception;
            }

            $items = array_map(static fn (array $row): array => [
                'paymentId' => (string) $row['payment_id'],
                'orderId' => (string) $row['order_id'],
                'provider' => (string) $row['provider'],
                'amountMinor' => (int) $row['amount_minor'],
                'amountRefundedMinor' => (int) $row['amount_refunded_minor'],
                'currency' => (string) $row['currency'],
                'status' => (string) $row['status'],
                'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
                'updatedAt' => (new \DateTimeImmutable((string) $row['updated_at']))->format(DATE_ATOM),
            ], $rows);

            return new JsonResponse(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset], 200, ['Cache-Control'=>'no-store']);
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'Payment permission or tenant context is missing.');
        } catch (\Throwable) {
            return $this->problem(500, 'Payment history could not be loaded.');
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
        } catch (\Throwable) {
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
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'Payment permission or tenant context is missing.');
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
            throw new AccessDeniedHttpException('Permission denied.');
        }
        return $principal;
    }

    private function tenant(AuthenticatedPrincipal $principal): string
    {
        if ($principal->tenantId === null) {
            throw new AccessDeniedHttpException('Tenant context is required.');
        }
        return $principal->tenantId;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['type'=>'about:blank','title'=>match($status){400=>'Bad Request',403=>'Forbidden',404=>'Not Found',409=>'Conflict',422=>'Unprocessable Entity',default=>'Service Unavailable'},'status'=>$status,'detail'=>$detail], $status, ['Content-Type'=>'application/problem+json','Cache-Control'=>'no-store']);
    }
}
