<?php
declare(strict_types=1);
namespace App\Infrastructure\Audit;
use PDO;
use RuntimeException;
final class AuditRepository
{
 private ?PDO $pdo=null;
 public function __construct(private readonly string $databaseUrl){}
 private function connection():PDO{
  if($this->pdo!==null)return $this->pdo;$u=parse_url($this->databaseUrl);
  if(!is_array($u)||!isset($u['host'],$u['path']))throw new RuntimeException('AUDIT_DATABASE_URL is invalid.');
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s',$u['host'],$u['port']??5432,ltrim($u['path'],'/'));
  $this->pdo=new PDO($dsn,rawurldecode($u['user']??''),rawurldecode($u['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $this->pdo;
 }
 public function ping():bool{try{return (int)$this->connection()->query('SELECT 1')->fetchColumn()===1;}catch(\Throwable){return false;}}
 public function append(string $tenant,array $event):array{
  $pdo=$this->connection();$pdo->beginTransaction();
  try{
   $pdo->prepare("SELECT set_config('app.tenant_id',:tenant,true)")->execute(['tenant'=>$tenant]);
   $pdo->prepare('INSERT INTO audit_tenant_heads(tenant_id,last_hash,last_event_id) VALUES(:tenant,:hash,:id) ON CONFLICT(tenant_id) DO NOTHING')->execute(['tenant'=>$tenant,'hash'=>str_repeat('0',64),'id'=>'']);
   $head=$pdo->prepare('SELECT last_hash FROM audit_tenant_heads WHERE tenant_id=:tenant FOR UPDATE');$head->execute(['tenant'=>$tenant]);$previous=(string)$head->fetchColumn();
   $existing=$pdo->prepare('SELECT event_id,event_hash FROM audit_events WHERE tenant_id=:tenant AND event_id=:id');$existing->execute(['tenant'=>$tenant,'id'=>$event['eventId']]);
   if($row=$existing->fetch()){$pdo->commit();return ['created'=>false,'eventId'=>$row['event_id'],'eventHash'=>$row['event_hash']];}
   $canonical=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=hash('sha256',$previous."\n".$canonical);
   $s=$pdo->prepare('INSERT INTO audit_events(event_id,tenant_id,event_type,actor_type,actor_id,resource_type,resource_id,occurred_at,correlation_id,payload,previous_hash,event_hash) VALUES(:id,:tenant,:type,:actor_type,:actor_id,:resource_type,:resource_id,:occurred_at,:correlation_id,CAST(:payload AS jsonb),:previous_hash,:event_hash)');
   $s->execute(['id'=>$event['eventId'],'tenant'=>$tenant,'type'=>$event['eventType'],'actor_type'=>$event['actorType'],'actor_id'=>$event['actorId'],'resource_type'=>$event['resourceType'],'resource_id'=>$event['resourceId'],'occurred_at'=>$event['occurredAt'],'correlation_id'=>$event['correlationId'],'payload'=>json_encode($event['payload'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'previous_hash'=>$previous,'event_hash'=>$hash]);
   $s=$pdo->prepare('UPDATE audit_tenant_heads SET last_hash=:hash,last_event_id=:id WHERE tenant_id=:tenant');$s->execute(['tenant'=>$tenant,'hash'=>$hash,'id'=>$event['eventId']]);
   $pdo->commit();return ['created'=>true,'eventId'=>$event['eventId'],'eventHash'=>$hash];
  }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 public function find(string $tenant,string $id):?array{return $this->withTenant($tenant,function(PDO $p)use($tenant,$id){$s=$p->prepare('SELECT * FROM audit_events WHERE tenant_id=:tenant AND event_id=:id');$s->execute(['tenant'=>$tenant,'id'=>$id]);return $s->fetch()?:null;});}
 public function search(string $tenant,array $filters,int $limit,int $offset):array{return $this->withTenant($tenant,function(PDO $p)use($tenant,$filters,$limit,$offset){
  $where=['tenant_id=:tenant'];$params=['tenant'=>$tenant];foreach(['eventType'=>'event_type','actorId'=>'actor_id','resourceType'=>'resource_type','resourceId'=>'resource_id'] as $k=>$col)if(($filters[$k]??'')!==''){$where[]=$col.'=:'.$k;$params[$k]=$filters[$k];}
  foreach(['from'=>'>=','to'=>'<='] as $k=>$op)if(($filters[$k]??'')!==''){$where[]='occurred_at '.$op.' :'.$k;$params[$k]=$filters[$k];}
  $clause=implode(' AND ',$where);$s=$p->prepare('SELECT event_id,event_type,actor_type,actor_id,resource_type,resource_id,occurred_at,correlation_id,payload,previous_hash,event_hash,created_at FROM audit_events WHERE '.$clause.' ORDER BY occurred_at DESC,event_id DESC LIMIT '.(int)$limit.' OFFSET '.(int)$offset);$s->execute($params);$items=$s->fetchAll();$c=$p->prepare('SELECT count(*) FROM audit_events WHERE '.$clause);$c->execute($params);return ['items'=>$items,'total'=>(int)$c->fetchColumn()];
 });}
 private function withTenant(string $tenant,callable $fn):mixed{$p=$this->connection();$p->beginTransaction();try{$p->prepare("SELECT set_config('app.tenant_id',:tenant,true)")->execute(['tenant'=>$tenant]);$v=$fn($p);$p->commit();return $v;}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
}
