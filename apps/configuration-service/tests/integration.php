<?php
declare(strict_types=1);
$base=rtrim(getenv('CONFIGURATION_BASE_URL')?:'http://configuration-service:8080','/');$tenant='cfg-test-'.bin2hex(random_bytes(5));$actor='integration-test';$checks=0;
function ok(bool $v,string $s):void{global $checks;$checks++;if(!$v)throw new RuntimeException('FAIL '.$s);echo "[PASS] {$s}\n";}
function api(string $m,string $p,?array $data=null,?string $tenantId=null,?string $ifMatch=null):array{global $base,$tenant,$actor;$h="Accept: application/json\r\nX-Tenant-Id: ".($tenantId??$tenant)."\r\nX-Actor-Id: {$actor}\r\n";if($data!==null)$h.="Content-Type: application/json\r\n";if($ifMatch!==null)$h.="If-Match: {$ifMatch}\r\n";$c=stream_context_create(['http'=>['method'=>$m,'header'=>$h,'content'=>$data===null?'':json_encode($data,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>10]]);$raw=@file_get_contents($base.$p,false,$c);preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$x);return ['status'=>(int)($x[1]??0),'body'=>json_decode((string)$raw,true)];}
try{
 ok(api('GET','/health/ready')['status']===200,'readiness');
 $n='integration';$k='typed-value';
 $r=api('PUT',"/api/configurations/$n/$k",['value'=>['enabled'=>true,'emptyObject'=>(object)[]],'expectedVersion'=>0]);ok($r['status']===201,'create configuration');
 $r=api('GET',"/api/configurations/$n/$k");ok($r['status']===200&&($r['body']['value']['enabled']??false),'read configuration');
 ok(api('GET',"/api/configurations/$n/$k",null,'other-tenant')['status']===404,'tenant isolation');
 ok(api('PUT',"/api/configurations/$n/$k",['value'=>['enabled'=>false],'expectedVersion'=>1])['status']===200,'versioned update');
 ok(api('PUT',"/api/configurations/$n/$k",['value'=>'stale','expectedVersion'=>1])['status']===412,'stale version rejected');
 ok(api('PUT',"/api/configurations/$n/$k",['value'=>'if-match'],null,'"2"')['status']===200,'If-Match header accepted');
 $r=api('GET',"/api/configurations/$n/$k/versions");ok($r['status']===200&&count($r['body']['data'])===3,'version history');
 $r=api('POST',"/api/configurations/$n/$k/rollback",['version'=>1]);ok($r['status']===200&&$r['body']['version']===4,'rollback creates new version');
 ok(api('PUT','/api/feature-flags/integration-flag',['enabled'=>true,'rolloutPercentage'=>100,'expectedVersion'=>0])['status']===201,'create feature flag');
 $r=api('GET','/api/feature-flags/integration-flag/evaluate');ok($r['status']===200&&$r['body']['enabled']===true,'evaluate enabled flag');
 ok(api('PUT','/api/feature-flags/integration-flag',['enabled'=>false,'rolloutPercentage'=>0,'expectedVersion'=>1])['status']===200,'update feature flag');
 $r=api('GET','/api/feature-flags/integration-flag/evaluate');ok($r['status']===200&&$r['body']['enabled']===false,'disabled flag stays disabled');
 $r=api('GET','/api/audit-events?limit=100');ok($r['status']===200&&count($r['body']['data'])>=6,'audit history');
 $u=parse_url(getenv('DATABASE_URL')?:'postgresql://autoflow_configuration:local-only-configuration-password@postgres:5432/autoflow');$pdo=new PDO('pgsql:host='.$u['host'].';port='.($u['port']??5432).';dbname='.ltrim($u['path'],'/'),rawurldecode($u['user']??''),rawurldecode($u['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $end=microtime(true)+25;$published=0;do{$q=$pdo->prepare('SELECT COUNT(*) FROM configuration_service.configuration_outbox_events WHERE tenant_id=? AND published_at IS NOT NULL');$q->execute([$tenant]);$published=(int)$q->fetchColumn();if($published>=6)break;usleep(250000);}while(microtime(true)<$end);
 ok($published>=6,'outbox events published to RabbitMQ');
 echo "\nAll {$checks} integration checks passed.\n";exit(0);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
