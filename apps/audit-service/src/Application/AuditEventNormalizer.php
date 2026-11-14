<?php
declare(strict_types=1);
namespace App\Application;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
final readonly class AuditEventNormalizer
{
    public function normalize(array $envelope, array $headers = []): array
    {
        $tenantId=$envelope['tenantId']??$envelope['tenant_id']??$headers['tenant_id']??null;
        $eventId=$envelope['eventId']??$envelope['event_id']??null;
        $eventType=$envelope['eventType']??$envelope['event_type']??null;
        if(!is_string($tenantId)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$tenantId)!==1) throw new InvalidArgumentException('Integration event is missing a valid tenantId.');
        if(!is_string($eventId)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D',$eventId)!==1) throw new InvalidArgumentException('Integration event is missing a valid eventId.');
        if(!is_string($eventType)||trim($eventType)===''||strlen($eventType)>120) throw new InvalidArgumentException('Integration event is missing a valid eventType.');
        $occurredAt=$envelope['occurredAt']??$envelope['occurred_at']??null;
        try{$date=is_string($occurredAt)&&$occurredAt!==''?new DateTimeImmutable($occurredAt):new DateTimeImmutable('now',new DateTimeZone('UTC'));}
        catch(\Throwable $e){throw new InvalidArgumentException('Integration event occurredAt is invalid.',previous:$e);}
        $payload=$envelope['payload']??[];
        if(!is_array($payload)) throw new InvalidArgumentException('Integration event payload must be a JSON object.');
        if(strlen(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>16384) throw new InvalidArgumentException('Integration event payload exceeds the 16 KiB limit.');
        $aggregateId=$envelope['aggregateId']??$envelope['aggregate_id']??$eventId;
        $resourceType=$envelope['resourceType']??$envelope['aggregateType']??$envelope['aggregate_type']??'domain_event';
        return ['tenantId'=>$tenantId,'event'=>[
            'eventId'=>$eventId,'eventType'=>$eventType,
            'actorType'=>in_array($envelope['actorType']??null,['user','service','system'],true)?$envelope['actorType']:'system',
            'actorId'=>mb_substr((string)($envelope['actorId']??$envelope['producer']??'autoflow'),0,255),
            'resourceType'=>is_string($resourceType)&&$resourceType!==''?mb_substr($resourceType,0,80):'domain_event',
            'resourceId'=>is_scalar($aggregateId)?mb_substr((string)$aggregateId,0,160):$eventId,
            'occurredAt'=>$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'correlationId'=>mb_substr((string)($envelope['correlationId']??$headers['correlation_id']??''),0,128),
            'payload'=>$payload,
        ]];
    }
}
