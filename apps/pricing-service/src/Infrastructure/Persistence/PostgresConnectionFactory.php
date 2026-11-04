<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;

final class PostgresConnectionFactory
{
    public static function create(string $databaseUrl): PDO
    {
        $parts = parse_url($databaseUrl);

        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
            throw new InvalidArgumentException('DATABASE_URL must use the postgres or postgresql scheme.');
        }

        $host = $parts['host'] ?? null;
        $database = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        $username = $parts['user'] ?? null;
        $password = $parts['pass'] ?? null;

        if (!is_string($host) || $host === '' || $database === '' || !is_string($username) || $username === '') {
            throw new InvalidArgumentException('DATABASE_URL must define host, database and username.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $parts['port'] ?? 5432,
            rawurldecode($database),
        );

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        if (isset($query['sslmode']) && is_string($query['sslmode'])) {
            $dsn .= ';sslmode=' . $query['sslmode'];
        }

        return new PDO(
            $dsn,
            rawurldecode($username),
            is_string($password) ? rawurldecode($password) : '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
