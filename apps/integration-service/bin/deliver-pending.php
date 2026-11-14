<?php
declare(strict_types=1);
function envv(string $key,string $default=''):string{return getenv($key)?:$default;}
$url=envv('INTEGRATION_WORKER_DATABASE_URL');$p=parse_url($url);if(!$p)throw new RuntimeException('INTEGRATION_WORKER_DATABASE_URL missing');
$db=new PDO('pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path'],'/'),rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$allowed=array_filter(array_map('trim',explode(',',envv('INTEGRATION_OUTBOUND_ALLOWED_HOSTS'))));$signing=envv('INTEGRATION_DELIVERY_SIGNING_SECRET');if(!$signing)throw new RuntimeException('INTEGRATION_DELIVERY_SIGNING_SECRET is required');
function publicIp(string $ip):bool{return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;}
function deliver(array $row,array $allowed,string $secret):array{
 $u=parse_url($row['target_url']);$host=strtolower((string)($u['host']??''));if(($u['scheme']??'')!=='https'||!in_array($host,$allowed,true))return [false,'Target host is not allowlisted.'];
 $ips=gethostbynamel($host)?:[];if(!$ips) return [false,'Target host did not resolve.'];foreach($ips as $ip)if(!publicIp($ip))return [false,'Target host resolved to a non-public address.'];
 $ip=$ips[0];$body=json_encode(['id'=>$row['id'],'type'=>$row['event_type'],'tenantId'=>$row['tenant_id'],'payload'=>json_decode($row['payload'],true)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $ch=curl_init($row['target_url']);$port=(int)($u['port']??443);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Webhook-Signature: sha256='.hash_hmac('sha256',$body,$secret),'X-Autoflow-Delivery-Id: '.$row['id']],CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip]]);
 $response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($response!==false&&$status>=200&&$status<300)return [true,null];return [false,$response===false?$error:'Remote endpoint returned HTTP '.$status];
}
$interval=max(1,(int)envv('INTEGRATION_WORKER_INTERVAL_SECONDS','3'));$batch=max(1,min(50,(int)envv('INTEGRATION_WORKER_BATCH_SIZE','10')));
fwrite(STDOUT,"Integration delivery worker started.".PHP_EOL);
while(true){
 try{
  $db->beginTransaction();$rows=$db->query("SELECT id,tenant_id,provider,target_url,event_type,payload,attempts FROM integration_service.delivery_attempts WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=now()) ORDER BY created_at LIMIT ".$batch." FOR UPDATE SKIP LOCKED")->fetchAll();
  foreach($rows as $row)$db->prepare("UPDATE integration_service.delivery_attempts SET attempts=attempts+1,updated_at=now() WHERE id=:id")->execute(['id'=>$row['id']]);$db->commit();
  foreach($rows as $row){[$ok,$error]=deliver($row,$allowed,$signing);$attempt=(int)$row['attempts']+1;
   if($ok)$q=$db->prepare("UPDATE integration_service.delivery_attempts SET status='delivered',last_error=NULL,next_attempt_at=NULL,updated_at=now() WHERE id=:id");
   elseif($attempt>=5)$q=$db->prepare("UPDATE integration_service.delivery_attempts SET status='failed',last_error=:error,next_attempt_at=NULL,updated_at=now() WHERE id=:id");
   else{$delay=min(300,5*(2**($attempt-1)));$q=$db->prepare("UPDATE integration_service.delivery_attempts SET status='pending',last_error=:error,next_attempt_at=now()+(:delay||' seconds')::interval,updated_at=now() WHERE id=:id");$q->bindValue(':delay',$delay,PDO::PARAM_INT);}
   $q->bindValue(':id',$row['id']);if(!$ok)$q->bindValue(':error',substr((string)$error,0,500));$q->execute();
  }
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,'Delivery worker error: '.$e->getMessage().PHP_EOL);}
 sleep($interval);
}
