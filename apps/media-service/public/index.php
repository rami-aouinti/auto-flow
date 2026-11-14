<?php
declare(strict_types=1);

// Health-only bootstrap. Business routes intentionally return 404 until implemented.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!in_array($path, ['/health', '/health/live', '/health/ready'], true)) {
    http_response_code(404);
    header('Content-Type: application/problem+json');
    echo json_encode(['type'=>'about:blank','title'=>'Not Found','status'=>404,'detail'=>'Business endpoints are not implemented in this scaffold.']);
    return;
}
$ready = true;
$checks = [];
if ($path === '/health/ready') {
    $dependencies = trim((string) (getenv('HEALTH_DEPENDENCIES') ?: ''));
    foreach (array_filter(array_map('trim', explode(';', $dependencies))) as $dependency) {
        [$host, $port] = array_pad(explode(':', $dependency, 2), 2, '');
        $socket = @fsockopen($host, (int) $port, $errno, $error, 1.5);
        $ok = is_resource($socket);
        if ($ok) { fclose($socket); }
        $checks[$dependency] = $ok ? 'ok' : 'unavailable';
        $ready = $ready && $ok;
    }
} else { $checks['process'] = 'ok'; }
http_response_code($ready ? 200 : 503);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['service'=>'media-service','status'=>$ready ? 'ok' : 'not_ready','checks'=>$checks], JSON_UNESCAPED_SLASHES);
