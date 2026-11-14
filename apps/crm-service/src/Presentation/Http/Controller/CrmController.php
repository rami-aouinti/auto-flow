<?php
declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Domain\ApplicationLifecycle;
use App\Infrastructure\Upstream\CrmReferenceValidator;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Route('/api/v1/crm')]
final readonly class CrmController
{
    public function __construct(
        private Connection $db,
        private CrmReferenceValidator $referenceValidator,
        private HttpClientInterface $httpClient,
        private string $orderServiceUrl,
    ) {}

    #[Route('/applications', methods: ['POST'])]
    public function createApplication(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'crm:applications:write');
            $data = $this->applicationInput($this->body($request));
            foreach (['customers:read'] as $scope) {
                if (!$principal->hasScope($scope)) {
                    throw new AccessDeniedHttpException('Required downstream permission is missing: ' . $scope);
                }
            }
            if ($data['offerId'] !== null) {
                foreach (['offers:read', 'pricing:quote', 'catalog:read'] as $scope) {
                    if (!$principal->hasScope($scope)) {
                        throw new AccessDeniedHttpException('Required downstream permission is missing: ' . $scope);
                    }
                }
            }
            $this->referenceValidator->validate(
                $data['customerId'], $data['offerId'], $data['quoteId'], $data['vehicleId'],
                $this->tenant($principal), $this->bearerToken($request),
            );
            $key = trim((string) $request->headers->get('Idempotency-Key', ''));
            if ($key === '' || strlen($key) > 128) {
                return $this->problem(400, 'A valid Idempotency-Key header is required.');
            }
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            return $this->db->transactional(function (Connection $db) use ($principal, $data, $key, $hash): JsonResponse {
                $tenant = $this->tenant($principal);
                $this->tenantContext($db, $tenant);
                $old = $db->fetchAssociative(
                    'SELECT request_hash,response_body FROM crm_idempotency_keys WHERE tenant_id=:tenant AND actor_id=:actor AND idempotency_key=:key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $key],
                );
                if ($old !== false) {
                    if (!hash_equals((string) $old['request_hash'], $hash)) {
                        return $this->problem(409, 'Idempotency-Key was already used for a different command.');
                    }
                    $body = is_string($old['response_body']) ? json_decode($old['response_body'], true, 512, JSON_THROW_ON_ERROR) : $old['response_body'];
                    return new JsonResponse($body, 200, ['Cache-Control' => 'no-store']);
                }

                $id = Uuid::v7()->toRfc4122();
                $db->insert('crm_applications', [
                    'tenant_id' => $tenant, 'application_id' => $id, 'customer_id' => $data['customerId'],
                    'offer_id' => $data['offerId'], 'vehicle_id' => $data['vehicleId'], 'quote_id' => $data['quoteId'],
                    'applicant_message' => $data['message'], 'status' => 'submitted', 'eligibility_status' => 'pending',
                    'created_by' => $principal->subject, 'updated_by' => $principal->subject,
                ]);
                $item = $this->loadApplication($db, $tenant, $id);
                $this->audit($db, $tenant, $id, $principal->subject, 'CrmApplicationSubmitted', ['customerId' => $data['customerId']]);
                $this->outbox($db, $tenant, $id, 'crm.application.submitted.v1', [
                    'applicationId' => $id, 'customerId' => $data['customerId'], 'offerId' => $data['offerId'],
                    'vehicleId' => $data['vehicleId'], 'quoteId' => $data['quoteId'], 'status' => 'submitted',
                ]);
                $db->insert('crm_idempotency_keys', [
                    'tenant_id' => $tenant, 'actor_id' => $principal->subject, 'idempotency_key' => $key,
                    'request_hash' => $hash, 'response_body' => json_encode($item, JSON_THROW_ON_ERROR),
                ]);
                return new JsonResponse($item, 201, ['Cache-Control' => 'no-store', 'ETag' => '"1"']);
            });
        } catch (\InvalidArgumentException|\JsonException $error) {
            return $this->problem(400, $error->getMessage());
        } catch (\DomainException $error) {
            return $this->problem(422, $error->getMessage());
        } catch (AccessDeniedHttpException $error) {
            return $this->problem(403, $error->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'Application conflicts with an existing record.');
        } catch (Throwable) {
            return $this->problem(503, 'Application could not be submitted.');
        }
    }

    #[Route('/applications', methods: ['GET'])]
    public function listApplications(Request $request): JsonResponse
    {
        return $this->run($request, 'crm:applications:read', function (Connection $db, string $tenant) use ($request): JsonResponse {
            $limit = max(1, min(100, $request->query->getInt('limit', 20)));
            $offset = max(0, $request->query->getInt('offset', 0));
            $where = ['tenant_id=:tenant'];
            $params = ['tenant' => $tenant, 'limit' => $limit, 'offset' => $offset];
            foreach (['status', 'eligibilityStatus'] as $field) {
                $value = $request->query->get($field);
                if (is_string($value) && $value !== '') {
                    $column = $field === 'eligibilityStatus' ? 'eligibility_status' : 'status';
                    $where[] = $column . '=:filter_' . $field;
                    $params['filter_' . $field] = $value;
                }
            }
            $advisor = $request->query->get('assignedAdvisorId');
            if (is_string($advisor) && $advisor !== '') {
                $where[] = 'assigned_advisor_id=:advisor';
                $params['advisor'] = $advisor;
            }
            $sql = implode(' AND ', $where);
            $items = $db->fetchAllAssociative(
                "SELECT application_id,customer_id,offer_id,vehicle_id,quote_id,status,eligibility_status,assigned_advisor_id,submitted_at,reviewed_at,version,created_at,updated_at FROM crm_applications WHERE $sql ORDER BY submitted_at DESC LIMIT :limit OFFSET :offset",
                $params,
            );
            $total = (int) $db->fetchOne("SELECT COUNT(*) FROM crm_applications WHERE $sql", array_diff_key($params, ['limit' => true, 'offset' => true]));
            return new JsonResponse(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset], 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function getApplication(Request $request, string $id): JsonResponse
    {
        return $this->run($request, 'crm:applications:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            $item = $this->loadApplication($db, $tenant, $id);
            return $item === null ? $this->problem(404, 'Application not found.') : new JsonResponse($item, 200, ['Cache-Control' => 'no-store', 'ETag' => '"' . $item['version'] . '"']);
        });
    }

    #[Route('/applications/{id}/convert', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function convertApplication(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'crm:applications:manage');
            if (!$principal->hasScope('orders:write')) {
                throw new AccessDeniedHttpException('orders:write permission is required.');
            }
            $tenant = $this->tenant($principal);
            $token = $this->bearerToken($request);
            $idempotencyKey = 'crm-application-' . strtolower($id);

            $claim = $this->db->transactional(function (Connection $db) use ($principal, $tenant, $id): array {
                $this->tenantContext($db, $tenant);
                $application = $db->fetchAssociative(
                    'SELECT customer_id,offer_id,vehicle_id,quote_id,status,eligibility_status FROM crm_applications WHERE tenant_id=:tenant AND application_id=:id FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $id],
                );
                if ($application === false) {
                    return ['error' => 404, 'detail' => 'Application not found.'];
                }
                $conversion = $db->fetchAssociative(
                    'SELECT order_id,initiated_by,conversion_status FROM crm_order_conversions WHERE tenant_id=:tenant AND application_id=:id',
                    ['tenant' => $tenant, 'id' => $id],
                );
                if ($conversion !== false && $conversion['conversion_status'] === 'completed') {
                    return ['completed' => true, 'orderId' => $conversion['order_id']];
                }
                if ($application['status'] !== 'approved' || $application['eligibility_status'] !== 'eligible') {
                    return ['error' => 409, 'detail' => 'Only approved applications with eligible review status can be converted.'];
                }
                if ($conversion !== false && $conversion['initiated_by'] !== $principal->subject) {
                    return ['error' => 409, 'detail' => 'The conversion was initiated by another advisor and must be retried by that advisor.'];
                }
                if ($conversion === false) {
                    $db->insert('crm_order_conversions', [
                        'tenant_id' => $tenant,
                        'application_id' => $id,
                        'initiated_by' => $principal->subject,
                        'conversion_status' => 'processing',
                    ]);
                }
                return [
                    'customerId' => $application['customer_id'],
                    'offerId' => $application['offer_id'],
                    'vehicleId' => $application['vehicle_id'],
                    'quoteId' => $application['quote_id'],
                ];
            });

            if (isset($claim['error'])) {
                return $this->problem((int) $claim['error'], (string) $claim['detail']);
            }
            if (($claim['completed'] ?? false) === true) {
                return new JsonResponse(['applicationId' => $id, 'orderId' => $claim['orderId'], 'status' => 'converted'], 200, ['Cache-Control' => 'no-store']);
            }
            if (!is_string($claim['offerId'] ?? null) || !is_string($claim['quoteId'] ?? null) || !is_string($claim['vehicleId'] ?? null)) {
                return $this->problem(409, 'The application must reference an offer, quote and vehicle before it can be converted.');
            }

            $response = $this->httpClient->request('POST', rtrim($this->orderServiceUrl, '/') . '/api/v1/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Idempotency-Key' => $idempotencyKey,
                    'X-Tenant-ID' => $tenant,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $claim,
                'timeout' => 5.0,
                'max_duration' => 10.0,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                return $this->problem(403, 'The caller is not authorized to create an order.');
            }
            if ($status === 409) {
                return $this->problem(409, 'The order conversion conflicts with an existing order command.');
            }
            if ($status === 422) {
                return $this->problem(422, 'The application references are not valid for order creation.');
            }
            if ($status < 200 || $status >= 300) {
                return $this->problem(503, 'Order Service is temporarily unavailable; retry the same conversion.');
            }
            $order = $response->toArray(false);
            $orderId = is_array($order) ? ($order['orderId'] ?? null) : null;
            if (!is_string($orderId) || !Uuid::isValid($orderId)) {
                return $this->problem(503, 'Order Service returned an invalid order identifier; retry the same conversion.');
            }

            return $this->db->transactional(function (Connection $db) use ($principal, $tenant, $id, $orderId): JsonResponse {
                $this->tenantContext($db, $tenant);
                $application = $db->fetchAssociative(
                    'SELECT status FROM crm_applications WHERE tenant_id=:tenant AND application_id=:id FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $id],
                );
                if ($application === false) {
                    return $this->problem(404, 'Application not found.');
                }
                if ($application['status'] !== 'converted') {
                    $db->executeStatement(
                        "UPDATE crm_applications SET status='converted',version=version+1,updated_at=CURRENT_TIMESTAMP,updated_by=:actor WHERE tenant_id=:tenant AND application_id=:id AND status='approved'",
                        ['actor' => $principal->subject, 'tenant' => $tenant, 'id' => $id],
                    );
                    $this->audit($db, $tenant, $id, $principal->subject, 'CrmApplicationConverted', ['orderId' => $orderId]);
                    $this->outbox($db, $tenant, $id, 'crm.application.converted.v1', ['applicationId' => $id, 'orderId' => $orderId]);
                }
                $db->executeStatement(
                    "UPDATE crm_order_conversions SET order_id=:order,conversion_status='completed',updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:tenant AND application_id=:id",
                    ['order' => $orderId, 'tenant' => $tenant, 'id' => $id],
                );
                return new JsonResponse(['applicationId' => $id, 'orderId' => $orderId, 'status' => 'converted'], 201, ['Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $error) {
            return $this->problem(403, $error->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Application conversion is temporarily unavailable; retry the same conversion.');
        }
    }

    #[Route('/applications/{id}/status', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PATCH'])]
    public function transition(Request $request, string $id): JsonResponse
    {
        try {
            $body = $this->body($request);
            $target = $body['status'] ?? null;
            $reason = $body['reason'] ?? null;
            if (!is_string($target) || ($reason !== null && !is_string($reason))) {
                throw new \InvalidArgumentException('status and optional reason are required.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }

        if ($target === 'converted') {
            return $this->problem(409, 'Use the application conversion endpoint to create an Order.');
        }

        return $this->run($request, 'crm:applications:manage', function (Connection $db, string $tenant, AuthenticatedPrincipal $principal) use ($id, $target, $reason): JsonResponse {
            $current = $db->fetchAssociative('SELECT status,version FROM crm_applications WHERE tenant_id=:tenant AND application_id=:id FOR UPDATE', ['tenant' => $tenant, 'id' => $id]);
            if ($current === false) {
                return $this->problem(404, 'Application not found.');
            }
            if (!ApplicationLifecycle::canTransition((string) $current['status'], $target)) {
                return $this->problem(409, 'Transition from ' . $current['status'] . ' to ' . $target . ' is not allowed.');
            }
            $db->executeStatement(
                'UPDATE crm_applications SET status=:status,version=version+1,updated_at=CURRENT_TIMESTAMP,updated_by=:actor,reviewed_at=CASE WHEN :status IN (\'eligible\',\'declined\',\'approved\') THEN CURRENT_TIMESTAMP ELSE reviewed_at END WHERE tenant_id=:tenant AND application_id=:id',
                ['status' => $target, 'actor' => $principal->subject, 'tenant' => $tenant, 'id' => $id],
            );
            $this->audit($db, $tenant, $id, $principal->subject, 'CrmApplicationStatusChanged', ['from' => $current['status'], 'to' => $target, 'reason' => $reason]);
            $this->outbox($db, $tenant, $id, 'crm.application.status_changed.v1', ['applicationId' => $id, 'from' => $current['status'], 'to' => $target, 'reason' => $reason]);
            return new JsonResponse($this->loadApplication($db, $tenant, $id), 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/assignment', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    public function assignAdvisor(Request $request, string $id): JsonResponse
    {
        try {
            $body = $this->body($request);
            $advisor = $body['advisorId'] ?? null;
            if (!is_string($advisor) || trim($advisor) === '' || strlen($advisor) > 255) {
                throw new \InvalidArgumentException('advisorId is required.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }
        return $this->run($request, 'crm:applications:assign', function (Connection $db, string $tenant, AuthenticatedPrincipal $principal) use ($id, $advisor): JsonResponse {
            $n = $db->executeStatement('UPDATE crm_applications SET assigned_advisor_id=:advisor,version=version+1,updated_at=CURRENT_TIMESTAMP,updated_by=:actor WHERE tenant_id=:tenant AND application_id=:id AND status NOT IN (\'converted\',\'withdrawn\')', ['advisor' => $advisor, 'actor' => $principal->subject, 'tenant' => $tenant, 'id' => $id]);
            if ($n !== 1) {
                return $this->problem(404, 'Open application not found.');
            }
            $this->audit($db, $tenant, $id, $principal->subject, 'CrmAdvisorAssigned', ['advisorId' => $advisor]);
            $this->outbox($db, $tenant, $id, 'crm.application.advisor_assigned.v1', ['applicationId' => $id, 'advisorId' => $advisor]);
            return new JsonResponse($this->loadApplication($db, $tenant, $id), 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/eligibility', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    public function setEligibility(Request $request, string $id): JsonResponse
    {
        try {
            $body = $this->body($request);
            $status = $body['status'] ?? null;
            $result = $body['result'] ?? [];
            if (!in_array($status, ['pending', 'in_review', 'eligible', 'ineligible', 'manual_review'], true) || !is_array($result) || array_is_list($result)) {
                throw new \InvalidArgumentException('A valid eligibility status and JSON result object are required.');
            }
            if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 16384) {
                throw new \InvalidArgumentException('Eligibility result exceeds 16 KiB.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }
        return $this->run($request, 'crm:eligibility:manage', function (Connection $db, string $tenant, AuthenticatedPrincipal $principal) use ($id, $status, $result): JsonResponse {
            $n = $db->executeStatement('UPDATE crm_applications SET eligibility_status=:status,eligibility_result=:result,version=version+1,updated_at=CURRENT_TIMESTAMP,updated_by=:actor WHERE tenant_id=:tenant AND application_id=:id', ['status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'actor' => $principal->subject, 'tenant' => $tenant, 'id' => $id]);
            if ($n !== 1) {
                return $this->problem(404, 'Application not found.');
            }
            $this->audit($db, $tenant, $id, $principal->subject, 'CrmEligibilityUpdated', ['status' => $status]);
            $this->outbox($db, $tenant, $id, 'crm.application.eligibility_updated.v1', ['applicationId' => $id, 'status' => $status, 'result' => $result]);
            return new JsonResponse($this->loadApplication($db, $tenant, $id), 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/tasks', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function createTask(Request $request, string $id): JsonResponse
    {
        try {
            $body = $this->body($request);
            $title = $body['title'] ?? null;
            $assignee = $body['assignedTo'] ?? null;
            if (!is_string($title) || trim($title) === '' || strlen($title) > 180 || !is_string($assignee) || trim($assignee) === '' || strlen($assignee) > 255) {
                throw new \InvalidArgumentException('title and assignedTo are required.');
            }
            $description = $body['description'] ?? null;
            if ($description !== null && (!is_string($description) || strlen($description) > 10000)) {
                throw new \InvalidArgumentException('description is invalid.');
            }
            $dueAt = $body['dueAt'] ?? null;
            if ($dueAt !== null && (!is_string($dueAt) || strtotime($dueAt) === false)) {
                throw new \InvalidArgumentException('dueAt must be an ISO date-time.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }
        return $this->run($request, 'crm:tasks:write', function (Connection $db, string $tenant, AuthenticatedPrincipal $principal) use ($id, $title, $assignee, $description, $dueAt): JsonResponse {
            if ($this->loadApplication($db, $tenant, $id) === null) {
                return $this->problem(404, 'Application not found.');
            }
            $taskId = Uuid::v7()->toRfc4122();
            $db->insert('crm_tasks', ['tenant_id' => $tenant, 'task_id' => $taskId, 'application_id' => $id, 'assigned_to' => $assignee, 'title' => trim($title), 'description' => $description, 'due_at' => $dueAt, 'created_by' => $principal->subject]);
            $this->audit($db, $tenant, $id, $principal->subject, 'CrmTaskCreated', ['taskId' => $taskId, 'assignedTo' => $assignee]);
            return new JsonResponse($db->fetchAssociative('SELECT task_id,application_id,assigned_to,title,description,due_at,status,created_at FROM crm_tasks WHERE tenant_id=:tenant AND task_id=:id', ['tenant' => $tenant, 'id' => $taskId]), 201, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/tasks', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function listTasks(Request $request, string $id): JsonResponse
    {
        return $this->run($request, 'crm:tasks:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            if ($this->loadApplication($db, $tenant, $id) === null) {
                return $this->problem(404, 'Application not found.');
            }
            return new JsonResponse(['items' => $db->fetchAllAssociative('SELECT task_id,assigned_to,title,description,due_at,status,completed_at,created_at FROM crm_tasks WHERE tenant_id=:tenant AND application_id=:id ORDER BY due_at NULLS LAST,created_at DESC', ['tenant' => $tenant, 'id' => $id])], 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/tasks/{taskId}', requirements: ['id' => '[0-9a-fA-F-]{36}', 'taskId' => '[0-9a-fA-F-]{36}'], methods: ['PATCH'])]
    public function updateTask(Request $request, string $id, string $taskId): JsonResponse
    {
        try {
            $status = $this->body($request)['status'] ?? null;
            if (!in_array($status, ['open', 'in_progress', 'completed', 'cancelled'], true)) {
                throw new \InvalidArgumentException('Invalid task status.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }
        return $this->run($request, 'crm:tasks:write', function (Connection $db, string $tenant, AuthenticatedPrincipal $principal) use ($id, $taskId, $status): JsonResponse {
            $n = $db->executeStatement('UPDATE crm_tasks SET status=:status,completed_at=CASE WHEN :status=\'completed\' THEN CURRENT_TIMESTAMP ELSE NULL END,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:tenant AND application_id=:application AND task_id=:task', ['status' => $status, 'tenant' => $tenant, 'application' => $id, 'task' => $taskId]);
            if ($n !== 1) {
                return $this->problem(404, 'Task not found.');
            }
            $this->audit($db, $tenant, $id, $principal->subject, 'CrmTaskUpdated', ['taskId' => $taskId, 'status' => $status]);
            return new JsonResponse($db->fetchAssociative('SELECT task_id,application_id,assigned_to,title,description,due_at,status,completed_at FROM crm_tasks WHERE tenant_id=:tenant AND task_id=:task', ['tenant' => $tenant, 'task' => $taskId]), 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/interactions', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function addInteraction(Request $request, string $id): JsonResponse
    {
        try {
            $body = $this->body($request);
            $channel = $body['channel'] ?? null;
            $subject = $body['subject'] ?? null;
            $text = $body['body'] ?? null;
            if (!in_array($channel, ['phone', 'email', 'meeting', 'note', 'system'], true) || !is_string($subject) || trim($subject) === '' || strlen($subject) > 180 || ($text !== null && !is_string($text))) {
                throw new \InvalidArgumentException('channel, subject and optional body are invalid.');
            }
            if (is_string($text) && strlen($text) > 20000) {
                throw new \InvalidArgumentException('Interaction body is too long.');
            }
        } catch (Throwable $error) {
            return $this->problem(400, $error->getMessage());
        }
        return $this->run($request, 'crm:interactions:write', function (Connection $db, string $tenant, AuthenticatedPrincipal $principal) use ($id, $channel, $subject, $text): JsonResponse {
            if ($this->loadApplication($db, $tenant, $id) === null) {
                return $this->problem(404, 'Application not found.');
            }
            $interactionId = Uuid::v7()->toRfc4122();
            $db->insert('crm_interactions', ['tenant_id' => $tenant, 'interaction_id' => $interactionId, 'application_id' => $id, 'actor_id' => $principal->subject, 'channel' => $channel, 'subject' => trim($subject), 'body' => $text]);
            $this->audit($db, $tenant, $id, $principal->subject, 'CrmInteractionRecorded', ['interactionId' => $interactionId, 'channel' => $channel]);
            return new JsonResponse($db->fetchAssociative('SELECT interaction_id,actor_id,channel,subject,body,occurred_at FROM crm_interactions WHERE tenant_id=:tenant AND interaction_id=:id', ['tenant' => $tenant, 'id' => $interactionId]), 201, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/interactions', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function listInteractions(Request $request, string $id): JsonResponse
    {
        return $this->run($request, 'crm:interactions:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            if ($this->loadApplication($db, $tenant, $id) === null) {
                return $this->problem(404, 'Application not found.');
            }
            return new JsonResponse(['items' => $db->fetchAllAssociative('SELECT interaction_id,actor_id,channel,subject,body,occurred_at FROM crm_interactions WHERE tenant_id=:tenant AND application_id=:id ORDER BY occurred_at DESC LIMIT 200', ['tenant' => $tenant, 'id' => $id])], 200, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route('/applications/{id}/history', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(Request $request, string $id): JsonResponse
    {
        return $this->run($request, 'crm:applications:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            if ($this->loadApplication($db, $tenant, $id) === null) {
                return $this->problem(404, 'Application not found.');
            }
            $items = $db->fetchAllAssociative('SELECT event_id,actor_id,event_type,payload,occurred_at FROM crm_audit_events WHERE tenant_id=:tenant AND application_id=:id ORDER BY occurred_at DESC LIMIT 200', ['tenant' => $tenant, 'id' => $id]);
            foreach ($items as &$item) {
                if (is_string($item['payload'])) { $item['payload'] = json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR); }
            }
            unset($item);
            return new JsonResponse(['items' => $items], 200, ['Cache-Control' => 'no-store']);
        });
    }

    private function run(Request $request, string $scope, callable $callback): JsonResponse
    {
        try {
            $principal = $this->principal($request, $scope);
            return $this->db->transactional(function (Connection $db) use ($principal, $callback): JsonResponse {
                $tenant = $this->tenant($principal);
                $this->tenantContext($db, $tenant);
                return $callback($db, $tenant, $principal);
            });
        } catch (AccessDeniedHttpException $error) {
            return $this->problem(403, $error->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'CRM operation is temporarily unavailable.');
        }
    }

    private function bearerToken(Request $request): string
    {
        $authorization = $request->headers->get('Authorization', '');
        if (!is_string($authorization) || preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/D', $authorization, $matches) !== 1) {
            throw new AccessDeniedHttpException('A verified bearer token is required for downstream validation.');
        }
        return $matches[1];
    }

    private function principal(Request $request, string $scope): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope($scope)) {
            throw new AccessDeniedHttpException('Required CRM permission is missing.');
        }
        return $principal;
    }

    private function tenant(AuthenticatedPrincipal $principal): string
    {
        if (!is_string($principal->tenantId) || $principal->tenantId === '') {
            throw new AccessDeniedHttpException('Tenant context is missing.');
        }
        return $principal->tenantId;
    }

    private function tenantContext(Connection $db, string $tenant): void
    {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
    }

    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('A JSON object is required.');
        }
        return $body;
    }

    private function applicationInput(array $body): array
    {
        $customerId = $body['customerId'] ?? null;
        if (!is_string($customerId) || !Uuid::isValid($customerId)) {
            throw new \DomainException('A valid customerId UUID is required.');
        }
        $optional = [];
        foreach (['offerId' => 36, 'quoteId' => 128, 'vehicleId' => 128] as $field => $max) {
            $value = $body[$field] ?? null;
            if ($value !== null && (!is_string($value) || trim($value) === '' || strlen($value) > $max)) {
                throw new \DomainException($field . ' is invalid.');
            }
            if ($field === 'offerId' && $value !== null && !Uuid::isValid($value)) {
                throw new \DomainException('offerId must be a UUID.');
            }
            $optional[$field] = $value;
        }
        $message = $body['message'] ?? null;
        if ($message !== null && (!is_string($message) || strlen($message) > 4000)) {
            throw new \DomainException('message is invalid.');
        }
        return ['customerId' => $customerId, 'offerId' => $optional['offerId'], 'quoteId' => $optional['quoteId'], 'vehicleId' => $optional['vehicleId'], 'message' => $message];
    }

    private function loadApplication(Connection $db, string $tenant, string $id): ?array
    {
        $item = $db->fetchAssociative('SELECT a.application_id,a.customer_id,a.offer_id,a.vehicle_id,a.quote_id,a.status,a.applicant_message,a.assigned_advisor_id,a.eligibility_status,a.eligibility_result,a.submitted_at,a.reviewed_at,a.version,a.created_at,a.updated_at,c.order_id AS "orderId" FROM crm_applications a LEFT JOIN crm_order_conversions c ON c.tenant_id=a.tenant_id AND c.application_id=a.application_id AND c.conversion_status=\'completed\' WHERE a.tenant_id=:tenant AND a.application_id=:id', ['tenant' => $tenant, 'id' => $id]);
        if ($item === false) { return null; }
        if (is_string($item['eligibility_result'])) { $item['eligibility_result'] = json_decode($item['eligibility_result'], true, 512, JSON_THROW_ON_ERROR); }
        return $item;
    }

    private function audit(Connection $db, string $tenant, string $applicationId, string $actor, string $type, array $payload): void
    {
        $db->insert('crm_audit_events', ['tenant_id' => $tenant, 'event_id' => Uuid::v7()->toRfc4122(), 'application_id' => $applicationId, 'actor_id' => $actor, 'event_type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    private function outbox(Connection $db, string $tenant, string $applicationId, string $type, array $payload): void
    {
        $eventId = Uuid::v7()->toRfc4122();
        $envelope = ['eventId' => $eventId, 'eventType' => $type, 'schemaVersion' => 1, 'occurredAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'tenantId' => $tenant, 'producer' => 'crm-service', 'payload' => $payload];
        $db->insert('crm_outbox_events', ['tenant_id' => $tenant, 'event_id' => $eventId, 'aggregate_id' => $applicationId, 'event_type' => $type, 'payload' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        $title = match ($status) { 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity', default => 'Service Unavailable' };
        return new JsonResponse(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
