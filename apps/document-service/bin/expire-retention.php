<?php
declare(strict_types=1);

/**
 * Permanently purges documents whose retention date has passed.
 * Run with a dedicated maintenance DB role in production; the local Compose
 * default uses the PostgreSQL bootstrap administrator to cross tenant RLS.
 */
$url=parse_url(getenv('DOCUMENT_MAINTENANCE_DATABASE_URL')?:'');
if(!is_array($url)||empty($url['host'])||empty($url['path'])){fwrite(STDERR,"DOCUMENT_MAINTENANCE_DATABASE_URL is required\n");exit(1);}
$pdo=new PDO('pgsql:host='.$url['host'].';port='.($url['port']??5432).';dbname='.ltrim($url['path'],'/'),rawurldecode($url['user']??''),rawurldecode($url['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$root=rtrim(getenv('DOCUMENT_STORAGE_PATH')?:'/var/lib/autoflow/documents','/');
$interval=max(60,(int)(getenv('DOCUMENT_RETENTION_INTERVAL_SECONDS')?:3600));
$batch=max(1,min(100,(int)(getenv('DOCUMENT_RETENTION_BATCH_SIZE')?:25)));
fwrite(STDOUT,"Document retention worker started; batch={$batch}, interval={$interval}s\n");
while(true){
    try{
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT id,tenant_id,owner_id,checksum_sha256,storage_key FROM document_service.documents WHERE retention_until IS NOT NULL AND retention_until<=NOW() AND legal_hold=FALSE ORDER BY retention_until LIMIT :limit FOR UPDATE SKIP LOCKED");
        $q->bindValue('limit',$batch,PDO::PARAM_INT);$q->execute();$docs=$q->fetchAll();
        if($docs===[]){$pdo->commit();sleep($interval);continue;}
        $count=0;
        foreach($docs as $doc){
            $id=$doc['id'];$tenant=$doc['tenant_id'];
            $versions=$pdo->prepare("SELECT storage_key FROM document_service.document_versions WHERE tenant_id=:t AND document_id=:d");
            $versions->execute(['t'=>$tenant,'d'=>$id]);$keys=array_column($versions->fetchAll(),'storage_key');$keys[]=$doc['storage_key'];
            foreach(array_unique($keys) as $key){
                $real=realpath($key);
                if($real!==false&&str_starts_with($real,$root.'/')&&is_file($real)&&!@unlink($real))throw new RuntimeException("Unable to remove expired document binary.");
            }
            $audit=$pdo->prepare("INSERT INTO document_service.document_audit_events(tenant_id,document_id,actor_id,action,details) VALUES(:t,:d,'document-retention-worker','document.purged',jsonb_build_object('reason','retention_expired'))");
            $audit->execute(['t'=>$tenant,'d'=>$id]);
            $purge=$pdo->prepare("INSERT INTO document_service.document_purge_events(id,tenant_id,document_id,checksum_sha256,reason) VALUES(:i,:t,:d,:h,'retention_expired')");
            $purge->execute(['i'=>sprintf('%s-%s-%s-%s-%s',substr($id,0,8),substr($id,9,4),substr($id,14,4),substr($id,19,4),substr($id,24,12)),'t'=>$tenant,'d'=>$id,'h'=>$doc['checksum_sha256']]);
            $event=$pdo->prepare("INSERT INTO document_service.document_outbox_events(id,tenant_id,aggregate_id,event_type,payload) VALUES(:i,:t,:d,'DocumentPurged',jsonb_build_object('documentId',:d,'reason','retention_expired'))");
            $event->execute(['i'=>bin2hex(random_bytes(16)),'t'=>$tenant,'d'=>$id]);
            $pdo->prepare("DELETE FROM document_service.document_versions WHERE tenant_id=:t AND document_id=:d")->execute(['t'=>$tenant,'d'=>$id]);
            $pdo->prepare("DELETE FROM document_service.document_access_grants WHERE tenant_id=:t AND document_id=:d")->execute(['t'=>$tenant,'d'=>$id]);
            $pdo->prepare("DELETE FROM document_service.documents WHERE tenant_id=:t AND id=:d AND legal_hold=FALSE")->execute(['t'=>$tenant,'d'=>$id]);
            $count++;
        }
        $pdo->commit();
        if($count>0)fwrite(STDOUT,"Purged {$count} expired document(s).\n");
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Retention batch failed: ".$e->getMessage()."\n");}
    sleep($interval);
}
