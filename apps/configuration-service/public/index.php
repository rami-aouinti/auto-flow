<?php
declare(strict_types=1);

/**
 * Configuration & Feature Flag API.
 * Authentication is expected at the trusted API gateway. In production the gateway
 * must strip client-supplied identity headers and inject verified tenant/actor headers.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, mixed $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function problem(int $status, string $title, string $detail, array $extra = []): never {
    header('Content-Type: application/problem+json; charset=utf-8');
    respond($status, ['type'=>'about:blank','title'=>$title,'status'=>$status,'detail'=>$detail] + $extra);
}
function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { problem(400, 'Bad Request', 'Request body must contain valid JSON.'); }
    if (!is_array($data)) problem(400, 'Bad Request', 'JSON body must be an object.');
    return $data;
}
function uuid(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $url = (string)(getenv('DATABASE_URL') ?: 'postgresql://autoflow_configuration:local-only-configuration-password@postgres:5432/autoflow');
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) throw new RuntimeException('DATABASE_URL is invalid.');
    $dsn = 'pgsql:host='.$parts['host'].';port='.($parts['port'] ?? 5432).';dbname='.ltrim($parts['path'], '/');
    $pdo = new PDO($dsn, rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? ''), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $pdo->exec("SET search_path TO configuration_service, public");
    return $pdo;
}
function identity(): array {
    $mode = getenv('AUTH_MODE') ?: 'gateway';
    if ($mode !== 'development' && (($_SERVER['HTTP_X_AUTOFLOW_AUTH_VERIFIED'] ?? '') !== 'true')) {
        problem(401, 'Unauthorized', 'A verified identity from the trusted API gateway is required.');
    }
    $tenant = trim((string)($_SERVER['HTTP_X_TENANT_ID'] ?? ''));
    $actor = trim((string)($_SERVER['HTTP_X_ACTOR_ID'] ?? ''));
    if ($tenant === '' || $actor === '') problem(401, 'Unauthorized', 'Tenant and actor identity headers are required.');
    if (strlen($tenant) > 128 || strlen($actor) > 255) problem(400, 'Bad Request', 'Identity claim exceeds the supported length.');
    return [$tenant, $actor];
}
function validName(string $value, string $kind): string {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,149}$/', $value)) problem(400, 'Bad Request', "Invalid {$kind}.");
    return $value;
}
function recordEvent(PDO $pdo, string $tenant, string $actor, string $action, string $type, string $key, ?int $version, array $details, string $eventType): void {
    $pdo->prepare('INSERT INTO configuration_audit_events (tenant_id,actor_id,action,resource_type,resource_key,version,details) VALUES (?,?,?,?,?,?,?::jsonb)')
        ->execute([$tenant,$actor,$action,$type,$key,$version,json_encode($details, JSON_THROW_ON_ERROR)]);
    $payload = ['tenantId'=>$tenant,'actorId'=>$actor,'resourceType'=>$type,'resourceKey'=>$key,'version'=>$version,'occurredAt'=>gmdate(DATE_ATOM),'details'=>$details];
    $pdo->prepare('INSERT INTO configuration_outbox_events (id,tenant_id,event_type,aggregate_key,payload) VALUES (?,?,?,?,?::jsonb)')
        ->execute([uuid(),$tenant,$eventType,$key,json_encode($payload, JSON_THROW_ON_ERROR)]);
}
function cacheDelete(string $tenant, string $key): void {
    $host = getenv('REDIS_HOST') ?: 'redis'; $port = (int)(getenv('REDIS_PORT') ?: 6379);
    $socket = @fsockopen($host, $port, $errno, $error, 0.25);
    if (!$socket) return;
    $cacheKey = 'autoflow:configuration:'.$tenant.':'.$key;
    fwrite($socket, "*2\r\n$3\r\nDEL\r\n$".strlen($cacheKey)."\r\n".$cacheKey."\r\n");
    fclose($socket);
}
function jsonValue(mixed $value): array {
    $type = match (true) { is_string($value)=>'string', is_int($value)||is_float($value)=>'number', is_bool($value)=>'boolean', is_array($value)&&array_is_list($value)=>'array', is_array($value)=>'object', $value===null=>'null', default=>null };
    if ($type === null) problem(422, 'Unprocessable Content', 'Configuration value must be valid JSON data.');
    return [$type, json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($path === '/health' || $path === '/health/live') respond(200, ['service'=>'configuration-service','status'=>'ok','checks'=>['process'=>'ok']]);
if ($path === '/health/ready') {
    try { db()->query('SELECT 1'); respond(200, ['service'=>'configuration-service','status'=>'ok','checks'=>['postgres'=>'ok']]); }
    catch (Throwable $e) { respond(503, ['service'=>'configuration-service','status'=>'not_ready','checks'=>['postgres'=>'unavailable']]); }
}
if (str_starts_with($path, '/api/')) {
    [$tenant,$actor] = identity();
    try {
        $pdo = db();
        // GET /api/configurations?namespace=...
        if ($path === '/api/configurations' && $method === 'GET') {
            $namespace = isset($_GET['namespace']) ? validName((string)$_GET['namespace'],'namespace') : null;
            $sql = 'SELECT namespace,config_key,value,value_type,description,version,updated_by,updated_at FROM configurations WHERE tenant_id=?';
            $args = [$tenant];
            if ($namespace !== null) { $sql .= ' AND namespace=?'; $args[] = $namespace; }
            $sql .= ' ORDER BY namespace,config_key LIMIT 500';
            $s=$pdo->prepare($sql); $s->execute($args); $rows=$s->fetchAll(); foreach ($rows as &$row) { $row['value']=json_decode($row['value']); } unset($row); respond(200,['data'=>$rows]);
        }
        // /api/configurations/{namespace}/{key}[/{versions|rollback}]
        if (preg_match('#^/api/configurations/([^/]+)/([^/]+)(?:/(versions|rollback))?$#', $path, $m)) {
            $namespace=validName(rawurldecode($m[1]),'namespace'); $key=validName(rawurldecode($m[2]),'key');
            if (($m[3] ?? '') === 'versions' && $method === 'GET') {
                $s=$pdo->prepare('SELECT version,value,value_type,description,changed_by,change_reason,created_at FROM configuration_versions WHERE tenant_id=? AND namespace=? AND config_key=? ORDER BY version DESC LIMIT 100');
                $s->execute([$tenant,$namespace,$key]); $rows=$s->fetchAll(); foreach ($rows as &$row) { $row['value']=json_decode($row['value']); } unset($row); respond(200,['data'=>$rows]);
            }
            if (($m[3] ?? '') === 'rollback' && $method === 'POST') {
                $input=body(); $target=(int)($input['version'] ?? 0);
                if ($target < 1) problem(422,'Unprocessable Content','A positive version is required.');
                $s=$pdo->prepare('SELECT value,value_type,description FROM configuration_versions WHERE tenant_id=? AND namespace=? AND config_key=? AND version=?');
                $s->execute([$tenant,$namespace,$key,$target]); $old=$s->fetch();
                if (!$old) problem(404,'Not Found','Configuration version was not found.');
                $pdo->beginTransaction();
                $s=$pdo->prepare('SELECT version FROM configurations WHERE tenant_id=? AND namespace=? AND config_key=? FOR UPDATE'); $s->execute([$tenant,$namespace,$key]); $current=$s->fetch();
                if (!$current) { $pdo->rollBack(); problem(404,'Not Found','Configuration was not found.'); }
                $next=(int)$current['version']+1;
                $pdo->prepare('UPDATE configurations SET value=?::jsonb,value_type=?,description=?,version=?,updated_by=?,updated_at=NOW() WHERE tenant_id=? AND namespace=? AND config_key=?')
                    ->execute([$old['value'],$old['value_type'],$old['description'],$next,$actor,$tenant,$namespace,$key]);
                $pdo->prepare('INSERT INTO configuration_versions (id,tenant_id,namespace,config_key,version,value,value_type,description,changed_by,change_reason) VALUES (?,?,?,?,?,?::jsonb,?,?,?,?)')
                    ->execute([uuid(),$tenant,$namespace,$key,$next,$old['value'],$old['value_type'],$old['description'],$actor,'Rollback to version '.$target]);
                recordEvent($pdo,$tenant,$actor,'rollback','configuration',$namespace.'/'.$key,$next,['rolledBackTo'=>$target],'ConfigurationUpdated');
                $pdo->commit(); cacheDelete($tenant,$namespace.'/'.$key);
                respond(200,['namespace'=>$namespace,'key'=>$key,'version'=>$next,'rolledBackTo'=>$target]);
            }
            if ($method === 'GET' && !isset($m[3])) {
                $s=$pdo->prepare('SELECT namespace,config_key,value,value_type,description,version,updated_by,updated_at FROM configurations WHERE tenant_id=? AND namespace=? AND config_key=?');
                $s->execute([$tenant,$namespace,$key]); $row=$s->fetch(); if (!$row) problem(404,'Not Found','Configuration was not found.');
                $row['value']=json_decode($row['value']); header('ETag: "'.$row['version'].'"'); respond(200,$row);
            }
            if ($method === 'PUT' && !isset($m[3])) {
                $input=body(); if (!array_key_exists('value',$input)) problem(422,'Unprocessable Content','The value field is required.');
                [$type,$json]=jsonValue($input['value']);
                $expected=$input['expectedVersion'] ?? null;
                $pdo->beginTransaction();
                $s=$pdo->prepare('SELECT version FROM configurations WHERE tenant_id=? AND namespace=? AND config_key=? FOR UPDATE');
                $s->execute([$tenant,$namespace,$key]); $current=$s->fetch();
                if ($current && $expected !== null && (int)$expected !== (int)$current['version']) { $pdo->rollBack(); problem(412,'Precondition Failed','Configuration version does not match expectedVersion.'); }
                if (!$current && $expected !== null && (int)$expected !== 0) { $pdo->rollBack(); problem(412,'Precondition Failed','Configuration does not exist at expectedVersion.'); }
                $version=$current ? (int)$current['version']+1 : 1;
                $description=isset($input['description']) ? substr((string)$input['description'],0,500) : null;
                if ($current) {
                    $pdo->prepare('UPDATE configurations SET value=?::jsonb,value_type=?,description=?,version=?,updated_by=?,updated_at=NOW() WHERE tenant_id=? AND namespace=? AND config_key=?')
                        ->execute([$json,$type,$description,$version,$actor,$tenant,$namespace,$key]);
                } else {
                    $pdo->prepare('INSERT INTO configurations (id,tenant_id,namespace,config_key,value,value_type,description,version,created_by,updated_by) VALUES (?,?,?,?,?::jsonb,?,?,1,?,?)')
                        ->execute([uuid(),$tenant,$namespace,$key,$json,$type,$description,$actor,$actor]);
                }
                $pdo->prepare('INSERT INTO configuration_versions (id,tenant_id,namespace,config_key,version,value,value_type,description,changed_by,change_reason) VALUES (?,?,?,?,?,?::jsonb,?,?,?,?)')
                    ->execute([uuid(),$tenant,$namespace,$key,$version,$json,$type,$description,$actor,isset($input['reason'])?substr((string)$input['reason'],0,500):null]);
                recordEvent($pdo,$tenant,$actor,$current?'updated':'created','configuration',$namespace.'/'.$key,$version,['valueType'=>$type],'ConfigurationUpdated');
                $pdo->commit(); cacheDelete($tenant,$namespace.'/'.$key);
                header('ETag: "'.$version.'"'); respond($current?200:201,['namespace'=>$namespace,'key'=>$key,'value'=>$input['value'],'type'=>$type,'version'=>$version]);
            }
        }
        // Feature flags: list, read and create/update.
        if ($path === '/api/feature-flags' && $method === 'GET') {
            $s=$pdo->prepare('SELECT flag_key,enabled,rollout_percentage,tenant_allowlist,description,version,updated_at FROM feature_flags WHERE tenant_id=? ORDER BY flag_key');
            $s->execute([$tenant]); $rows=$s->fetchAll(); foreach ($rows as &$row) { $row['tenant_allowlist']=json_decode($row['tenant_allowlist'],true) ?: []; } unset($row); respond(200,['data'=>$rows]);
        }
        if (preg_match('#^/api/feature-flags/([^/]+)(?:/(evaluate))?$#',$path,$m)) {
            $key=validName(rawurldecode($m[1]),'flag key');
            if (($m[2] ?? '') === 'evaluate' && $method === 'GET') {
                $s=$pdo->prepare('SELECT flag_key,enabled,rollout_percentage,tenant_allowlist,version FROM feature_flags WHERE tenant_id=? AND flag_key=?');
                $s->execute([$tenant,$key]); $flag=$s->fetch(); if (!$flag) problem(404,'Not Found','Feature flag was not found.');
                $allow=json_decode($flag['tenant_allowlist'],true) ?: [];
                $bucket=hexdec(substr(hash('sha256',$tenant.':'.$key),0,7)) % 100;
                $enabled=(bool)$flag['enabled'] && (in_array($tenant,$allow,true) || $bucket < (int)$flag['rollout_percentage']);
                respond(200,['key'=>$key,'enabled'=>$enabled,'reason'=>!$flag['enabled']?'disabled':(in_array($tenant,$allow,true)?'allowlisted':($enabled?'rollout':'outside_rollout')),'version'=>(int)$flag['version']]);
            }
            if ($method === 'GET' && !isset($m[2])) {
                $s=$pdo->prepare('SELECT flag_key,enabled,rollout_percentage,tenant_allowlist,description,version,updated_at FROM feature_flags WHERE tenant_id=? AND flag_key=?');
                $s->execute([$tenant,$key]); $flag=$s->fetch(); if (!$flag) problem(404,'Not Found','Feature flag was not found.'); $flag['tenant_allowlist']=json_decode($flag['tenant_allowlist'],true) ?: []; respond(200,$flag);
            }
            if ($method === 'PUT' && !isset($m[2])) {
                $input=body(); if (!array_key_exists('enabled',$input) || !is_bool($input['enabled'])) problem(422,'Unprocessable Content','enabled must be a boolean.');
                $rollout=(int)($input['rolloutPercentage'] ?? 100); if ($rollout<0 || $rollout>100) problem(422,'Unprocessable Content','rolloutPercentage must be between 0 and 100.');
                $allow=$input['tenantAllowlist'] ?? []; if (!is_array($allow) || count($allow)>500 || count(array_filter($allow,'is_string'))!==count($allow)) problem(422,'Unprocessable Content','tenantAllowlist must be an array of strings (maximum 500).');
                $pdo->beginTransaction(); $s=$pdo->prepare('SELECT version FROM feature_flags WHERE tenant_id=? AND flag_key=? FOR UPDATE'); $s->execute([$tenant,$key]); $current=$s->fetch();
                $expected=$input['expectedVersion']??null;
                if ($current && $expected!==null && (int)$expected!==(int)$current['version']) { $pdo->rollBack(); problem(412,'Precondition Failed','Feature flag version does not match expectedVersion.'); }
                $version=$current?(int)$current['version']+1:1;
                $description=isset($input['description'])?substr((string)$input['description'],0,500):null;
                if ($current) $pdo->prepare('UPDATE feature_flags SET enabled=?,rollout_percentage=?,tenant_allowlist=?::jsonb,description=?,version=?,updated_by=?,updated_at=NOW() WHERE tenant_id=? AND flag_key=?')->execute([$input['enabled'],$rollout,json_encode(array_values(array_unique($allow))),$description,$version,$actor,$tenant,$key]);
                else $pdo->prepare('INSERT INTO feature_flags (id,tenant_id,flag_key,enabled,rollout_percentage,tenant_allowlist,description,version,created_by,updated_by) VALUES (?,?,?,?,?,?,?::jsonb,1,?,?)')->execute([uuid(),$tenant,$key,$input['enabled'],$rollout,json_encode(array_values(array_unique($allow))),$description,$actor,$actor]);
                recordEvent($pdo,$tenant,$actor,$current?'updated':'created','feature_flag',$key,$version,['enabled'=>$input['enabled'],'rolloutPercentage'=>$rollout],'FeatureFlagChanged');
                $pdo->commit(); cacheDelete($tenant,'flag:'.$key); header('ETag: "'.$version.'"');
                respond($current?200:201,['key'=>$key,'enabled'=>$input['enabled'],'rolloutPercentage'=>$rollout,'tenantAllowlist'=>array_values(array_unique($allow)),'version'=>$version]);
            }
        }
        if ($path === '/api/audit-events' && $method === 'GET') {
            $limit=max(1,min(100,(int)($_GET['limit']??50)));
            $s=$pdo->prepare('SELECT actor_id,action,resource_type,resource_key,version,details,occurred_at FROM configuration_audit_events WHERE tenant_id=? ORDER BY occurred_at DESC,id DESC LIMIT ?');
            $s->bindValue(1,$tenant); $s->bindValue(2,$limit,PDO::PARAM_INT); $s->execute(); $rows=$s->fetchAll(); foreach ($rows as &$row) { $row['details']=json_decode($row['details']) ?? new stdClass(); } unset($row); respond(200,['data'=>$rows]);
        }
        problem(404,'Not Found','The requested endpoint does not exist.');
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('configuration-service database error: '.$e->getMessage());
        problem(503,'Service Unavailable','Configuration persistence is temporarily unavailable.');
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('configuration-service error: '.$e->getMessage());
        problem(500,'Internal Server Error','An unexpected error occurred.');
    }
}
problem(404,'Not Found','The requested endpoint does not exist.');
