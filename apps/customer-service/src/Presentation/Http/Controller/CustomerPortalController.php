<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/v1/customers/me')]
final readonly class CustomerPortalController
{
    public function __construct(private Connection $db)
    {
    }

    #[Route('', methods: ['GET'])]
    public function profile(Request $request): JsonResponse
    {
        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);

                return $customer === null
                    ? $this->problem(404, 'No customer profile is linked to this account. Contact your AutoFlow advisor.')
                    : new JsonResponse($customer, 200, ['Cache-Control' => 'no-store']);
            },
        );
    }

    #[Route('', methods: ['PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body) || $body === []) {
                throw new \InvalidArgumentException('A non-empty JSON object is required.');
            }

            $allowed = [
                'firstName' => 'first_name',
                'lastName' => 'last_name',
                'email' => 'email',
                'phone' => 'phone',
                'locale' => 'locale',
            ];
            foreach ($body as $key => $value) {
                if (!isset($allowed[$key])) {
                    throw new \InvalidArgumentException(
                        'Only firstName, lastName, email, phone and locale fields can be changed.',
                    );
                }
                if ($key === 'phone' && ($value === null || (is_string($value) && trim($value) === ''))) {
                    $body[$key] = null;
                    continue;
                }

                $max = match ($key) {
                    'firstName', 'lastName' => 100,
                    'phone' => 40,
                    'locale' => 10,
                    default => 254,
                };
                if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
                    throw new \InvalidArgumentException($key . ' is invalid.');
                }
                $body[$key] = trim($value);
            }
            if (isset($body['email']) && filter_var($body['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Email address is invalid.');
            }
            if (isset($body['locale']) && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $body['locale'])) {
                throw new \InvalidArgumentException('Locale is invalid.');
            }
            if (isset($body['email'])) {
                $body['email'] = mb_strtolower($body['email']);
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($body, $allowed): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }
                if (
                    $customer['customer_type'] === 'company'
                    && (isset($body['firstName']) || isset($body['lastName']))
                ) {
                    return $this->problem(422, 'Company contact names must be changed by an advisor.');
                }
                if (
                    $customer['customer_type'] === 'individual'
                    && (isset($body['firstName']) || isset($body['lastName']))
                ) {
                    $first = $body['firstName'] ?? $customer['first_name'];
                    $last = $body['lastName'] ?? $customer['last_name'];
                    if ($first === '' || $last === '') {
                        return $this->problem(422, 'First and last name are required.');
                    }
                }

                $sets = [];
                $params = [
                    'tenant' => $tenant,
                    'id' => $customer['customer_id'],
                    'subject' => $principal->subject,
                    'version' => (int) $customer['version'],
                ];
                foreach ($body as $key => $value) {
                    $sets[] = $allowed[$key] . ' = :' . $key;
                    $params[$key] = $value;
                    if ($key === 'email') {
                        $sets[] = 'email_normalized = :normalized';
                        $params['normalized'] = $value;
                    }
                }
                $sets[] = 'version = version + 1';
                $sets[] = 'updated_at = CURRENT_TIMESTAMP';
                $sets[] = 'updated_by = :subject';

                $updated = $db->executeStatement(
                    'UPDATE customer_records SET ' . implode(', ', $sets)
                    . ' WHERE tenant_id = :tenant AND customer_id = :id'
                    . ' AND version = :version AND archived_at IS NULL',
                    $params,
                );
                if ($updated !== 1) {
                    return $this->problem(409, 'Customer profile changed concurrently. Reload and retry.');
                }

                $this->audit(
                    $db,
                    $tenant,
                    $customer['customer_id'],
                    $principal->subject,
                    'CustomerSelfServiceProfileUpdated',
                    ['fields' => array_keys($body)],
                );

                return new JsonResponse(
                    $this->customer($db, $principal, $tenant),
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            },
        );
    }

    #[Route('/addresses', methods: ['GET'])]
    public function addresses(Request $request): JsonResponse
    {
        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }

                $items = $db->fetchAllAssociative(
                    'SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default'
                    . ' FROM customer_addresses WHERE tenant_id=:tenant AND customer_id=:id'
                    . ' AND deleted_at IS NULL ORDER BY is_default DESC,created_at',
                    ['tenant' => $tenant, 'id' => $customer['customer_id']],
                );

                return new JsonResponse(['items' => $items], 200, ['Cache-Control' => 'no-store']);
            },
        );
    }

    #[Route('/addresses', methods: ['POST'])]
    public function addAddress(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                throw new \InvalidArgumentException('A JSON object is required.');
            }
            $address = $this->addressInput($body);
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($address): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }
                if ($address['isDefault']) {
                    $db->executeStatement(
                        'UPDATE customer_addresses SET is_default=false'
                        . ' WHERE tenant_id=:tenant AND customer_id=:id'
                        . ' AND address_type=:type AND deleted_at IS NULL',
                        [
                            'tenant' => $tenant,
                            'id' => $customer['customer_id'],
                            'type' => $address['addressType'],
                        ],
                    );
                }

                $id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
                $db->insert('customer_addresses', [
                    'tenant_id' => $tenant,
                    'address_id' => $id,
                    'customer_id' => $customer['customer_id'],
                    'address_type' => $address['addressType'],
                    'label' => $address['label'],
                    'line1' => $address['line1'],
                    'line2' => $address['line2'],
                    'postal_code' => $address['postalCode'],
                    'city' => $address['city'],
                    'region' => $address['region'],
                    'country_code' => $address['countryCode'],
                    'is_default' => $address['isDefault'],
                ]);
                $this->audit(
                    $db,
                    $tenant,
                    $customer['customer_id'],
                    $principal->subject,
                    'CustomerSelfServiceAddressAdded',
                    ['addressId' => $id],
                );

                return new JsonResponse(
                    $this->address($db, $tenant, $customer['customer_id'], $id),
                    201,
                    ['Cache-Control' => 'no-store'],
                );
            },
        );
    }

    #[Route('/addresses/{addressId}', requirements: ['addressId' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    public function updateAddress(Request $request, string $addressId): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                throw new \InvalidArgumentException('A JSON object is required.');
            }
            $address = $this->addressInput($body);
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($addressId, $address): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }

                $exists = $db->fetchOne(
                    'SELECT 1 FROM customer_addresses WHERE tenant_id=:tenant AND customer_id=:customer'
                    . ' AND address_id=:address AND deleted_at IS NULL',
                    ['tenant' => $tenant, 'customer' => $customer['customer_id'], 'address' => $addressId],
                );
                if (!$exists) {
                    return $this->problem(404, 'Address not found.');
                }
                if ($address['isDefault']) {
                    $db->executeStatement(
                        'UPDATE customer_addresses SET is_default=false'
                        . ' WHERE tenant_id=:tenant AND customer_id=:customer AND address_type=:type'
                        . ' AND address_id<>:address AND deleted_at IS NULL',
                        [
                            'tenant' => $tenant,
                            'customer' => $customer['customer_id'],
                            'type' => $address['addressType'],
                            'address' => $addressId,
                        ],
                    );
                }

                $db->executeStatement(
                    'UPDATE customer_addresses SET address_type=:type,label=:label,line1=:line1,line2=:line2,'
                    . 'postal_code=:postal,city=:city,region=:region,country_code=:country,is_default=:default,'
                    . 'updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:tenant AND customer_id=:customer'
                    . ' AND address_id=:address AND deleted_at IS NULL',
                    [
                        'type' => $address['addressType'],
                        'label' => $address['label'],
                        'line1' => $address['line1'],
                        'line2' => $address['line2'],
                        'postal' => $address['postalCode'],
                        'city' => $address['city'],
                        'region' => $address['region'],
                        'country' => $address['countryCode'],
                        'default' => $address['isDefault'],
                        'tenant' => $tenant,
                        'customer' => $customer['customer_id'],
                        'address' => $addressId,
                    ],
                );
                $this->audit(
                    $db,
                    $tenant,
                    $customer['customer_id'],
                    $principal->subject,
                    'CustomerSelfServiceAddressUpdated',
                    ['addressId' => $addressId],
                );

                return new JsonResponse(
                    $this->address($db, $tenant, $customer['customer_id'], $addressId),
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            },
        );
    }

    #[Route('/addresses/{addressId}', requirements: ['addressId' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function deleteAddress(Request $request, string $addressId): JsonResponse
    {
        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($addressId): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }

                $deleted = $db->executeStatement(
                    'UPDATE customer_addresses SET deleted_at=CURRENT_TIMESTAMP,is_default=false,'
                    . 'updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:tenant AND customer_id=:customer'
                    . ' AND address_id=:address AND deleted_at IS NULL',
                    ['tenant' => $tenant, 'customer' => $customer['customer_id'], 'address' => $addressId],
                );
                if ($deleted !== 1) {
                    return $this->problem(404, 'Address not found.');
                }

                $this->audit(
                    $db,
                    $tenant,
                    $customer['customer_id'],
                    $principal->subject,
                    'CustomerSelfServiceAddressDeleted',
                    ['addressId' => $addressId],
                );

                return new JsonResponse(null, 204, ['Cache-Control' => 'no-store']);
            },
        );
    }

    #[Route('/consents', methods: ['GET'])]
    public function consents(Request $request): JsonResponse
    {
        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }

                $items = $db->fetchAllAssociative(
                    'SELECT purpose,granted,source,recorded_at FROM customer_consents'
                    . ' WHERE tenant_id=:tenant AND customer_id=:id ORDER BY purpose',
                    ['tenant' => $tenant, 'id' => $customer['customer_id']],
                );

                return new JsonResponse(['items' => $items], 200, ['Cache-Control' => 'no-store']);
            },
        );
    }

    #[Route('/consents', methods: ['PUT'])]
    public function setConsent(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $purpose = $body['purpose'] ?? null;
            $granted = $body['granted'] ?? null;
            if (
                !is_string($purpose)
                || preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $purpose) !== 1
                || !is_bool($granted)
            ) {
                throw new \InvalidArgumentException('purpose and boolean granted are required.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        return $this->run(
            $request,
            function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($purpose, $granted): JsonResponse {
                $customer = $this->customer($db, $principal, $tenant);
                if ($customer === null) {
                    return $this->problem(404, 'No customer profile is linked to this account.');
                }

                $db->executeStatement(
                    'INSERT INTO customer_consents'
                    . '(tenant_id,customer_id,purpose,granted,source,recorded_at)'
                    . ' VALUES(:tenant,:id,:purpose,:granted,:source,CURRENT_TIMESTAMP)'
                    . ' ON CONFLICT(tenant_id,customer_id,purpose) DO UPDATE'
                    . ' SET granted=EXCLUDED.granted,source=EXCLUDED.source,recorded_at=CURRENT_TIMESTAMP',
                    [
                        'tenant' => $tenant,
                        'id' => $customer['customer_id'],
                        'purpose' => $purpose,
                        'granted' => $granted,
                        'source' => 'customer-self-service',
                    ],
                );
                $this->audit(
                    $db,
                    $tenant,
                    $customer['customer_id'],
                    $principal->subject,
                    'CustomerSelfServiceConsentChanged',
                    ['purpose' => $purpose, 'granted' => $granted],
                );

                return new JsonResponse(
                    ['purpose' => $purpose, 'granted' => $granted],
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            },
        );
    }

    /** @return array<string,mixed> */
    private function addressInput(array $body): array
    {
        $type = $body['addressType'] ?? 'residential';
        if (!is_string($type) || !in_array($type, ['billing', 'shipping', 'residential', 'business'], true)) {
            throw new \InvalidArgumentException('addressType is invalid.');
        }

        $required = [
            'line1' => 180,
            'postalCode' => 20,
            'city' => 120,
            'countryCode' => 2,
        ];
        $result = [
            'addressType' => $type,
            'label' => $this->optionalString($body, 'label', 80),
        ];
        foreach ($required as $field => $max) {
            $value = $body[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
                throw new \InvalidArgumentException(
                    $field . ' is required and must be at most ' . $max . ' characters.',
                );
            }
            $result[$field] = trim($value);
        }

        $result['line2'] = $this->optionalString($body, 'line2', 180);
        $result['region'] = $this->optionalString($body, 'region', 120);
        $result['countryCode'] = strtoupper($result['countryCode']);
        if (!preg_match('/^[A-Z]{2}$/', $result['countryCode'])) {
            throw new \InvalidArgumentException('countryCode must be ISO alpha-2.');
        }

        $default = $body['isDefault'] ?? false;
        if (!is_bool($default)) {
            throw new \InvalidArgumentException('isDefault must be boolean.');
        }
        $result['isDefault'] = $default;

        return $result;
    }

    private function optionalString(array $body, string $field, int $max): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field]) || mb_strlen(trim($body[$field])) > $max) {
            throw new \InvalidArgumentException($field . ' is invalid.');
        }

        return trim($body[$field]);
    }

    private function address(Connection $db, string $tenant, string $customerId, string $addressId): array
    {
        $address = $db->fetchAssociative(
            'SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default'
            . ' FROM customer_addresses WHERE tenant_id=:tenant AND customer_id=:customer'
            . ' AND address_id=:address AND deleted_at IS NULL',
            ['tenant' => $tenant, 'customer' => $customerId, 'address' => $addressId],
        );
        if ($address === false) {
            throw new \RuntimeException('Saved address could not be retrieved.');
        }

        return $address;
    }

    private function audit(
        Connection $db,
        string $tenant,
        string $customerId,
        string $actor,
        string $event,
        array $payload,
    ): void {
        $db->insert('customer_audit_events', [
            'tenant_id' => $tenant,
            'event_id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
            'customer_id' => $customerId,
            'actor_id' => $actor,
            'event_type' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    private function run(Request $request, callable $operation): JsonResponse
    {
        try {
            $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope('customers:portal')) {
                throw new AccessDeniedHttpException('Customer self-service permission is required.');
            }
            if (!$principal->tenantId) {
                throw new AccessDeniedHttpException('Tenant context is missing.');
            }

            return $this->db->transactional(function (Connection $db) use ($principal, $operation): JsonResponse {
                $tenant = (string) $principal->tenantId;
                $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);

                return $operation($db, $principal, $tenant);
            });
        } catch (AccessDeniedHttpException $error) {
            return $this->problem(403, $error->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'A conflicting customer data value is already used in this tenant. Reload and retry.');
        } catch (Throwable) {
            return $this->problem(503, 'Customer self-service is temporarily unavailable.');
        }
    }

    private function customer(Connection $db, AuthenticatedPrincipal $principal, string $tenant): ?array
    {
        $id = $db->fetchOne(
            "SELECT customer_id FROM customer_records WHERE tenant_id=:tenant"
            . " AND keycloak_subject=:subject AND status='active' AND archived_at IS NULL",
            ['tenant' => $tenant, 'subject' => $principal->subject],
        );
        if ($id === false) {
            return null;
        }

        $customer = $db->fetchAssociative(
            'SELECT customer_id,customer_type,first_name,last_name,legal_name,email,phone,locale,status,version,'
            . 'created_at,updated_at FROM customer_records WHERE tenant_id=:tenant AND customer_id=:id',
            ['tenant' => $tenant, 'id' => $id],
        );

        return $customer === false ? null : $customer;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => match ($status) {
                400 => 'Bad Request',
                403 => 'Forbidden',
                404 => 'Not Found',
                409 => 'Conflict',
                422 => 'Unprocessable Entity',
                default => 'Service Unavailable',
            },
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
