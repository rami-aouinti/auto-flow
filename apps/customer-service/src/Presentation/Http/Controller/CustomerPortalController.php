<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/v1/customers/me')]
final readonly class CustomerPortalController
{
    public function __construct(private Connection $db) {}

    #[Route('', methods: ['GET'])]
    public function profile(Request $request): JsonResponse
    {
        return $this->run($request, function (Connection $db, AuthenticatedPrincipal $principal, string $tenant): JsonResponse {
            $customer = $this->customer($db, $principal, $tenant);
            return $customer === null
                ? $this->problem(404, 'No customer profile is linked to this account. Contact your AutoFlow advisor.')
                : new JsonResponse($customer, 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('', methods: ['PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body) || $body === []) {
                throw new \InvalidArgumentException('A non-empty JSON object is required.');
            }
            $allowed = ['firstName' => 'first_name', 'lastName' => 'last_name', 'email' => 'email', 'phone' => 'phone', 'locale' => 'locale'];
            foreach ($body as $key => $value) {
                if (!isset($allowed[$key]) || !is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 254) {
                    throw new \InvalidArgumentException('Only valid firstName, lastName, email, phone and locale fields can be changed.');
                }
                $body[$key] = trim($value);
            }
            if (isset($body['email']) && filter_var($body['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Email address is invalid.');
            }
            if (isset($body['locale']) && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $body['locale'])) {
                throw new \InvalidArgumentException('Locale is invalid.');
            }
            if (isset($body['email'])) $body['email'] = mb_strtolower($body['email']);
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        return $this->run($request, function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($body, $allowed): JsonResponse {
            $customer = $this->customer($db, $principal, $tenant);
            if ($customer === null) return $this->problem(404, 'No customer profile is linked to this account.');
            if ($customer['customer_type'] === 'company' && (isset($body['firstName']) || isset($body['lastName']))) {
                return $this->problem(422, 'Company contact names must be changed by an advisor.');
            }
            if ($customer['customer_type'] === 'individual' && (isset($body['firstName']) || isset($body['lastName']))) {
                $first = $body['firstName'] ?? $customer['first_name'];
                $last = $body['lastName'] ?? $customer['last_name'];
                if ($first === '' || $last === '') return $this->problem(422, 'First and last name are required.');
            }
            $sets = [];
            $params = ['tenant' => $tenant, 'id' => $customer['customer_id'], 'subject' => $principal->subject, 'version' => (int) $customer['version']];
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
                'UPDATE customer_records SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant AND customer_id = :id AND version = :version AND archived_at IS NULL',
                $params,
            );
            if ($updated !== 1) return $this->problem(409, 'Customer profile changed concurrently. Reload and retry.');
            $db->insert('customer_audit_events', [
                'tenant_id' => $tenant,
                'event_id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
                'customer_id' => $customer['customer_id'],
                'actor_id' => $principal->subject,
                'event_type' => 'CustomerSelfServiceProfileUpdated',
                'payload' => json_encode(['fields' => array_keys($body)], JSON_THROW_ON_ERROR),
            ]);
            return new JsonResponse($this->customer($db, $principal, $tenant), 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/addresses', methods: ['GET'])]
    public function addresses(Request $request): JsonResponse
    {
        return $this->run($request, function (Connection $db, AuthenticatedPrincipal $principal, string $tenant): JsonResponse {
            $customer = $this->customer($db, $principal, $tenant);
            if ($customer === null) return $this->problem(404, 'No customer profile is linked to this account.');
            return new JsonResponse(['items' => $db->fetchAllAssociative(
                'SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default FROM customer_addresses WHERE tenant_id=:tenant AND customer_id=:id AND deleted_at IS NULL ORDER BY is_default DESC,created_at',
                ['tenant' => $tenant, 'id' => $customer['customer_id']],
            )], 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/consents', methods: ['GET'])]
    public function consents(Request $request): JsonResponse
    {
        return $this->run($request, function (Connection $db, AuthenticatedPrincipal $principal, string $tenant): JsonResponse {
            $customer = $this->customer($db, $principal, $tenant);
            if ($customer === null) return $this->problem(404, 'No customer profile is linked to this account.');
            return new JsonResponse(['items' => $db->fetchAllAssociative(
                'SELECT purpose,granted,source,recorded_at FROM customer_consents WHERE tenant_id=:tenant AND customer_id=:id ORDER BY purpose',
                ['tenant' => $tenant, 'id' => $customer['customer_id']],
            )], 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/consents', methods: ['PUT'])]
    public function setConsent(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $purpose = $body['purpose'] ?? null;
            $granted = $body['granted'] ?? null;
            if (!is_string($purpose) || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $purpose) || !is_bool($granted)) {
                throw new \InvalidArgumentException('purpose and boolean granted are required.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        return $this->run($request, function (Connection $db, AuthenticatedPrincipal $principal, string $tenant) use ($purpose, $granted): JsonResponse {
            $customer = $this->customer($db, $principal, $tenant);
            if ($customer === null) return $this->problem(404, 'No customer profile is linked to this account.');
            $db->executeStatement(
                'INSERT INTO customer_consents(tenant_id,customer_id,purpose,granted,source,recorded_at) VALUES(:tenant,:id,:purpose,:granted,:source,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id,customer_id,purpose) DO UPDATE SET granted=EXCLUDED.granted,source=EXCLUDED.source,recorded_at=CURRENT_TIMESTAMP',
                ['tenant' => $tenant, 'id' => $customer['customer_id'], 'purpose' => $purpose, 'granted' => $granted, 'source' => 'customer-self-service'],
            );
            $db->insert('customer_audit_events', [
                'tenant_id' => $tenant,
                'event_id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
                'customer_id' => $customer['customer_id'],
                'actor_id' => $principal->subject,
                'event_type' => 'CustomerSelfServiceConsentChanged',
                'payload' => json_encode(['purpose' => $purpose, 'granted' => $granted], JSON_THROW_ON_ERROR),
            ]);
            return new JsonResponse(['purpose' => $purpose, 'granted' => $granted], 200, ['Cache-Control' => 'no-store']);
        });
    }

    private function run(Request $request, callable $operation): JsonResponse
    {
        try {
            $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope('customers:portal')) {
                throw new AccessDeniedHttpException('Customer self-service permission is required.');
            }
            if (!$principal->tenantId) throw new AccessDeniedHttpException('Tenant context is missing.');
            return $this->db->transactional(function (Connection $db) use ($principal, $operation): JsonResponse {
                $tenant = (string) $principal->tenantId;
                $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
                return $operation($db, $principal, $tenant);
            });
        } catch (AccessDeniedHttpException $error) {
            return $this->problem(403, $error->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Customer self-service is temporarily unavailable.');
        }
    }

    private function customer(Connection $db, AuthenticatedPrincipal $principal, string $tenant): ?array
    {
        $id = $db->fetchOne(
            "SELECT customer_id FROM customer_records WHERE tenant_id=:tenant AND keycloak_subject=:subject AND status='active' AND archived_at IS NULL",
            ['tenant' => $tenant, 'subject' => $principal->subject],
        );
        if ($id === false) return null;
        $customer = $db->fetchAssociative(
            'SELECT customer_id,customer_type,first_name,last_name,legal_name,email,phone,locale,status,version,created_at,updated_at FROM customer_records WHERE tenant_id=:tenant AND customer_id=:id',
            ['tenant' => $tenant, 'id' => $id],
        );
        return $customer === false ? null : $customer;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => match ($status) { 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity', default => 'Service Unavailable' },
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
