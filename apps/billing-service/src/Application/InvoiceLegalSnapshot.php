<?php

declare(strict_types=1);

namespace App\Application;

final class InvoiceLegalSnapshot
{
    /**
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $issuer
     * @return array<string,mixed>
     */
    public static function build(array $customer, array $issuer): array
    {
        $type = ($customer['customerType'] ?? null) === 'company' ? 'company' : 'individual';
        $name = $type === 'company'
            ? trim((string) ($customer['legalName'] ?? ''))
            : trim((string) ($customer['firstName'] ?? '') . ' ' . (string) ($customer['lastName'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($customer['legalName'] ?? ''));
        }

        $recipientAddress = self::address($customer['billingAddress'] ?? null);
        $issuerAddress = self::address($issuer['address'] ?? null);
        $recipientReady = $name !== '' && self::completeAddress($recipientAddress);
        $issuerName = trim((string) ($issuer['legalName'] ?? ''));
        $issuerTaxReference = trim((string) ($issuer['taxNumber'] ?? '')) !== ''
            || trim((string) ($issuer['vatId'] ?? '')) !== '';
        $issuerReady = $issuerName !== '' && $issuerTaxReference && self::completeAddress($issuerAddress);

        return [
            'issuer' => [
                'legalName' => $issuerName,
                'taxNumber' => $issuer['taxNumber'] ?? null,
                'vatId' => $issuer['vatId'] ?? null,
                'address' => $issuerAddress,
            ],
            'recipient' => [
                'customerId' => $customer['customerId'] ?? null,
                'customerType' => $type,
                'legalName' => $type === 'company' ? $name : null,
                'firstName' => $customer['firstName'] ?? null,
                'lastName' => $customer['lastName'] ?? null,
                'displayName' => $name,
                'email' => $customer['email'] ?? null,
                'vatId' => $customer['vatId'] ?? null,
                'address' => $recipientAddress,
                'customerVersion' => $customer['customerVersion'] ?? null,
            ],
            'legalReadiness' => [
                'issuerComplete' => $issuerReady,
                'recipientComplete' => $recipientReady,
                'readyToIssue' => $issuerReady && $recipientReady,
            ],
            'documentStatus' => $issuerReady && $recipientReady ? 'issued' : 'draft',
        ];
    }

    /** @return array<string,mixed>|null */
    private static function address(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        return [
            'line1' => self::nullableString($value['line1'] ?? null),
            'line2' => self::nullableString($value['line2'] ?? null),
            'postalCode' => self::nullableString($value['postalCode'] ?? null),
            'city' => self::nullableString($value['city'] ?? null),
            'region' => self::nullableString($value['region'] ?? null),
            'countryCode' => is_string($value['countryCode'] ?? null)
                ? strtoupper(trim($value['countryCode']))
                : null,
        ];
    }

    /** @param array<string,mixed>|null $address */
    private static function completeAddress(?array $address): bool
    {
        if ($address === null) {
            return false;
        }

        foreach (['line1', 'postalCode', 'city'] as $field) {
            if (!is_string($address[$field] ?? null) || trim($address[$field]) === '') {
                return false;
            }
        }

        return is_string($address['countryCode'] ?? null)
            && preg_match('/^[A-Z]{2}$/D', $address['countryCode']) === 1;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
