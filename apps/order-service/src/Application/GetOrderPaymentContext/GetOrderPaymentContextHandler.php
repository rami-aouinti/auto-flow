<?php

declare(strict_types=1);

namespace App\Application\GetOrderPaymentContext;

use Doctrine\DBAL\Connection;
use UnexpectedValueException;

final readonly class GetOrderPaymentContextHandler
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(string $tenantId, string $orderId): ?array
    {
        return $this->db->transactional(function (Connection $db) use ($tenantId, $orderId): ?array {
            $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
            $row = $db->fetchAssociative(
                'SELECT order_id, tenant_id, status, quote_snapshot, customer_snapshot FROM orders WHERE tenant_id = :tenant AND order_id = :id',
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
                throw new UnexpectedValueException('The stored quote snapshot is invalid.');
            }

            $currency = strtoupper((string) ($snapshot['currency'] ?? ''));
            $amountMinor = $this->amountMinor($snapshot);
            if ($amountMinor < 50 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new UnexpectedValueException('The accepted order has no valid payable amount and currency in its quote snapshot.');
            }

            $customerSnapshot = is_array($row['customer_snapshot'])
                ? $row['customer_snapshot']
                : json_decode((string) $row['customer_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($customerSnapshot) || array_is_list($customerSnapshot) || $customerSnapshot === []) {
                throw new UnexpectedValueException('The stored customer snapshot is invalid.');
            }
            $addresses = $customerSnapshot['addresses'] ?? [];
            if (!is_array($addresses) || !array_is_list($addresses)) {
                throw new UnexpectedValueException('The stored customer address snapshot is invalid.');
            }
            $billingAddress = null;
            foreach ($addresses as $address) {
                if (is_array($address) && ($address['address_type'] ?? null) === 'billing') {
                    if ($billingAddress === null || ($address['is_default'] ?? false) === true) {
                        $billingAddress = $address;
                    }
                    if (($address['is_default'] ?? false) === true) {
                        break;
                    }
                }
            }
            $billingSnapshot = [
                'customerId' => (string) ($customerSnapshot['customerId'] ?? ''),
                'customerType' => $customerSnapshot['customerType'] ?? null,
                'firstName' => $customerSnapshot['firstName'] ?? null,
                'lastName' => $customerSnapshot['lastName'] ?? null,
                'legalName' => $customerSnapshot['legalName'] ?? null,
                'email' => $customerSnapshot['email'] ?? null,
                'vatId' => $customerSnapshot['vatId'] ?? null,
                'billingAddress' => is_array($billingAddress) ? [
                    'line1' => $billingAddress['line1'] ?? null,
                    'line2' => $billingAddress['line2'] ?? null,
                    'postalCode' => $billingAddress['postal_code'] ?? null,
                    'city' => $billingAddress['city'] ?? null,
                    'region' => $billingAddress['region'] ?? null,
                    'countryCode' => $billingAddress['country_code'] ?? null,
                ] : null,
                'customerVersion' => isset($row['customer_version']) ? (int) $row['customer_version'] : null,
            ];

            return [
                'orderId' => (string) $row['order_id'],
                'tenantId' => (string) $row['tenant_id'],
                'status' => (string) $row['status'],
                'amountMinor' => $amountMinor,
                'currency' => $currency,
                'quoteId' => (string) ($snapshot['quoteId'] ?? ''),
                'billingSnapshot' => $billingSnapshot,
            ];
        });
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
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
