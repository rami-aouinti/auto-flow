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

#[Route('/api/v1/customers')]
final readonly class CustomerIdentityController
{
    public function __construct(private Connection $db) {}

    #[Route('/{id}/identity', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    public function link(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope('customers:manage') || !$principal->tenantId) {
                throw new AccessDeniedHttpException('Customer management permission is required.');
            }
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $subject = $body['subject'] ?? null;
            if (!is_string($subject) || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', trim($subject))) {
                return $this->problem(400, 'A UUID Keycloak subject is required.');
            }
            $subject = trim($subject);
            return $this->db->transactional(function (Connection $db) use ($principal, $id, $subject): JsonResponse {
                $tenant = (string) $principal->tenantId;
                $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
                $updated = $db->executeStatement(
                    'UPDATE customer_records SET keycloak_subject=:subject,updated_at=CURRENT_TIMESTAMP,updated_by=:actor,version=version+1 WHERE tenant_id=:tenant AND customer_id=:id AND archived_at IS NULL',
                    ['subject' => $subject, 'actor' => $principal->subject, 'tenant' => $tenant, 'id' => $id],
                );
                if ($updated !== 1) return $this->problem(404, 'Active customer not found.');
                $db->insert('customer_audit_events', [
                    'tenant_id' => $tenant,
                    'event_id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
                    'customer_id' => $id,
                    'actor_id' => $principal->subject,
                    'event_type' => 'CustomerIdentityLinked',
                    'payload' => json_encode(['subject' => $subject], JSON_THROW_ON_ERROR),
                ]);
                return new JsonResponse(['customerId' => $id, 'linked' => true], 200, ['Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $error) {
            return $this->problem(403, $error->getMessage());
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return $this->problem(409, 'This Keycloak account is already linked to another customer in this tenant.');
        } catch (Throwable) {
            return $this->problem(503, 'Customer identity could not be linked.');
        }
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['type' => 'about:blank', 'title' => 'Customer identity error', 'status' => $status, 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
