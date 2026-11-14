<?php
declare(strict_types=1);
namespace App\Infrastructure\Messaging;
use App\Application\AuditEventNormalizer;
use App\Application\AuditPayloadRedactor;
use App\Infrastructure\Audit\AuditRepository;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;
final readonly class AuditEventConsumer
{
    private const SOURCE_EXCHANGES=['autoflow.events','autoflow.order.events','autoflow.payment.events','autoflow.contract.events','autoflow.fulfillment.events','autoflow.crm.events','autoflow.catalog.events','autoflow.inventory.events','autoflow.vehicle-reservation.events','autoflow.document.events','autoflow.configuration'];
    public function __construct(private AMQPChannel $channel,private AuditRepository $repository,private AuditEventNormalizer $normalizer,private AuditPayloadRedactor $redactor,private int $maxAttempts=5){}
    public function run():never
    {
        foreach(self::SOURCE_EXCHANGES as $exchange)$this->channel->exchange_declare($exchange,'topic',false,true,false);
        $this->channel->exchange_declare('autoflow.audit.events','topic',false,true,false);
        $this->channel->exchange_declare('autoflow.audit.retry','topic',false,true,false);
        $this->channel->exchange_declare('autoflow.audit.dlx','direct',false,true,false);
        $this->channel->queue_declare('audit.events.retry',false,true,false,false,false,new AMQPTable(['x-message-ttl'=>30000,'x-dead-letter-exchange'=>'autoflow.audit.events']));
        $this->channel->queue_bind('audit.events.retry','autoflow.audit.retry','#');
        $this->channel->queue_declare('audit.events.failed',false,true,false,false);
        $this->channel->queue_bind('audit.events.failed','autoflow.audit.dlx','audit.failed');
        $this->channel->queue_declare('audit.events',false,true,false,false,false,new AMQPTable(['x-dead-letter-exchange'=>'autoflow.audit.dlx','x-dead-letter-routing-key'=>'audit.failed']));
        foreach(self::SOURCE_EXCHANGES as $exchange)$this->channel->queue_bind('audit.events',$exchange,'#');
        $this->channel->queue_bind('audit.events','autoflow.audit.events','#');
        $this->channel->confirm_select();
        $this->channel->basic_qos(0,10,false);
        $this->channel->basic_consume('audit.events','',false,false,false,false,function(AMQPMessage $message):void{
            $attempt=0;$headers=[];
            try{
                $table=$message->get('application_headers');
                if($table instanceof AMQPTable){$headers=$table->getNativeData();$attempt=(int)($headers['x-autoflow-audit-attempt']??0);}
                $body=json_decode($message->getBody(),true,512,JSON_THROW_ON_ERROR);
                if(!is_array($body)||array_is_list($body))throw new \InvalidArgumentException('Integration event body must be a JSON object.');
                $normalized=$this->normalizer->normalize($body,$headers);
                $normalized['event']['payload']=$this->redactor->redact($normalized['event']['payload']);
                $this->repository->append($normalized['tenantId'],$normalized['event']);
                $message->ack();
            }catch(\InvalidArgumentException|\JsonException $e){
                $this->publishDeadLetter($message,$e->getMessage(),$attempt);
                $message->ack();
            }catch(Throwable $e){
                $next=$attempt+1;
                if($next>=$this->maxAttempts)$this->publishDeadLetter($message,$e->getMessage(),$next);
                else{
                    $headers['x-autoflow-audit-attempt']=$next;
                    $retry=new AMQPMessage($message->getBody(),['content_type'=>'application/json','delivery_mode'=>AMQPMessage::DELIVERY_MODE_PERSISTENT,'message_id'=>$message->get('message_id')?:null,'type'=>$message->get('type')?:null,'application_headers'=>new AMQPTable($headers)]);
                    $this->channel->basic_publish($retry,'autoflow.audit.retry',(string)$message->get('type'));
                    $this->channel->wait_for_pending_acks_returns(5.0);
                }
                $message->ack();
                error_log('[Audit Consumer] Processing failure; attempt '.$next.': '.$e->getMessage());
            }
        });
        error_log('[Audit Consumer] Started; listening to domain outbox exchanges.');
        while($this->channel->is_consuming()){try{$this->channel->wait(null,false,5);}catch(AMQPTimeoutException){}}
        throw new \RuntimeException('Audit RabbitMQ consumer stopped unexpectedly.');
    }
    private function publishDeadLetter(AMQPMessage $original,string $reason,int $attempt):void
    {
        $headers=[];$table=$original->get('application_headers');if($table instanceof AMQPTable)$headers=$table->getNativeData();
        $headers['x-autoflow-audit-error']=mb_substr($reason,0,500);$headers['x-autoflow-audit-attempt']=$attempt;
        $message=new AMQPMessage($original->getBody(),['content_type'=>'application/json','delivery_mode'=>AMQPMessage::DELIVERY_MODE_PERSISTENT,'message_id'=>$original->get('message_id')?:null,'type'=>$original->get('type')?:null,'application_headers'=>new AMQPTable($headers)]);
        $this->channel->basic_publish($message,'autoflow.audit.dlx','audit.failed');
        $this->channel->wait_for_pending_acks_returns(5.0);
    }
}
