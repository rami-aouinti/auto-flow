<?php
declare(strict_types=1);
$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:8080/health/ready', false, $context);
$status = $http_response_header[0] ?? '';
exit($body !== false && str_contains($status, ' 200 ') ? 0 : 1);
