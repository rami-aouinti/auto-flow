<?php
declare(strict_types=1);
$host = getenv('DB_HOST') ?: 'postgres';
$port = (int)(getenv('DB_PORT') ?: 5432);
$socket = @fsockopen($host, $port, $errno, $error, 1.5);
if (!is_resource($socket)) { fwrite(STDERR, "PostgreSQL is unavailable\n"); exit(1); }
fclose($socket);
