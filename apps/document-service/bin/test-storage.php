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
putenv('DOCUMENT_STORAGE_DRIVER=s3');
putenv('DOCUMENT_S3_ENDPOINT=');
putenv('DOCUMENT_S3_BUCKET=');
putenv('DOCUMENT_S3_ACCESS_KEY=');
putenv('DOCUMENT_S3_SECRET_KEY=');
check(!documentStorageReady(),'S3 readiness fails closed when credentials are missing');
fwrite(STDOUT,"Document storage adapter tests passed.\n");
