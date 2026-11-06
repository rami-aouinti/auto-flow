<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/internal/orders')]
final readonly class InternalPaymentContextController
{
    public function __construct(
        private Connection $db,
        private string $paymentSharedSecret,
    ) {
    }

    #[Route('/{orderId}/payment-context', methods: ['GET'], requirements: ['orderId' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(Request $request, string $orderId): JsonResponse
    {
        $provided = $request->headers->get('X-Autoflow-Service-Token', '');
        if ($this->paymentSharedSecret === '' || !is_string($provided)
            || !hash_equals($this->paymentSharedSecret, $provided)) {
            return new JsonResponse(['detail' => 'Service authentication failed.'], 403);
        }

        $tenantId = $request->headers->get('X-Tenant-ID', '');
        if (!is_string($tenantId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1) {
            return new JsonResponse(['detail' => 'A valid tenant context is required.'], 400);
        }

        try {
            $result = $this->db->transactional(function (Connection $db) use ($tenantId, $orderId): ?array {
                $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
                $row = $db->fetchAssociative(
                    'SELECT order_id, tenant_id, status, quote_snapshot FROM orders WHERE tenant_id = :tenant AND order_id = :id',
                    ['tenant' => $tenantId, 'id' => $orderId],
                );
                if (!is_array($row)) {
                    return null;
                }
                if ($row['status'] !== 'accepted') {
                    return ['notPayable' => true, 'status' => $row['status']];
                }
                $snapshot = is_array($row['quote_snapshot'])
                    ? $row['quote_snapshot']
                    : json_decode((string) $row['quote_snapshot'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($snapshot) || array_is_list($snapshot)) {
                    throw new \UnexpectedValueException('The stored quote snapshot is invalid.');
                }
                $currency = strtoupper((string) ($snapshot['currency'] ?? ''));
                $amountMinor = $this->amountMinor($snapshot);
                if ($amountMinor < 50 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                    throw new \UnexpectedValueException('The accepted order has no valid payable amount and currency in its quote snapshot.');
                }
                return [
                    'orderId' => (string) $row['order_id'],
                    'tenantId' => (string) $row['tenant_id'],
                    'status' => (string) $row['status'],
                    'amountMinor' => $amountMinor,
                    'currency' => $currency,
                    'quoteId' => (string) ($snapshot['quoteId'] ?? ''),
                ];
            });
        } catch (\Throwable) {
            return new JsonResponse(['detail' => 'The order payment context is unavailable.'], 503);
        }

        if ($result === null) {
            return new JsonResponse(['detail' => 'Order not found.'], 404);
        }
        if (($result['notPayable'] ?? false) === true) {
            return new JsonResponse(['detail' => 'Only accepted orders can be paid.', 'orderStatus' => $result['status']], 409);
        }
        return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string,mixed> $snapshot */
    private function amountMinor(array $snapshot): int
    {
        if (isset($snapshot['totalAmountMinor']) && is_int($snapshot['totalAmountMinor'])) {
            return $snapshot['totalAmountMinor'];
        }
        $amount = $snapshot['totalAmount'] ?? $snapshot['monthlyAmount'] ?? null;
        if (!is_string($amount) || preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount) !== 1) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if (isset($snapshot['totalAmount'])) {
            return $minor;
        }
        // monthlyAmount represents one payable installment; never multiply it by the lease duration.
        return $minor;
    }
}
