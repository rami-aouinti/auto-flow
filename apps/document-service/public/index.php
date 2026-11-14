<?php
declare(strict_types=1);

const MAX_UPLOAD = 26214400;
const MIME_TYPES = ['application/pdf','image/jpeg','image/png','text/plain','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

function respond(array $body, int $status=200, string $type='application/json'): never {
    http_response_code($status); header('Content-Type: '.$type.'; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
}
function fail(int $status,string $title,string $detail): never {
    respond(['type'=>'about:blank','title'=>$title,'status'=>$status,'detail'=>$detail],$status,'application/problem+json');
}
function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }
function pdo(): PDO {
    static $db; if($db instanceof PDO)return $db;
    $u=parse_url(getenv('DOCUMENT_DATABASE_URL')?:'');
    if(!is_array($u)||empty($u['host'])||empty($u['path']))throw new RuntimeException('Document database URL missing');
    $db=new PDO('pgsql:host='.$u['host'].';port='.($u['port']??5432).';dbname='.ltrim($u['path'],'/'),rawurldecode($u['user']??''),rawurldecode($u['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET search_path TO document_service,public');return $db;
}
function dec(string $v): string { $r=base64_decode(strtr($v,'-_','+/').str_repeat('=',(4-strlen($v)%4)%4),true);return $r===false?'':$r; }
function rsaKey(array $j): OpenSSLAsymmetricKey {
    $len=static function(int $n):string{if($n<128)return chr($n);$b=ltrim(pack('N',$n),"\0");return chr(128|strlen($b)).$b;};
    $int=static function(string $v)use($len):string{if((ord($v[0])&128)!==0)$v="\0".$v;return "\x02".$len(strlen($v)).$v;};
    $rsa=$int(dec($j['n']??'')).$int(dec($j['e']??''));$rsa="\x30".$len(strlen($rsa)).$rsa;
    $bits="\x03".$len(strlen($rsa)+1)."\0".$rsa;$seq=hex2bin('300d06092a864886f70d0101010500').$bits;$der="\x30".$len(strlen($seq)).$seq;
    $key=openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n");
    if(!$key)throw new RuntimeException('Invalid signing key');return $key;
}
function auth():array {
    $h=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/D',$h,$m))fail(401,'Unauthorized','Bearer token required.');
    $s=explode('.',$m[1]);if(count($s)!==3)fail(401,'Unauthorized','Malformed token.');
    $head=json_decode(dec($s[0]),true);$c=json_decode(dec($s[1]),true);
    if(!is_array($head)||($head['alg']??'')!=='RS256'||!is_array($c))fail(401,'Unauthorized','Only RS256 tokens are accepted.');
    $iss=rtrim(getenv('AUTOFLOW_JWT_ISSUER')?:'','/');$jwks=getenv('AUTOFLOW_JWT_JWKS_URI')?:'';
    if(!$iss||!$jwks)fail(503,'Authentication unavailable','JWT configuration is missing.');
    if(($c['iss']??'')!==$iss||!isset($c['exp'])||(int)$c['exp']<=time()||(isset($c['nbf'])&&(int)$c['nbf']>time()+5))fail(401,'Unauthorized','Token expired or issuer is invalid.');
    $aud=$c['aud']??[];if(!in_array('autoflow-document',is_array($aud)?$aud:[$aud],true))fail(401,'Unauthorized','Invalid token audience.');
    $ctx=stream_context_create(['http'=>['timeout'=>3,'header'=>"Accept: application/json\r\n"]]);$raw=@file_get_contents($jwks,false,$ctx);$keys=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($keys['keys']??null))fail(503,'Authentication unavailable','Cannot load identity signing keys.');
    $found=null;foreach($keys['keys'] as $k)if(($k['kid']??'')===($head['kid']??null)&&($k['kty']??'')==='RSA'){$found=$k;break;}
    if(!$found||openssl_verify($s[0].'.'.$s[1],dec($s[2]),rsaKey($found),OPENSSL_ALGO_SHA256)!==1)fail(401,'Unauthorized','Invalid token signature.');
    $sub=$c['sub']??'';$tenant=$c['tenant_id']??'';if(!is_string($sub)||$sub===''||!is_string($tenant)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$tenant))fail(403,'Forbidden','Token must contain sub and tenant_id.');
    $sc=preg_split('/\s+/',trim((string)($c['scope']??'')))?:[];$roles=$c['realm_access']['roles']??[];if(is_array($roles))$sc=array_merge($sc,$roles);
    return ['sub'=>$sub,'tenant'=>$tenant,'scopes'=>array_unique($sc)];
}
function permit(array $u,string $s):void{if(!in_array($s,$u['scopes'],true))fail(403,'Forbidden','Missing permission '.$s.'.');}
function upload(array $u,string $id,int $v):array {
    if(!isset($_FILES['file'])||($_FILES['file']['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($_FILES['file']['tmp_name']??''))fail(400,'Bad Request','Multipart field file is required.');
    $f=$_FILES['file'];$size=(int)$f['size'];if($size<1||$size>MAX_UPLOAD)fail(413,'Payload Too Large','Maximum upload size is 25 MiB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);if(!is_string($mime)||!in_array($mime,MIME_TYPES,true))fail(415,'Unsupported Media Type','Allowed formats: PDF, JPEG, PNG, TXT and DOCX.');
    $sock=@fsockopen(getenv('CLAMAV_HOST')?:'clamav',(int)(getenv('CLAMAV_PORT')?:3310),$errno,$err,4);if(!$sock)fail(503,'Scanner unavailable','Upload rejected because malware scanning is unavailable.');
    stream_set_timeout($sock,30);fwrite($sock,"zINSTREAM\0");$in=fopen($f['tmp_name'],'rb');while(!feof($in)){$chunk=fread($in,65536);if($chunk===false)break;if($chunk!=='')fwrite($sock,pack('N',strlen($chunk)).$chunk);}fwrite($sock,pack('N',0));$scan=stream_get_contents($sock);fclose($in);fclose($sock);
    if(!is_string($scan)||!str_ends_with(trim($scan),'OK'))fail(422,'Unprocessable Content',str_contains((string)$scan,'FOUND')?'Malware detected.':'Malware scan did not complete cleanly.');
    $root=rtrim(getenv('DOCUMENT_STORAGE_PATH')?:'/var/lib/autoflow/documents','/');$dir=$root.'/'.hash('sha256',$u['tenant']);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Storage directory unavailable.');
    $key=$dir.'/'.$id.'-'.$v.'-'.bin2hex(random_bytes(8));if(!move_uploaded_file($f['tmp_name'],$key))throw new RuntimeException('Cannot store file.');chmod($key,0600);
    $name=basename(str_replace('\\','/',(string)$f['name']));$name=preg_replace('/[^A-Za-z0-9._ -]/u','_',$name)?:'document';
    return ['key'=>$key,'name'=>mb_substr($name,0,255),'mime'=>$mime,'size'=>$size,'hash'=>hash_file('sha256',$key)];
}
function audit(PDO $db,array $u,string $id,string $action,array $data=[]):void{$q=$db->prepare('INSERT INTO document_audit_events(tenant_id,document_id,actor_id,action,details) VALUES(:t,:d,:a,:x,:j)');$q->execute(['t'=>$u['tenant'],'d'=>$id,'a'=>$u['sub'],'x'=>$action,'j'=>json_encode($data)]);}
function emit(PDO $db,array $u,string $id,string $type,array $data):void{$q=$db->prepare('INSERT INTO document_outbox_events(id,tenant_id,aggregate_id,event_type,payload) VALUES(:i,:t,:d,:x,:j)');$q->execute(['i'=>uuid(),'t'=>$u['tenant'],'d'=>$id,'x'=>$type,'j'=>json_encode($data)]);}
function findDoc(PDO $db,array $u,string $id):array{$q=$db->prepare("SELECT * FROM documents WHERE tenant_id=:t AND id=:d AND status<>'deleted'");$q->execute(['t'=>$u['tenant'],'d'=>$id]);$r=$q->fetch();if(!$r)fail(404,'Not Found','Document not found.');unset($r['storage_key']);return $r;}
function run():never {
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';
    if(in_array($path,['/health','/health/live'],true))respond(['service'=>'document-service','status'=>'ok','checks'=>['process'=>'ok']]);
    if($path==='/health/ready'){
        $checks=['postgres'=>'unavailable','storage'=>'unavailable','clamav'=>'unavailable'];
        try{pdo()->query('SELECT 1');$checks['postgres']='ok';}catch(Throwable){}
        $root=getenv('DOCUMENT_STORAGE_PATH')?:'/var/lib/autoflow/documents';$checks['storage']=is_writable($root)?'ok':'unavailable';
        $scanner=@fsockopen(getenv('CLAMAV_HOST')?:'clamav',(int)(getenv('CLAMAV_PORT')?:3310),$errno,$error,1.0);
        if(is_resource($scanner)){fwrite($scanner,"zPING\\0");$pong=fread($scanner,32);fclose($scanner);if(is_string($pong)&&str_contains($pong,'PONG'))$checks['clamav']='ok';}
        $ready=!in_array('unavailable',$checks,true);respond(['service'=>'document-service','status'=>$ready?'ok':'not_ready','checks'=>$checks],$ready?200:503);
    }
    if($path==='/api/doc.json')respond(['openapi'=>'3.1.0','info'=>['title'=>'AutoFlow Document API','version'=>'1.0.0'],'paths'=>['/api/v1/documents'=>['get'=>['summary'=>'List documents'],'post'=>['summary'=>'Upload document']]]]);
    if($path==='/api/doc'){header('Location: /api/doc.json',true,302);exit;}
    $u=auth();$db=pdo();$tenant=$db->prepare("SELECT set_config('app.tenant_id', :tenant, false)");$tenant->execute(['tenant'=>$u['tenant']]);
    if($path==='/api/v1/documents'&&$method==='GET'){
        permit($u,'documents:read');$page=max(1,(int)($_GET['page']??1));$limit=min(100,max(1,(int)($_GET['limit']??25)));$where=["tenant_id=:t","status<>'deleted'"];$params=['t'=>$u['tenant']];
        foreach(['category','entity_type','entity_id','status'] as $k)if(isset($_GET[$k])&&$_GET[$k]!==''){$where[]="$k=:$k";$params[$k]=$_GET[$k];}
        if(!empty($_GET['q'])){$where[]='(title ILIKE :q OR original_filename ILIKE :q)';$params['q']='%'.str_replace(['%','_'],['\\%','\\_'],(string)$_GET['q']).'%';}
        $clause=implode(' AND ',$where);$q=$db->prepare("SELECT id,tenant_id,owner_id,entity_type,entity_id,category,title,description,original_filename,mime_type,byte_size,checksum_sha256,scan_status,status,current_version,retention_until,metadata,created_at,updated_at FROM documents WHERE $clause ORDER BY created_at DESC LIMIT $limit OFFSET ".(($page-1)*$limit));$q->execute($params);$items=$q->fetchAll();$c=$db->prepare("SELECT COUNT(*) FROM documents WHERE $clause");$c->execute($params);$total=(int)$c->fetchColumn();respond(['data'=>$items,'meta'=>['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total/$limit)]]);
    }
    if($path==='/api/v1/documents'&&$method==='POST'){
        permit($u,'documents:write');$title=trim((string)($_POST['title']??''));$cat=(string)($_POST['category']??'other');if($title===''||mb_strlen($title)>255||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$cat))fail(422,'Validation Failed','title and valid category are required.');
        $id=uuid();$f=upload($u,$id,1);$db->beginTransaction();try{$q=$db->prepare('INSERT INTO documents(id,tenant_id,owner_id,entity_type,entity_id,category,title,description,original_filename,mime_type,byte_size,checksum_sha256,storage_key,scan_status,retention_until) VALUES(:id,:t,:o,:et,:ei,:c,:title,:desc,:name,:mime,:size,:hash,:key,\'clean\',:ret)');
        $q->execute(['id'=>$id,'t'=>$u['tenant'],'o'=>$u['sub'],'et'=>$_POST['entity_type']??null,'ei'=>$_POST['entity_id']??null,'c'=>$cat,'title'=>$title,'desc'=>$_POST['description']??null,'name'=>$f['name'],'mime'=>$f['mime'],'size'=>$f['size'],'hash'=>$f['hash'],'key'=>$f['key'],'ret'=>($_POST['retention_until']??'')?:null]);
        $q=$db->prepare("INSERT INTO document_versions(id,tenant_id,document_id,version,original_filename,mime_type,byte_size,checksum_sha256,storage_key,scan_status,created_by) VALUES(:v,:t,:d,1,:n,:m,:s,:h,:k,'clean',:a)");$q->execute(['v'=>uuid(),'t'=>$u['tenant'],'d'=>$id,'n'=>$f['name'],'m'=>$f['mime'],'s'=>$f['size'],'h'=>$f['hash'],'k'=>$f['key'],'a'=>$u['sub']]);audit($db,$u,$id,'document.created',['version'=>1]);emit($db,$u,$id,'DocumentCreated',['documentId'=>$id,'category'=>$cat]);$db->commit();}catch(Throwable $e){$db->rollBack();@unlink($f['key']);throw $e;}
        header('Location: /api/v1/documents/'.$id);respond(['data'=>findDoc($db,$u,$id)],201);
    }
    if(preg_match('#^/api/v1/documents/([0-9a-f-]{36})(?:/(content|versions|audit|archive))?$#i',$path,$m)){
        $id=$m[1];$action=$m[2]??'';$q=$db->prepare("SELECT * FROM documents WHERE tenant_id=:t AND id=:d AND status<>'deleted'");$q->execute(['t'=>$u['tenant'],'d'=>$id]);$doc=$q->fetch();if(!$doc)fail(404,'Not Found','Document not found.');
        if($action===''&&$method==='GET'){permit($u,'documents:read');unset($doc['storage_key']);respond(['data'=>$doc]);}
        if($action===''&&$method==='DELETE'){permit($u,'documents:manage');$db->beginTransaction();try{$db->prepare("UPDATE documents SET status='deleted',deleted_at=NOW(),updated_at=NOW() WHERE tenant_id=:t AND id=:d")->execute(['t'=>$u['tenant'],'d'=>$id]);audit($db,$u,$id,'document.deleted');emit($db,$u,$id,'DocumentDeleted',['documentId'=>$id]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}http_response_code(204);exit;}
        if($action==='content'&&$method==='GET'){permit($u,'documents:read');if($doc['scan_status']!=='clean'||!is_file($doc['storage_key']))fail(423,'Locked','Document is not available for download.');audit($db,$u,$id,'document.downloaded');header('Content-Type: '.$doc['mime_type']);header('Content-Length: '.filesize($doc['storage_key']));header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.addcslashes($doc['original_filename'],'"\\').'"');readfile($doc['storage_key']);exit;}
        if($action==='versions'&&$method==='GET'){permit($u,'documents:read');$q=$db->prepare('SELECT id,version,original_filename,mime_type,byte_size,checksum_sha256,scan_status,created_by,created_at FROM document_versions WHERE tenant_id=:t AND document_id=:d ORDER BY version DESC');$q->execute(['t'=>$u['tenant'],'d'=>$id]);respond(['data'=>$q->fetchAll()]);}
        if($action==='versions'&&$method==='POST'){permit($u,'documents:write');$v=(int)$doc['current_version']+1;$f=upload($u,$id,$v);$db->beginTransaction();try{$q=$db->prepare("INSERT INTO document_versions(id,tenant_id,document_id,version,original_filename,mime_type,byte_size,checksum_sha256,storage_key,scan_status,created_by) VALUES(:v,:t,:d,:n,:name,:mime,:size,:hash,:key,'clean',:a)");$q->execute(['v'=>uuid(),'t'=>$u['tenant'],'d'=>$id,'n'=>$v,'name'=>$f['name'],'mime'=>$f['mime'],'size'=>$f['size'],'hash'=>$f['hash'],'key'=>$f['key'],'a'=>$u['sub']]);$q=$db->prepare("UPDATE documents SET current_version=:v,original_filename=:name,mime_type=:mime,byte_size=:size,checksum_sha256=:hash,storage_key=:key,scan_status='clean',updated_at=NOW() WHERE tenant_id=:t AND id=:d");$q->execute(['v'=>$v,'name'=>$f['name'],'mime'=>$f['mime'],'size'=>$f['size'],'hash'=>$f['hash'],'key'=>$f['key'],'t'=>$u['tenant'],'d'=>$id]);audit($db,$u,$id,'document.version_created',['version'=>$v]);emit($db,$u,$id,'DocumentVersionCreated',['documentId'=>$id,'version'=>$v]);$db->commit();}catch(Throwable $e){$db->rollBack();@unlink($f['key']);throw $e;}respond(['data'=>findDoc($db,$u,$id)],201);}
        if($action==='audit'&&$method==='GET'){permit($u,'documents:read');$q=$db->prepare('SELECT actor_id,action,details,occurred_at FROM document_audit_events WHERE tenant_id=:t AND document_id=:d ORDER BY occurred_at DESC LIMIT 100');$q->execute(['t'=>$u['tenant'],'d'=>$id]);respond(['data'=>$q->fetchAll()]);}
        if($action==='archive'&&$method==='POST'){permit($u,'documents:manage');$db->beginTransaction();try{$db->prepare("UPDATE documents SET status='archived',updated_at=NOW() WHERE tenant_id=:t AND id=:d")->execute(['t'=>$u['tenant'],'d'=>$id]);audit($db,$u,$id,'document.archived');emit($db,$u,$id,'DocumentArchived',['documentId'=>$id]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}respond(['data'=>findDoc($db,$u,$id)]);}
    }
    fail(404,'Not Found','Endpoint not found.');
}
try{run();}catch(Throwable $e){error_log('[document-service] '.$e::class.': '.$e->getMessage());fail(500,'Internal Server Error','Request could not be completed.');}
