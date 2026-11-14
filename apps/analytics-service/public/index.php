<?php
declare(strict_types=1);

function jsonOut(array $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit; }
function problem(int $status,string $title,string $detail): never { jsonOut(['type'=>'about:blank','title'=>$title,'status'=>$status,'detail'=>$detail],$status); }
function db(): PDO { static $pdo=null; if($pdo instanceof PDO)return $pdo; $url=getenv('ANALYTICS_DATABASE_URL')?:getenv('DATABASE_URL'); if(!$url)throw new RuntimeException('ANALYTICS_DATABASE_URL is not configured'); $p=parse_url($url); if(!$p||!isset($p['host'],$p['path']))throw new RuntimeException('Invalid database URL'); $dsn='pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path'],'/'); $pdo=new PDO($dsn,rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); return $pdo; }
function principal(): array {
 $h=$_SERVER['HTTP_AUTHORIZATION']??''; if(!preg_match('/^Bearer\s+(.+)$/i',$h,$m))problem(401,'Unauthorized','A bearer access token is required.');
 $parts=explode('.',$m[1]); if(count($parts)!==3)problem(401,'Unauthorized','Malformed access token.');
 $decode=static function(string $v){$x=base64_decode(strtr($v,'-_','+/').str_repeat('=',(4-strlen($v)%4)%4),true);return $x===false?null:$x;};
 $head=json_decode((string)$decode($parts[0]),true);$claims=json_decode((string)$decode($parts[1]),true);
 if(!is_array($head)||!is_array($claims)||($head['alg']??'')!=='RS256')problem(401,'Unauthorized','Unsupported access token.');
 $jwksUrl=getenv('AUTOFLOW_JWT_JWKS_URI')?:''; $issuer=getenv('AUTOFLOW_JWT_ISSUER')?:'';
 if($jwksUrl===''||$issuer==='')problem(503,'Authentication unavailable','JWT verification is not configured.');
 $raw=@file_get_contents($jwksUrl,false,stream_context_create(['http'=>['timeout'=>3]]));$jwks=$raw?json_decode($raw,true):null;
 if(!is_array($jwks['keys']??null))problem(503,'Authentication unavailable','Unable to load signing keys.');
 $key=null;foreach($jwks['keys'] as $candidate)if(($candidate['kid']??null)===($head['kid']??null)&&isset($candidate['x5c'][0])){$key="-----BEGIN CERTIFICATE-----\n".chunk_split($candidate['x5c'][0],64,"\n")."-----END CERTIFICATE-----\n";break;}
 $sig=$decode($parts[2]);$pub=$key?openssl_pkey_get_public($key):false;
 if(!$pub||$sig===null||openssl_verify($parts[0].'.'.$parts[1],$sig,$pub,OPENSSL_ALGO_SHA256)!==1)problem(401,'Unauthorized','Invalid token signature.');
 $aud=$claims['aud']??[];$aud=is_array($aud)?$aud:[$aud];$expected=getenv('ANALYTICS_JWT_AUDIENCE')?:'autoflow-analytics';
 if(($claims['iss']??'')!==$issuer||!in_array($expected,$aud,true)||($claims['exp']??0)<time()||($claims['nbf']??0)>time())problem(401,'Unauthorized','Token issuer, audience or lifetime is invalid.');
 $tenant=(string)($claims['tenant_id']??$claims['tenantId']??'');if($tenant===''||strlen($tenant)>128)problem(403,'Forbidden','A valid tenant claim is required.');
 $roles=$claims['realm_access']['roles']??[];$scopes=preg_split('/\s+/',(string)($claims['scope']??''),-1,PREG_SPLIT_NO_EMPTY);return ['tenant'=>$tenant,'scopes'=>array_unique(array_merge($scopes,is_array($roles)?$roles:[]))];
}
function permit(array $u,string $scope): void { if(!in_array($scope,$u['scopes'],true))problem(403,'Forbidden','Missing permission: '.$scope); }
function tenantScope(array $u): void { $q=db()->prepare("SELECT set_config('app.tenant_id',:tenant,false)"); $q->execute(['tenant'=>$u['tenant']]); }
function body(): array { $v=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($v))problem(400,'Bad Request','Expected a JSON object.');return $v; }
function canonicalType(string $type): string {
 $key=strtolower(preg_replace('/(?<!^)[A-Z]/','.$0',$type)??$type);
 $aliases=['offer.created'=>'offer.created','offercreated'=>'offer.created','offer.published'=>'offer.published','offerpublished'=>'offer.published','offer.accepted'=>'offer.accepted','offeraccepted'=>'offer.accepted','offer.expired'=>'offer.expired','offerexpired'=>'offer.expired','payment.captured'=>'payment.succeeded','paymentcaptured'=>'payment.succeeded','payment.succeeded'=>'payment.succeeded','payment.failed'=>'payment.failed','paymentfailed'=>'payment.failed','payment.refunded'=>'payment.refunded','paymentrefunded'=>'payment.refunded','payment.authorized'=>'payment.authorized','paymentauthorized'=>'payment.authorized','payment.cancelled'=>'payment.cancelled','paymentcanceled'=>'payment.cancelled','paymentcancelled'=>'payment.cancelled'];
 $compact=str_replace(['.','_','-'],'',$key);
 return $aliases[$key]??$aliases[$compact]??$key;
}
function safePayload(array $v): array { $out=[]; foreach($v as $k=>$value){ if(preg_match('/password|secret|token|authorization|email|phone|address|iban|card|ssn|birth|first.?name|last.?name/i',(string)$k))continue; $out[$k]=is_array($value)?safePayload($value):(is_scalar($value)||$value===null?$value:null); } return $out; }
try {
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($path==='/health'||$path==='/health/live')jsonOut(['service'=>'analytics-service','status'=>'ok']);
 if($path==='/health/ready'){db()->query('SELECT 1');jsonOut(['service'=>'analytics-service','status'=>'ok','checks'=>['postgres'=>'ok']]);}
 $u=principal(); tenantScope($u);
 if($path==='/api/v1/analytics/events'&&$method==='POST'){
  permit($u,'analytics:events:write');$b=body();$id=trim((string)($b['eventId']??''));$type=trim((string)($b['eventType']??''));$date=(string)($b['occurredAt']??gmdate(DATE_ATOM));$payload=$b['payload']??[];
  if($id===''||strlen($id)>200||!preg_match('/^[a-zA-Z0-9._:-]+$/',$type)||!is_array($payload)||strlen(json_encode($payload))>16384)problem(422,'Validation Failed','eventId, eventType and payload are invalid.'); $type=canonicalType($type);
  try{$q=db()->prepare('INSERT INTO analytics_service.events(tenant_id,event_id,event_type,occurred_at,payload) VALUES(:t,:i,:e,:d,:p) ON CONFLICT(tenant_id,event_id) DO NOTHING');$q->execute(['t'=>$u['tenant'],'i'=>$id,'e'=>$type,'d'=>$date,'p'=>json_encode(safePayload($payload),JSON_THROW_ON_ERROR)]);jsonOut(['accepted'=>true,'created'=>$q->rowCount()===1],$q->rowCount()===1?201:200);}catch(Throwable $e){problem(422,'Invalid event','The event could not be persisted.');}
 }
 if($path==='/api/v1/analytics/overview'&&$method==='GET'){
  permit($u,'analytics:read');$days=max(1,min(365,(int)($_GET['days']??30)));
  $q=db()->prepare("SELECT event_type,COUNT(*)::int AS total,MAX(occurred_at) AS last_occurred_at FROM analytics_service.events WHERE tenant_id=:t AND occurred_at>=now()-(:days||' days')::interval GROUP BY event_type ORDER BY event_type");$q->execute(['t'=>$u['tenant'],'days'=>$days]);$rows=$q->fetchAll();$counts=[];foreach($rows as $r)$counts[$r['event_type']]=(int)$r['total'];
  $created=$counts['offer.created']??0;$accepted=$counts['offer.accepted']??0;$payments=$counts['payment.succeeded']??0;
  jsonOut(['data'=>['periodDays'=>$days,'eventsByType'=>$counts,'offersCreated'=>$created,'offersAccepted'=>$accepted,'offerConversionRate'=>$created?round($accepted/$created*100,2):0,'successfulPayments'=>$payments,'lastEventAt'=>array_reduce($rows,fn($c,$r)=>max($c,strtotime($r['last_occurred_at'])),0)?gmdate(DATE_ATOM,array_reduce($rows,fn($c,$r)=>max($c,strtotime($r['last_occurred_at'])),0)):null]]);
 }
 if($path==='/api/v1/analytics/series'&&$method==='GET'){
  permit($u,'analytics:read');$days=max(1,min(90,(int)($_GET['days']??30)));$type=canonicalType(trim((string)($_GET['eventType']??'offer.created')));
  $q=db()->prepare("SELECT to_char(date_trunc('day',occurred_at),'YYYY-MM-DD') AS day,COUNT(*)::int AS value FROM analytics_service.events WHERE tenant_id=:t AND event_type=:e AND occurred_at>=now()-(:days||' days')::interval GROUP BY 1 ORDER BY 1");$q->execute(['t'=>$u['tenant'],'e'=>$type,'days'=>$days]);jsonOut(['data'=>$q->fetchAll()]);
 }
 if($path==='/api/v1/analytics/export.csv'&&$method==='GET'){
  permit($u,'analytics:read');$q=db()->prepare('SELECT event_type,occurred_at,event_id FROM analytics_service.events WHERE tenant_id=:t ORDER BY occurred_at DESC LIMIT 10000');$q->execute(['t'=>$u['tenant']]);header('Content-Type: text/csv');header('Content-Disposition: attachment; filename="analytics-events.csv"');$o=fopen('php://output','w');fputcsv($o,['eventType','occurredAt','eventId']);while($r=$q->fetch())fputcsv($o,[$r['event_type'],$r['occurred_at'],$r['event_id']]);fclose($o);exit;
 }
 problem(404,'Not Found','No analytics route matches this request.');
} catch(Throwable $e){error_log('analytics-service: '.$e->getMessage());problem(503,'Service Unavailable','The analytics service could not complete the request.');}
