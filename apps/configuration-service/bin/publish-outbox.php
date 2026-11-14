<?php
declare(strict_types=1);
function envv(string $key,string $default): string { $v=getenv($key); return $v===false||$v===''?$default:$v; }
function db(): PDO {
 $u=parse_url(envv('DATABASE_URL','postgresql://autoflow_configuration:local-only-configuration-password@postgres:5432/autoflow'));
 if(!$u||empty($u['host'])||empty($u['path'])) throw new RuntimeException('Invalid DATABASE_URL');
 return new PDO('pgsql:host='.$u['host'].';port='.($u['port']??5432).';dbname='.ltrim($u['path'],'/'),rawurldecode($u['user']??''),rawurldecode($u['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function rabbit(string $method,string $path,?array $data=null): array {
 $headers="Authorization: Basic ".base64_encode(envv('RABBITMQ_USER','autoflow').':'.envv('RABBITMQ_PASSWORD','autoflow'))."\r\nAccept: application/json\r\n"; $body='';
 if($data!==null){$headers.="Content-Type: application/json\r\n";$body=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]);
 $response=@file_get_contents(rtrim(envv('RABBITMQ_MANAGEMENT_URL','http://rabbitmq:15672'),'/').$path,false,$ctx);
 preg_match('/\s(\d{3})\s/', $http_response_header[0]??'', $m); $status=(int)($m[1]??0);
 if($response===false||$status<200||$status>=300) throw new RuntimeException("RabbitMQ HTTP {$status}: ".substr((string)$response,0,500));
 return $response===''?[]:(json_decode($response,true)??[]);
}
function topology(): void {
 rabbit('PUT','/api/exchanges/%2F/autoflow.configuration',['type'=>'topic','durable'=>true,'auto_delete'=>false,'internal'=>false,'arguments'=>(object)[]]);
 rabbit('PUT','/api/queues/%2F/configuration.events',['durable'=>true,'auto_delete'=>false,'arguments'=>(object)[]]);
 rabbit('POST','/api/bindings/%2F/e/autoflow.configuration/q/configuration.events',['routing_key'=>'configuration.#','arguments'=>(object)[]]);
}
function publish(array $e): void {
 $payload=json_decode($e['payload'],true,512,JSON_THROW_ON_ERROR); $payload['eventId']=$e['id']; $payload['occurredAt']=$e['occurred_at'];
 $key=$e['event_type']==='FeatureFlagChanged'?'configuration.feature_flag_changed':'configuration.updated';
 $r=rabbit('POST','/api/exchanges/%2F/autoflow.configuration/publish',['properties'=>['delivery_mode'=>2,'content_type'=>'application/json','message_id'=>$e['id'],'type'=>$e['event_type'],'timestamp'=>time()],'routing_key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'payload_encoding'=>'string']);
 if(($r['routed']??false)!==true) throw new RuntimeException('RabbitMQ did not route event.');
}
$pdo=db(); $poll=max(1,min(30,(int)envv('OUTBOX_POLL_INTERVAL_SECONDS','2'))); $batch=max(1,min(200,(int)envv('OUTBOX_BATCH_SIZE','50')));
topology(); fwrite(STDOUT,"Configuration outbox publisher started\n");
while(true){
 try{
  $pdo->beginTransaction();
  $q=$pdo->prepare("SELECT id::text AS id,event_type,payload::text AS payload,occurred_at FROM configuration_service.configuration_outbox_events WHERE published_at IS NULL AND next_attempt_at<=NOW() ORDER BY occurred_at,id LIMIT :n FOR UPDATE SKIP LOCKED");
  $q->bindValue(':n',$batch,PDO::PARAM_INT);$q->execute();$events=$q->fetchAll();
  foreach($events as $e){
   try{publish($e);$u=$pdo->prepare('UPDATE configuration_service.configuration_outbox_events SET published_at=NOW(),attempts=attempts+1,last_error=NULL WHERE id=?');$u->execute([$e['id']]);fwrite(STDOUT,"Published {$e['id']}\n");}
   catch(Throwable $x){$u=$pdo->prepare("UPDATE configuration_service.configuration_outbox_events SET attempts=attempts+1,last_error=?,next_attempt_at=NOW()+(LEAST(POWER(2,LEAST(attempts,8)),300)::text||' seconds')::interval WHERE id=?");$u->execute([substr($x->getMessage(),0,4000),$e['id']]);fwrite(STDERR,"Publish failed {$e['id']}: {$x->getMessage()}\n");}
  }
  $pdo->commit();if(!$events)sleep($poll);
 }catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Outbox poll failed: {$x->getMessage()}\n");sleep($poll);}
}
