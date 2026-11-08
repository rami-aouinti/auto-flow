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
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Route('/api/v1/inventory')]
final readonly class InventoryController
{
    private const STATUSES = ['available','blocked','in_transit','delivered','sold','maintenance','quarantined'];

    public function __construct(private Connection $db) {}

    #[Route('/units', methods: ['POST'])]
    public function create(Request $r): JsonResponse
    {
        try {
            $p = $this->principal($r, 'inventory:write');
            $d = $this->createInput($this->body($r));
            $key = trim((string) $r->headers->get('Idempotency-Key', ''));
            if ($key === '' || strlen($key) > 128) return $this->problem(400, 'A valid Idempotency-Key is required.');
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            return $this->db->transactional(function (Connection $db) use ($p, $d, $key, $hash): JsonResponse {
                $tenant = $this->tenant($p);
                $this->tenantContext($db, $tenant);
                $existing = $db->fetchAssociative(
                    'SELECT request_hash,response_body FROM inventory_idempotency_keys WHERE tenant_id=:tenant AND idempotency_key=:key',
                    ['tenant'=>$tenant,'key'=>$key]
                );
                if ($existing !== false) {
                    if (!hash_equals($existing['request_hash'], $hash)) return $this->problem(409, 'Idempotency-Key was used for another request.');
                    return new JsonResponse($this->jsonValue($existing['response_body']), 200, ['Cache-Control'=>'no-store']);
                }
                $id = Uuid::v7()->toRfc4122();
                $db->insert('inventory_units', [
                    'tenant_id'=>$tenant,'inventory_unit_id'=>$id,'vehicle_variant_id'=>$d['vehicleVariantId'],
                    'vin'=>$d['vin'],'vin_normalized'=>strtoupper($d['vin']),'dealer_id'=>$d['dealerId'],
                    'location_code'=>$d['locationCode'],'country_code'=>$d['countryCode'],
                    'status'=>'available','condition'=>$d['condition'],'mileage_km'=>$d['mileageKm'],
                    'first_registration_date'=>$d['firstRegistrationDate'],'created_by'=>$p->subject,'updated_by'=>$p->subject
                ]);
                $item = $this->load($db, $tenant, $id);
                $this->record($db, $tenant, $id, $p->subject, 'InventoryUnitCreated', null, 'available', null, $d['locationCode'], ['vin'=>$d['vin'],'vehicleVariantId'=>$d['vehicleVariantId']]);
                $db->insert('inventory_idempotency_keys', [
                    'tenant_id'=>$tenant,'idempotency_key'=>$key,'request_hash'=>$hash,
                    'response_body'=>json_encode($item, JSON_THROW_ON_ERROR)
                ]);
                return new JsonResponse($item, 201, ['Cache-Control'=>'no-store','ETag'=>'"1"']);
            });
        } catch (\InvalidArgumentException|\JsonException $e) { return $this->problem(400, $e->getMessage()); }
        catch (\DomainException $e) { return $this->problem(422, $e->getMessage()); }
        catch (UniqueConstraintViolationException) { return $this->problem(409, 'VIN already exists in this tenant.'); }
        catch (AccessDeniedHttpException $e) { return $this->problem(403, $e->getMessage()); }
        catch (Throwable) { return $this->problem(503, 'Inventory unit could not be created.'); }
    }

    #[Route('/units', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        return $this->run($r, 'inventory:read', function (Connection $db, string $tenant) use ($r): JsonResponse {
            $limit = max(1, min(100, $r->query->getInt('limit', 20)));
            $offset = max(0, $r->query->getInt('offset', 0));
            $where = ['tenant_id=:tenant'];
            $params = ['tenant'=>$tenant,'limit'=>$limit,'offset'=>$offset];
            $variant = $r->query->get('vehicleVariantId');
            $status = $r->query->get('status');
            $dealer = $r->query->get('dealerId');
            if (is_string($variant) && Uuid::isValid($variant)) { $where[]='vehicle_variant_id=:variant'; $params['variant']=$variant; }
            if (is_string($status) && in_array($status, self::STATUSES, true)) { $where[]='status=:status'; $params['status']=$status; }
            if (is_string($dealer) && Uuid::isValid($dealer)) { $where[]='dealer_id=:dealer'; $params['dealer']=$dealer; }
            $sql = implode(' AND ', $where);
            $items = $db->fetchAllAssociative(
                "SELECT inventory_unit_id,vehicle_variant_id,vin,dealer_id,location_code,country_code,status,condition,mileage_km,first_registration_date,version,acquired_at,created_at,updated_at FROM inventory_units WHERE $sql ORDER BY acquired_at DESC LIMIT :limit OFFSET :offset",
                $params
            );
            $total = (int) $db->fetchOne("SELECT COUNT(*) FROM inventory_units WHERE $sql", array_diff_key($params, ['limit'=>true,'offset'=>true]));
            return new JsonResponse(['items'=>$items,'total'=>$total,'limit'=>$limit,'offset'=>$offset], 200, ['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/availability', methods: ['GET'])]
    public function availability(Request $r): JsonResponse
    {
        return $this->run($r, 'inventory:read', function (Connection $db, string $tenant) use ($r): JsonResponse {
            $variant = (string) $r->query->get('vehicleVariantId', '');
            if (!Uuid::isValid($variant)) return $this->problem(400, 'vehicleVariantId must be a UUID.');
            $rows = $db->fetchAllAssociative(
                "SELECT status,COUNT(*) AS quantity FROM inventory_units WHERE tenant_id=:tenant AND vehicle_variant_id=:variant GROUP BY status ORDER BY status",
                ['tenant'=>$tenant,'variant'=>$variant]
            );
            $counts = array_fill_keys(self::STATUSES, 0);
            foreach ($rows as $row) $counts[$row['status']] = (int) $row['quantity'];
            return new JsonResponse([
                'vehicleVariantId'=>$variant,
                'available'=>$counts['available'],
                'total'=>array_sum($counts),
                'byStatus'=>$counts
            ], 200, ['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/units/{id}', requirements: ['id'=>'[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function get(Request $r, string $id): JsonResponse
    {
        return $this->run($r, 'inventory:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            $item = $this->load($db, $tenant, $id);
            return $item === null ? $this->problem(404, 'Inventory unit not found.') : new JsonResponse($item, 200, ['Cache-Control'=>'no-store','ETag'=>'"'.$item['version'].'"']);
        });
    }

    #[Route('/units/{id}/status', requirements: ['id'=>'[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function changeStatus(Request $r, string $id): JsonResponse
    {
        try {
            $body = $this->body($r);
            $target = $body['status'] ?? null;
            $reason = $body['reason'] ?? null;
            if (!is_string($target) || !in_array($target, self::STATUSES, true)) throw new \InvalidArgumentException('A valid target status is required.');
            if ($reason !== null && (!is_string($reason) || mb_strlen($reason) > 500)) throw new \InvalidArgumentException('reason must be at most 500 characters.');
            $key = trim((string) $r->headers->get('Idempotency-Key', ''));
            if ($key === '' || strlen($key) > 128) return $this->problem(400, 'A valid Idempotency-Key is required.');
            $expected = trim((string) $r->headers->get('If-Match', ''), '"');
            if ($expected === '' || !ctype_digit($expected)) return $this->problem(428, 'If-Match with the current version is required.');
        } catch (Throwable $e) { return $this->problem(400, $e->getMessage()); }

        return $this->run($r, 'inventory:write', function (Connection $db, string $tenant, AuthenticatedPrincipal $p) use ($id, $target, $reason, $key, $expected): JsonResponse {
            $old = $db->fetchAssociative('SELECT status,location_code,version FROM inventory_units WHERE tenant_id=:tenant AND inventory_unit_id=:id FOR UPDATE', ['tenant'=>$tenant,'id'=>$id]);
            if ($old === false) return $this->problem(404, 'Inventory unit not found.');
            $hash = hash('sha256', json_encode([$id,$target,$reason,$expected], JSON_THROW_ON_ERROR));
            $existing = $db->fetchAssociative('SELECT request_hash,response_body FROM inventory_idempotency_keys WHERE tenant_id=:tenant AND idempotency_key=:key', ['tenant'=>$tenant,'key'=>$key]);
            if ($existing !== false) {
                if (!hash_equals($existing['request_hash'], $hash)) return $this->problem(409, 'Idempotency-Key was used for another command.');
                $replayed = $this->jsonValue($existing['response_body']);
                return new JsonResponse($replayed, 200, ['Cache-Control'=>'no-store','ETag'=>'"'.$replayed['version'].'"']);
            }
            if ((int)$old['version'] !== (int)$expected) return $this->problem(412, 'Inventory unit version is stale.');
            if ($old['status'] === $target) return new JsonResponse($this->load($db, $tenant, $id), 200, ['ETag'=>'"'.$old['version'].'"']);
            if (!$this->transitionAllowed($old['status'], $target)) return $this->problem(409, 'Inventory status transition is not allowed.');
            $db->executeStatement('UPDATE inventory_units SET status=:status,version=version+1,updated_by=:actor,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:tenant AND inventory_unit_id=:id', ['status'=>$target,'actor'=>$p->subject,'tenant'=>$tenant,'id'=>$id]);
            $this->record($db, $tenant, $id, $p->subject, 'InventoryStatusChanged', $old['status'], $target, $old['location_code'], $old['location_code'], ['reason'=>$reason]);
            $item = $this->load($db, $tenant, $id);
            $db->insert('inventory_idempotency_keys', ['tenant_id'=>$tenant,'idempotency_key'=>$key,'request_hash'=>$hash,'response_body'=>json_encode($item, JSON_THROW_ON_ERROR)]);
            return new JsonResponse($item, 200, ['Cache-Control'=>'no-store','ETag'=>'"'.$item['version'].'"']);
        });
    }

    #[Route('/units/{id}/history', requirements: ['id'=>'[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(Request $r, string $id): JsonResponse
    {
        return $this->run($r, 'inventory:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            if ($this->load($db, $tenant, $id) === null) return $this->problem(404, 'Inventory unit not found.');
            $items = $db->fetchAllAssociative('SELECT movement_id,from_status,to_status,from_location_code,to_location_code,reason,actor_id,occurred_at FROM inventory_movements WHERE tenant_id=:tenant AND inventory_unit_id=:id ORDER BY occurred_at DESC', ['tenant'=>$tenant,'id'=>$id]);
            return new JsonResponse(['items'=>$items], 200, ['Cache-Control'=>'no-store']);
        });
    }

    private function run(Request $r, string $scope, callable $fn): JsonResponse
    {
        try {
            $p = $this->principal($r, $scope);
            return $this->db->transactional(function (Connection $db) use ($p, $fn): JsonResponse {
                $tenant = $this->tenant($p);
                $this->tenantContext($db, $tenant);
                return $fn($db, $tenant, $p);
            });
        } catch (AccessDeniedHttpException $e) { return $this->problem(403, $e->getMessage()); }
        catch (Throwable) { return $this->problem(503, 'Inventory operation is temporarily unavailable.'); }
    }

    private function principal(Request $r, string $scope): AuthenticatedPrincipal
    {
        $p = $r->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$p instanceof AuthenticatedPrincipal || !$p->hasScope($scope)) throw new AccessDeniedHttpException('Required inventory permission is missing.');
        return $p;
    }

    private function tenant(AuthenticatedPrincipal $p): string
    {
        if (!is_string($p->tenantId) || $p->tenantId === '') throw new AccessDeniedHttpException('Tenant context is missing.');
        return $p->tenantId;
    }

    private function tenantContext(Connection $db, string $tenant): void
    {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant'=>$tenant]);
    }

    private function body(Request $r): array
    {
        $body = json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) throw new \InvalidArgumentException('A JSON object is required.');
        return $body;
    }

    private function createInput(array $body): array
    {
        $variant = $body['vehicleVariantId'] ?? null;
        if (!is_string($variant) || !Uuid::isValid($variant)) throw new \DomainException('vehicleVariantId must be a UUID.');
        $vin = strtoupper(trim((string)($body['vin'] ?? '')));
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) throw new \DomainException('vin must be a valid 17-character VIN.');
        $dealer = $body['dealerId'] ?? null;
        if ($dealer !== null && (!is_string($dealer) || !Uuid::isValid($dealer))) throw new \DomainException('dealerId must be a UUID.');
        $location = $body['locationCode'] ?? null;
        if ($location !== null && (!is_string($location) || trim($location)==='' || mb_strlen($location)>64)) throw new \DomainException('locationCode is invalid.');
        $country = isset($body['countryCode']) ? strtoupper((string)$body['countryCode']) : null;
        if ($country !== null && !preg_match('/^[A-Z]{2}$/', $country)) throw new \DomainException('countryCode must be ISO alpha-2.');
        $condition = $body['condition'] ?? 'new';
        if (!in_array($condition, ['new','used','damaged'], true)) throw new \DomainException('condition must be new, used or damaged.');
        $mileage = $body['mileageKm'] ?? null;
        if ($mileage !== null && (!is_int($mileage) || $mileage < 0)) throw new \DomainException('mileageKm must be a non-negative integer.');
        $registration = $body['firstRegistrationDate'] ?? null;
        if ($registration !== null && (!is_string($registration) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $registration))) throw new \DomainException('firstRegistrationDate must use YYYY-MM-DD.');
        return ['vehicleVariantId'=>$variant,'vin'=>$vin,'dealerId'=>$dealer,'locationCode'=>$location,'countryCode'=>$country,'condition'=>$condition,'mileageKm'=>$mileage,'firstRegistrationDate'=>$registration];
    }

    private function load(Connection $db, string $tenant, string $id): ?array
    {
        $row = $db->fetchAssociative('SELECT inventory_unit_id,vehicle_variant_id,vin,dealer_id,location_code,country_code,status,condition,mileage_km,first_registration_date,version,acquired_at,created_at,updated_at FROM inventory_units WHERE tenant_id=:tenant AND inventory_unit_id=:id', ['tenant'=>$tenant,'id'=>$id]);
        return $row === false ? null : $row;
    }

    private function record(Connection $db, string $tenant, string $id, string $actor, string $event, ?string $from, string $to, ?string $fromLocation, ?string $toLocation, array $payload): void
    {
        $eventId = Uuid::v7()->toRfc4122();
        $occurred = (new \DateTimeImmutable())->format(DATE_ATOM);
        $envelope = [
            'eventId'=>$eventId,'eventType'=>$event.'.v1','schemaVersion'=>1,'producer'=>'inventory-service',
            'aggregate'=>['type'=>'InventoryUnit','id'=>$id],'tenantId'=>$tenant,'occurredAt'=>$occurred,
            'data'=>['inventoryUnitId'=>$id] + $payload
        ];
        $db->insert('inventory_movements', ['tenant_id'=>$tenant,'movement_id'=>Uuid::v7()->toRfc4122(),'inventory_unit_id'=>$id,'from_status'=>$from,'to_status'=>$to,'from_location_code'=>$fromLocation,'to_location_code'=>$toLocation,'reason'=>$payload['reason']??null,'actor_id'=>$actor]);
        $db->insert('inventory_audit_events', ['tenant_id'=>$tenant,'event_id'=>$eventId,'inventory_unit_id'=>$id,'actor_id'=>$actor,'event_type'=>$event,'payload'=>json_encode($envelope,JSON_THROW_ON_ERROR)]);
        $db->insert('inventory_outbox_events', ['tenant_id'=>$tenant,'event_id'=>$eventId,'inventory_unit_id'=>$id,'event_type'=>$event.'.v1','payload'=>json_encode($envelope,JSON_THROW_ON_ERROR)]);
    }

    private function transitionAllowed(string $from, string $to): bool
    {
        return InventoryStatus::from($from)->canTransitionTo(InventoryStatus::from($to));
    }

    private function jsonValue(mixed $value): array
    {
        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['type'=>'about:blank','title'=>match($status){400=>'Bad Request',403=>'Forbidden',404=>'Not Found',409=>'Conflict',412=>'Precondition Failed',428=>'Precondition Required',422=>'Unprocessable Entity',default=>'Service Unavailable'},'status'=>$status,'detail'=>$detail],$status,['Content-Type'=>'application/problem+json','Cache-Control'=>'no-store']);
    }
}
