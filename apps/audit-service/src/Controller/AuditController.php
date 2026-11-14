<?php
declare(strict_types=1);
namespace App\Controller;
use App\Infrastructure\Audit\AuditRepository;
use App\Application\AuditPayloadRedactor;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final readonly class AuditController
{
 public function __construct(private AuditRepository $repository,private AuditPayloadRedactor $redactor){}
 #[Route('/api/v1/audit/events',methods:['POST'])]
 public function ingest(Request $request):JsonResponse{
  $p=$this->principal($request);if(!$p)return $this->error(401,'Unauthorized','A valid bearer token is required.');
  if(!$p->hasScope('audit:write'))return $this->error(403,'Forbidden','The token requires audit:write.');
  if(!$p->tenantId)return $this->error(403,'Tenant required','A tenant_id claim is required.');
  try{$in=$request->toArray();}catch(\Throwable){return $this->error(400,'Invalid JSON','The request body must be a JSON object.');}
  foreach(['eventId','eventType','actorType','resourceType','resourceId','occurredAt'] as $f)if(!isset($in[$f])||!is_string($in[$f])||trim($in[$f])==='')return $this->error(422,'Validation failed',"$f is required.");
  if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/',$in['eventId']))return $this->error(422,'Validation failed','eventId has an invalid format.');
  if(!in_array($in['actorType'],['user','service','system'],true))return $this->error(422,'Validation failed','actorType must be user, service or system.');
  if(strlen($in['eventType'])>120||strlen($in['resourceType'])>80||strlen($in['resourceId'])>160)return $this->error(422,'Validation failed','Event/resource identifiers exceed their maximum length.');
  try{$date=(new \DateTimeImmutable($in['occurredAt']))->setTimezone(new \DateTimeZone('UTC'));}catch(\Throwable){return $this->error(422,'Validation failed','occurredAt must be an ISO-8601 timestamp.');}
  $payload=$in['payload']??[];if(!is_array($payload)||strlen(json_encode($payload)?:'')>16384)return $this->error(413,'Payload too large','payload must not exceed 16 KiB.');
  $event=['eventId'=>$in['eventId'],'eventType'=>$in['eventType'],'actorType'=>$in['actorType'],'actorId'=>isset($in['actorId'])?substr((string)$in['actorId'],0,255):$p->subject,'resourceType'=>$in['resourceType'],'resourceId'=>$in['resourceId'],'occurredAt'=>$date->format('Y-m-d\TH:i:s.u\Z'),'correlationId'=>substr((string)($in['correlationId']??$request->headers->get('X-Correlation-ID','')),0,128),'payload'=>$this->redactor->redact($payload)];
  try{$result=$this->repository->append($p->tenantId,$event);return new JsonResponse($result,$result['created']?201:200);}catch(\Throwable){return $this->error(503,'Audit storage unavailable','Retry using the same eventId.');}
 }
 #[Route('/api/v1/audit/events',methods:['GET'])]
 public function search(Request $request):JsonResponse{
  $p=$this->principal($request);if(!$p)return $this->error(401,'Unauthorized','A valid bearer token is required.');
  if(!$p->hasScope('audit:read'))return $this->error(403,'Forbidden','The token requires audit:read.');
  if(!$p->tenantId)return $this->error(403,'Tenant required','A tenant_id claim is required.');
  $page=filter_var($request->query->get('page',1),FILTER_VALIDATE_INT);$limit=filter_var($request->query->get('limit',25),FILTER_VALIDATE_INT);
  if($page===false||$page<1||$limit===false||$limit<1||$limit>100)return $this->error(400,'Invalid pagination','page must be positive and limit must be between 1 and 100.');
  $filters=[];foreach(['eventType','actorId','resourceType','resourceId','from','to'] as $k){$v=trim((string)$request->query->get($k,''));if($v!=='')$filters[$k]=$v;}
  try{$r=$this->repository->search($p->tenantId,$filters,$limit,($page-1)*$limit);return new JsonResponse(['data'=>$r['items'],'meta'=>['page'=>$page,'limit'=>$limit,'total'=>$r['total'],'pages'=>(int)ceil($r['total']/$limit)]]);}catch(\Throwable){return $this->error(503,'Audit storage unavailable','Audit search is temporarily unavailable.');}
 }
 #[Route('/api/v1/audit/events/{eventId}',methods:['GET'])]
 public function show(string $eventId,Request $request):JsonResponse{
  $p=$this->principal($request);if(!$p)return $this->error(401,'Unauthorized','A valid bearer token is required.');
  if(!$p->hasScope('audit:read'))return $this->error(403,'Forbidden','The token requires audit:read.');
  if(!$p->tenantId)return $this->error(403,'Tenant required','A tenant_id claim is required.');
  try{$event=$this->repository->find($p->tenantId,$eventId);}catch(\Throwable){return $this->error(503,'Audit storage unavailable','Audit lookup is temporarily unavailable.');}
  return $event?new JsonResponse(['data'=>$event]):$this->error(404,'Not found','The audit event does not exist in this tenant.');
 }
 private function principal(Request $r):?AuthenticatedPrincipal{$p=$r->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);return $p instanceof AuthenticatedPrincipal?$p:null;}
 private function error(int $s,string $t,string $d):JsonResponse{return new JsonResponse(['type'=>'about:blank','title'=>$t,'status'=>$s,'detail'=>$d],$s,['Content-Type'=>'application/problem+json']);}
}
