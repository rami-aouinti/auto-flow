<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
$host=getenv('RABBITMQ_HOST')?:'rabbitmq';$port=(int)(getenv('RABBITMQ_PORT')?:5672);$user=getenv('RABBITMQ_USER')?:'autoflow';$pass=getenv('RABBITMQ_PASSWORD')?:'autoflow';$vhost=getenv('RABBITMQ_VHOST')?:'/';
$dbUrl=getenv('ANALYTICS_DATABASE_URL')?:'';$p=parse_url($dbUrl);if(!$p)throw new RuntimeException('ANALYTICS_DATABASE_URL is required');
$pdo=new PDO('pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path'],'/'),rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$conn=new AMQPStreamConnection($host,$port,$user,$pass,$vhost);$ch=$conn->channel();
$exchanges=['autoflow.events','autoflow.order.events','autoflow.payment.events','autoflow.contract.events','autoflow.fulfillment.events','autoflow.crm.events','autoflow.catalog.events','autoflow.inventory.events','autoflow.vehicle-reservation.events','autoflow.document.events','autoflow.configuration'];
foreach($exchanges as $exchange)$ch->exchange_declare($exchange,'topic',false,true,false);
$ch->exchange_declare('autoflow.analytics.dlx','direct',false,true,false);
$args=new PhpAmqpLib\Wire\AMQPTable(['x-dead-letter-exchange'=>'autoflow.analytics.dlx','x-dead-letter-routing-key'=>'failed']);
[$queue]=$ch->queue_declare('analytics.events',false,true,false,false,false,$args);
$ch->queue_declare('analytics.events.failed',false,true,false,false);
$ch->queue_bind('analytics.events.failed','autoflow.analytics.dlx','failed');
foreach($exchanges as $exchange)$ch->queue_bind($queue,$exchange,'#');
$ch->basic_qos(null,25,null);
$ch->basic_consume($queue,'analytics-projection-worker',false,false,false,false,function(AMQPMessage $msg)use($pdo){
 try{$e=json_decode($msg->getBody(),true,512,JSON_THROW_ON_ERROR);$id=(string)($e['eventId']??$e['event_id']??$msg->get('message_id')??'');$type=(string)($e['eventType']??$e['event_type']??'');$tenant=(string)($e['tenantId']??$e['tenant_id']??$msg->get('application_headers')?->getNativeData()['tenant_id']??'');$date=(string)($e['occurredAt']??$e['occurred_at']??gmdate(DATE_ATOM));$payload=$e['payload']??$e;
 if($id===''||$type===''||$tenant===''||!is_array($payload))throw new UnexpectedValueException('Missing event identity, type, tenant or payload');
 $pdo->prepare("SELECT set_config('app.tenant_id',:tenant,false)")->execute(['tenant'=>$tenant]);
 $q=$pdo->prepare('INSERT INTO analytics_service.events(tenant_id,event_id,event_type,occurred_at,payload) VALUES(:t,:i,:e,:d,:p) ON CONFLICT(tenant_id,event_id) DO NOTHING');
 $q->execute(['t'=>$tenant,'i'=>$id,'e'=>$type,'d'=>$date,'p'=>json_encode($payload,JSON_THROW_ON_ERROR)]);$msg->ack();
 }catch(UnexpectedValueException|JsonException $e){fwrite(STDERR,'Rejecting invalid analytics event: '.$e->getMessage().PHP_EOL);$msg->reject(false);}
 catch(Throwable $e){fwrite(STDERR,'Analytics projection failed: '.$e->getMessage().PHP_EOL);$msg->nack(false,true);}
});
fwrite(STDOUT,"Analytics event consumer started; queue={$queue}".PHP_EOL);
while($ch->is_consuming())$ch->wait();
