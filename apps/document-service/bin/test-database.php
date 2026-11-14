<?php
declare(strict_types=1);

$url=parse_url(getenv('DOCUMENT_TEST_DATABASE_URL')?:'');
if(!is_array($url)||empty($url['host'])||empty($url['path'])){fwrite(STDERR,"DOCUMENT_TEST_DATABASE_URL is required\n");exit(1);}
$db=new PDO('pgsql:host='.$url['host'].';port='.($url['port']??5432).';dbname='.ltrim($url['path'],'/'),rawurldecode($url['user']??''),rawurldecode($url['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET search_path TO document_service,public');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('Document DB test failed: '.$message);};
$tenantA='document-test-tenant-a';$tenantB='document-test-tenant-b';$id='33333333-3333-4333-8333-333333333333';$grant='44444444-4444-4444-8444-444444444444';
$db->beginTransaction();
try{
    $db->prepare("SELECT set_config('app.tenant_id',:t,false)")->execute(['t'=>$tenantA]);
    $insert=$db->prepare("INSERT INTO documents(id,tenant_id,owner_id,category,title,original_filename,mime_type,byte_size,checksum_sha256,storage_key,scan_status,retention_until) VALUES(:id,:t,'document-test-owner','other','RLS fixture','fixture.txt','text/plain',1,:hash,'test://fixture','clean',NOW()+INTERVAL '1 day')");
    $insert->execute(['id'=>$id,'t'=>$tenantA,'hash'=>hash('sha256','x')]);
    $db->prepare("INSERT INTO document_access_grants(id,tenant_id,document_id,subject_id,permission,created_by) VALUES(:g,:t,:d,'document-test-recipient','read','document-test-owner')")->execute(['g'=>$grant,'t'=>$tenantA,'d'=>$id]);
    $db->prepare("SELECT set_config('app.tenant_id',:t,false)")->execute(['t'=>$tenantB]);
    $q=$db->prepare('SELECT COUNT(*) FROM documents WHERE id=:id');$q->execute(['id'=>$id]);
    $assert((int)$q->fetchColumn()===0,'tenant B cannot read tenant A document metadata');
    $q=$db->prepare('SELECT COUNT(*) FROM document_access_grants WHERE id=:id');$q->execute(['id'=>$grant]);
    $assert((int)$q->fetchColumn()===0,'tenant B cannot read tenant A access grants');
    $db->exec('SAVEPOINT cross_tenant_insert');
    try{$insert->execute(['id'=>'55555555-5555-4555-8555-555555555555','t'=>$tenantA,'hash'=>hash('sha256','y')]);$assert(false,'tenant B cannot insert a row for tenant A');}
    catch(PDOException){$db->exec('ROLLBACK TO SAVEPOINT cross_tenant_insert');}
    $db->prepare("SELECT set_config('app.tenant_id',:t,false)")->execute(['t'=>$tenantA]);
    $db->prepare("UPDATE documents SET legal_hold=TRUE,legal_hold_reason='RLS test',legal_hold_set_by='document-test-owner',legal_hold_set_at=NOW() WHERE id=:id")->execute(['id'=>$id]);
    $q=$db->prepare('SELECT legal_hold,legal_hold_reason FROM documents WHERE id=:id');$q->execute(['id'=>$id]);$row=$q->fetch();
    $assert(($row['legal_hold']??false)===true||($row['legal_hold']??null)==='t','legal hold migration is writable under tenant RLS');
    $db->prepare("INSERT INTO document_purge_events(id,tenant_id,document_id,checksum_sha256,reason) VALUES(:id,:t,:d,:h,'test')")->execute(['id'=>'66666666-6666-4666-8666-666666666666','t'=>$tenantA,'d'=>$id,'h'=>hash('sha256','x')]);
    $db->rollBack();
    $maintenanceUrl=parse_url(getenv('DOCUMENT_MAINTENANCE_TEST_DATABASE_URL')?:'');
    if(is_array($maintenanceUrl)&&!empty($maintenanceUrl['host'])&&!empty($maintenanceUrl['path'])){
        $maintenance=new PDO('pgsql:host='.$maintenanceUrl['host'].';port='.($maintenanceUrl['port']??5432).';dbname='.ltrim($maintenanceUrl['path'],'/'),rawurldecode($maintenanceUrl['user']??''),rawurldecode($maintenanceUrl['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $maintenance->beginTransaction();
        $maintenance->query('SELECT id FROM document_service.documents ORDER BY id LIMIT 1 FOR UPDATE')->fetchAll();
        $maintenance->rollBack();
    }
    fwrite(STDOUT,"Document PostgreSQL RLS, legal hold and maintenance-role tests passed.\n");
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
