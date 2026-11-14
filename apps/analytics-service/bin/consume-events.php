<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
$host=getenv('RABBITMQ_HOST')?:'rabbitmq';$port=(int)(getenv('RABBITMQ_PORT')?:5672);$user=getenv('RABBITMQ_USER')?:'autoflow';$pass=getenv('RABBITMQ_PASSWORD')?:'autoflow';$vhost=getenv('RABBITMQ_VHOST')?:'/';
$dbUrl=getenv('ANALYTICS_DATABASE_URL')?:'';$p=parse_url($dbUrl);if(!$p)throw new RuntimeException('ANALYTICS_DATABASE_URL is required');
$pdo=new PDO('pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path'],'/'),rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function canonicalType(string $type): string {
 $key=strtolower(preg_replace('/(?<!^)[A-Z]/','.$0',$type)??$type);
 $aliases=['offer.created'=>'offer.created','offercreated'=>'offer.created','offer.published'=>'offer.published','offerpublished'=>'offer.published','offer.accepted'=>'offer.accepted','offeraccepted'=>'offer.accepted','offer.expired'=>'offer.expired','offerexpired'=>'offer.expired','payment.captured'=>'payment.succeeded','paymentcaptured'=>'payment.succeeded','payment.succeeded'=>'payment.succeeded','payment.failed'=>'payment.failed','paymentfailed'=>'payment.failed','payment.refunded'=>'payment.refunded','paymentrefunded'=>'payment.refunded','payment.authorized'=>'payment.authorized','paymentauthorized'=>'payment.authorized','payment.cancelled'=>'payment.cancelled','paymentcanceled'=>'payment.cancelled','paymentcancelled'=>'payment.cancelled'];
 $compact=str_replace(['.','_','-'],'',$key);
 return $aliases[$key]??$aliases[$compact]??$key;
}
function safePayload(array $v):array{$out=[];foreach($v as $k=>$value){if(preg_match('/password|secret|token|authorization|email|phone|address|iban|card|ssn|birth|first.?name|last.?name/i',(string)$k))continue;$out[$k]=is_array($value)?safePayload($value):(is_scalar($value)||$value===null?$value:null);}return $out;}
$conn=new AMQPStreamConnection($host,$port,$user,$pass,$vhost);$ch=$conn->channel();
$exchanges=['autoflow.events','autoflow.order.events','autoflow.payment.events','autoflow.contract.events','autoflow.fulfillment.events','autoflow.crm.events','autoflow.catalog.events','autoflow.inventory.events','autoflow.vehicle-reservation.events','autoflow.document.events','autoflow.configuration'];
foreach($exchanges as $exchange)$ch->exchange_declare($exchange,'topic',false,true,false);
$ch->exchange_declare('autoflow.analytics.dlx','direct',false,true,false);
$ch->exchange_declare('autoflow.analytics.retry.dlx','direct',false,true,false);
$args=new PhpAmqpLib\Wire\AMQPTable(['x-dead-letter-exchange'=>'autoflow.analytics.dlx','x-dead-letter-routing-key'=>'failed']);
[$queue]=$ch->queue_declare('analytics.events',false,true,false,false,false,$args);
$ch->queue_declare('analytics.events.failed',false,true,false,false);
$ch->queue_bind('analytics.events.failed','autoflow.analytics.dlx','failed');
$retryArgs=new PhpAmqpLib\Wire\AMQPTable(['x-message-ttl'=>5000,'x-dead-letter-exchange'=>'autoflow.analytics.retry.dlx','x-dead-letter-routing-key'=>'retry']);
$ch->queue_declare('analytics.events.retry',false,true,false,false,false,$retryArgs);
$ch->queue_bind($queue,'autoflow.analytics.retry.dlx','retry');
foreach($exchanges as $exchange)$ch->queue_bind($queue,$exchange,'#');
$ch->basic_qos(null,25,null);
$ch->basic_consume($queue,'analytics-projection-worker',false,false,false,false,function(AMQPMessage $msg)use($pdo,$ch){
 try{$e=json_decode($msg->getBody(),true,512,JSON_THROW_ON_ERROR);$id=(string)($e['eventId']??$e['event_id']??$msg->get('message_id')??'');$type=canonicalType((string)($e['eventType']??$e['event_type']??''));$headers=$msg->get('application_headers');$headerData=is_object($headers)&&method_exists($headers,'getNativeData')?$headers->getNativeData():[];$tenant=(string)($e['tenantId']??$e['tenant_id']??$headerData['tenant_id']??'');$date=(string)($e['occurredAt']??$e['occurred_at']??gmdate(DATE_ATOM));$payload=safePayload(is_array($e['payload']??null)?$e['payload']:$e);
 if($id===''||$type===''||$tenant===''||!is_array($payload))throw new UnexpectedValueException('Missing event identity, type, tenant or payload');
 $pdo->prepare("SELECT set_config('app.tenant_id',:tenant,false)")->execute(['tenant'=>$tenant]);
 $q=$pdo->prepare('INSERT INTO analytics_service.events(tenant_id,event_id,event_type,occurred_at,payload) VALUES(:t,:i,:e,:d,:p) ON CONFLICT(tenant_id,event_id) DO NOTHING');
 $q->execute(['t'=>$tenant,'i'=>$id,'e'=>$type,'d'=>$date,'p'=>json_encode($payload,JSON_THROW_ON_ERROR)]);$msg->ack();
 }catch(UnexpectedValueException|JsonException $e){fwrite(STDERR,'Rejecting invalid analytics event: '.$e->getMessage().PHP_EOL);$msg->reject(false);}
 catch(Throwable $e){
  $headers=$msg->get('application_headers');
  $headerData=is_object($headers)&&method_exists($headers,'getNativeData')?$headers->getNativeData():[];
  $attempt=(int)($headerData['x-analytics-retry']??0);
  fwrite(STDERR,'Analytics projection failed (attempt '.($attempt+1).'): '.$e->getMessage().PHP_EOL);
  if($attempt<3){
   $retryHeaders=new PhpAmqpLib\Wire\AMQPTable(['x-analytics-retry'=>$attempt+1]);
   $retry=new AMQPMessage($msg->getBody(),['content_type'=>'application/json','delivery_mode'=>2,'application_headers'=>$retryHeaders]);
   $ch->basic_publish($retry,'','analytics.events.retry');
   $msg->ack();
  }else{$msg->reject(false);}
 }
});
fwrite(STDOUT,"Analytics event consumer started; queue={$queue}".PHP_EOL);
while($ch->is_consuming())$ch->wait();
