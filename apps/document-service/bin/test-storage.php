<?php
declare(strict_types=1);

require_once __DIR__.'/../src/storage.php';

function check(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('Document storage test failed: '.$message);
}
$root=sys_get_temp_dir().'/autoflow-document-storage-test-'.bin2hex(random_bytes(6));
putenv('DOCUMENT_STORAGE_DRIVER=local');
putenv('DOCUMENT_STORAGE_PATH='.$root);
check(documentStorageReady(),'local storage readiness creates and validates its root directory');
$tmp=tempnam(sys_get_temp_dir(),'autoflow-doc-');
if($tmp===false)throw new RuntimeException('Cannot create temporary test file.');
file_put_contents($tmp,"AutoFlow document storage round-trip\n");
$key=documentStoragePut($tmp,'tenant-fixture','11111111-1111-4111-8111-111111111111',1);
check(is_file($key),'uploaded local object exists');
check(documentStorageGet($key)==="AutoFlow document storage round-trip\n",'local object round-trips without content changes');
documentStorageDelete($key);
check(!file_exists($key),'local object is permanently deleted');
$outside=tempnam(sys_get_temp_dir(),'autoflow-outside-');
if($outside===false)throw new RuntimeException('Cannot create outside-root test file.');
file_put_contents($outside,'must remain');
documentStorageDelete($outside);
check(is_file($outside),'delete refuses paths outside the configured storage root');
unlink($outside);
unlink($tmp);
@rmdir($root.'/'.hash('sha256','tenant-fixture'));
@rmdir($root);
if(getenv('DOCUMENT_STORAGE_INTEGRATION_TEST')==='1'){
    putenv('DOCUMENT_STORAGE_DRIVER=s3');
    $s3Tmp=tempnam(sys_get_temp_dir(),'autoflow-s3-doc-');
    if($s3Tmp===false)throw new RuntimeException('Cannot create S3 integration fixture.');
    file_put_contents($s3Tmp,"encrypted S3 round-trip fixture");
    $s3Key=documentStoragePut($s3Tmp,'tenant-s3-fixture','22222222-2222-4222-8222-222222222222',1);
    check(str_starts_with($s3Key,'s3://'),'S3 adapter stores an opaque bucket key');
    check(documentStorageGet($s3Key)==="encrypted S3 round-trip fixture",'SSE-C encrypted S3 object round-trips');
    documentStorageDelete($s3Key);
    unlink($s3Tmp);
}
putenv('DOCUMENT_STORAGE_DRIVER=s3');
putenv('DOCUMENT_S3_ENDPOINT=');
putenv('DOCUMENT_S3_BUCKET=');
putenv('DOCUMENT_S3_ACCESS_KEY=');
putenv('DOCUMENT_S3_SECRET_KEY=');
check(!documentStorageReady(),'S3 readiness fails closed when credentials are missing');
fwrite(STDOUT,"Document storage adapter tests passed.\n");
