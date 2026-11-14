<?php
declare(strict_types=1);

$url=parse_url(getenv('DOCUMENT_DATABASE_URL')?:'');
if(!is_array($url)||empty($url['host'])||empty($url['path'])){fwrite(STDERR,"DOCUMENT_DATABASE_URL is required\n");exit(1);}
$pdo=new PDO('pgsql:host='.$url['host'].';port='.($url['port']??5432).';dbname='.ltrim($url['path'],'/'),rawurldecode($url['user']??''),rawurldecode($url['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET search_path TO document_service,public');
$base=rtrim(getenv('RABBITMQ_MANAGEMENT_URL')?:'http://rabbitmq:15672','/');
$user=getenv('RABBITMQ_USER')?:'autoflow';$pass=getenv('RABBITMQ_PASSWORD')?:'autoflow';
$exchange=getenv('DOCUMENT_EVENTS_EXCHANGE')?:'autoflow.document.events';
$interval=max(1,(int)(getenv('DOCUMENT_OUTBOX_INTERVAL_SECONDS')?:2));
$publish=function(string $method,string $path,?array $body=null)use($base,$user,$pass):array{
    $headers="Authorization: Basic ".base64_encode($user.':'.$pass)."\r\nAccept: application/json\r\n";
    if($body!==null)$headers.="Content-Type: application/json\r\n";
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'timeout'=>5,'ignore_errors'=>true]]);
    $response=@file_get_contents($base.$path,false,$context);$status=$http_response_header[0]??'';
    if(!preg_match('/\s2\d\d\s/',$status))throw new RuntimeException('RabbitMQ management API returned '.$status);
    return is_string($response)&&$response!==''?(json_decode($response,true)?:[]):[];
};
$publish('PUT','/api/exchanges/%2F/'.rawurlencode($exchange),['type'=>'topic','durable'=>true,'auto_delete'=>false,'internal'=>false,'arguments'=>new stdClass()]);
$queue='autoflow.document.events';
$publish('PUT','/api/queues/%2F/'.rawurlencode($queue),['durable'=>true,'auto_delete'=>false,'arguments'=>new stdClass()]);
$publish('POST','/api/bindings/%2F/e/'.rawurlencode($exchange).'/q/'.rawurlencode($queue),['routing_key'=>'#','arguments'=>new stdClass()]);
fwrite(STDOUT,"Document outbox publisher started; exchange={$exchange}\n");
while(true){
    $pdo->beginTransaction();
    try{
        $rows=$pdo->query("SELECT id,tenant_id,aggregate_id,event_type,payload,occurred_at FROM document_outbox_events WHERE published_at IS NULL AND attempts < 20 ORDER BY occurred_at LIMIT 25 FOR UPDATE SKIP LOCKED")->fetchAll();
        if($rows===[]){$pdo->commit();sleep($interval);continue;}
        foreach($rows as $row){
            try{
                $envelope=['eventId'=>$row['id'],'eventType'=>$row['event_type'],'tenantId'=>$row['tenant_id'],'aggregateId'=>$row['aggregate_id'],'occurredAt'=>$row['occurred_at'],'payload'=>json_decode($row['payload'],true)];
                $result=$publish('POST','/api/exchanges/%2F/'.rawurlencode($exchange).'/publish',['properties'=>['delivery_mode'=>2,'content_type'=>'application/json','message_id'=>$row['id'],'headers'=>['tenant_id'=>$row['tenant_id']]],'routing_key'=>$row['event_type'],'payload'=>json_encode($envelope,JSON_THROW_ON_ERROR),'payload_encoding'=>'string','mandatory'=>true]);
                if(($result['routed']??false)!==true)throw new RuntimeException('Message was not routed to a queue.');
                $q=$pdo->prepare('UPDATE document_outbox_events SET published_at=NOW(),attempts=attempts+1,last_error=NULL WHERE id=:id');$q->execute(['id'=>$row['id']]);
            }catch(Throwable $e){
                $q=$pdo->prepare('UPDATE document_outbox_events SET attempts=attempts+1,last_error=:error WHERE id=:id');$q->execute(['id'=>$row['id'],'error'=>substr($e->getMessage(),0,1000)]);
                fwrite(STDERR,"Publish failed for {$row['id']}: {$e->getMessage()}\n");
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Outbox batch failed: {$e->getMessage()}\n");sleep($interval);}
}
