<?php
declare(strict_types=1);
namespace App\Application;
final class AuditPayloadRedactor
{
    private const BLOCKED_KEYS = ['password','passwd','token','access_token','refresh_token','authorization','secret','client_secret','ssn','tax_id','iban','credit_card','email','phone'];
    public function redact(array $value): array
    {
        $clean=[];
        foreach($value as $key=>$item){
            if(in_array(strtolower((string)$key),self::BLOCKED_KEYS,true)){$clean[$key]='[REDACTED]';continue;}
            $clean[$key]=is_array($item)?$this->redact($item):(is_string($item)?mb_substr($item,0,2000):$item);
        }
        return $clean;
    }
}
