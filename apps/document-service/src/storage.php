<?php
declare(strict_types=1);

/** Minimal path-style S3 API client using AWS Signature Version 4. */
function documentS3Config():array {
    $endpoint=rtrim(getenv('DOCUMENT_S3_ENDPOINT')?:'','/');
    $bucket=getenv('DOCUMENT_S3_BUCKET')?:'';
    $access=getenv('DOCUMENT_S3_ACCESS_KEY')?:'';
    $secret=getenv('DOCUMENT_S3_SECRET_KEY')?:'';
    $region=getenv('DOCUMENT_S3_REGION')?:'us-east-1';
    if($endpoint===''||$bucket===''||$access===''||$secret==='')throw new RuntimeException('S3 storage configuration is incomplete.');
    $parts=parse_url($endpoint);
    if(!is_array($parts)||empty($parts['host']))throw new RuntimeException('S3 endpoint is invalid.');
    return compact('endpoint','bucket','access','secret','region','parts');
}
function documentS3Request(string $method,string $objectKey='',?string $filePath=null):string {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required for S3 storage.');
    $c=documentS3Config();$host=$c['parts']['host'].(isset($c['parts']['port'])?':'.$c['parts']['port']:'');
    $segments=array_map('rawurlencode',array_filter(explode('/',$objectKey),static fn($v)=>$v!==''));
    $uri='/'.rawurlencode($c['bucket']).($segments?'/'.implode('/',$segments):'');
    $url=$c['endpoint'].$uri;
    $payload=$method==='PUT'&&$filePath!==null?file_get_contents($filePath):'';
    if(!is_string($payload))throw new RuntimeException('Could not read file for S3 upload.');
    $payloadHash=hash('sha256',$payload);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $amzDate=$now->format('Ymd\THis\Z');$date=$now->format('Ymd');
    $canonicalHeaders="host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders='host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest=$method."\n".$uri."\n\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
    $scope=$date.'/'.$c['region'].'/s3/aws4_request';
    $stringToSign="AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256',$canonicalRequest);
    $kDate=hash_hmac('sha256',$date,'AWS4'.$c['secret'],true);
    $kRegion=hash_hmac('sha256',$c['region'],$kDate,true);
    $kService=hash_hmac('sha256','s3',$kRegion,true);
    $kSigning=hash_hmac('sha256','aws4_request',$kService,true);
    $signature=hash_hmac('sha256',$stringToSign,$kSigning);
    $authorization='AWS4-HMAC-SHA256 Credential='.$c['access'].'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;
    $headers=['Host: '.$host,'x-amz-content-sha256: '.$payloadHash,'x-amz-date: '.$amzDate,'Authorization: '.$authorization];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    if($method==='PUT')curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);
    if($method==='HEAD')curl_setopt($ch,CURLOPT_NOBODY,true);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$status<200||$status>=300)throw new RuntimeException('S3 request failed (HTTP '.$status.').'.($error!==''?' '.$error:''));
    return is_string($body)?$body:'';
}
function documentStoragePut(string $tmp,string $tenant,string $documentId,int $version):string {
    if((getenv('DOCUMENT_STORAGE_DRIVER')?:'local')==='s3'){
        $key=hash('sha256',$tenant).'/'.$documentId.'/v'.$version.'/'.bin2hex(random_bytes(12));
        documentS3Request('PUT',$key,$tmp);
        return 's3://'.(getenv('DOCUMENT_S3_BUCKET')?:'').'/'.$key;
    }
    $root=rtrim(getenv('DOCUMENT_STORAGE_PATH')?:'/var/lib/autoflow/documents','/');
    $dir=$root.'/'.hash('sha256',$tenant);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Storage directory unavailable.');
    $key=$dir.'/'.$documentId.'-'.$version.'-'.bin2hex(random_bytes(8));
    if(!move_uploaded_file($tmp,$key)&&!copy($tmp,$key))throw new RuntimeException('Cannot store file.');
    chmod($key,0600);return $key;
}
function documentStorageGet(string $key):string {
    if(str_starts_with($key,'s3://')){
        $parts=parse_url($key);$bucket=$parts['host']??'';$object=ltrim($parts['path']??'','/');
        if($bucket!==(getenv('DOCUMENT_S3_BUCKET')?:''))throw new RuntimeException('Stored object bucket does not match configuration.');
        return documentS3Request('GET',$object);
    }
    $body=file_get_contents($key);if(!is_string($body))throw new RuntimeException('Stored document cannot be read.');return $body;
}
function documentStorageDelete(string $key):void {
    if(str_starts_with($key,'s3://')){
        $parts=parse_url($key);$bucket=$parts['host']??'';$object=ltrim($parts['path']??'','/');
        if($bucket!==(getenv('DOCUMENT_S3_BUCKET')?:''))throw new RuntimeException('Stored object bucket does not match configuration.');
        documentS3Request('DELETE',$object);return;
    }
    $root=realpath(getenv('DOCUMENT_STORAGE_PATH')?:'/var/lib/autoflow/documents');$real=realpath($key);
    if($root!==false&&$real!==false&&str_starts_with($real,$root.'/')&&is_file($real)&&!unlink($real))throw new RuntimeException('Unable to remove stored document binary.');
}
function documentStorageReady():bool {
    try{
        if((getenv('DOCUMENT_STORAGE_DRIVER')?:'local')==='s3'){documentS3Request('HEAD');return true;}
        $root=getenv('DOCUMENT_STORAGE_PATH')?:'/var/lib/autoflow/documents';return is_dir($root)?is_writable($root):((@mkdir($root,0700,true))&&is_writable($root));
    }catch(Throwable){return false;}
}
