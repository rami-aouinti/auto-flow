<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;

final class PostgresConnectionFactory
{
    public static function create(string $databaseUrl): PDO
    {
        $parts = self::parseDatabaseUrl($databaseUrl);

        return new PDO(
            self::createDsn($parts),
            $parts['username'],
            $parts['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string, query: string}
     */
    private static function parseDatabaseUrl(string $databaseUrl): array
    {
        $parts = parse_url($databaseUrl);

        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
            throw new InvalidArgumentException('DATABASE_URL must use the postgres or postgresql scheme.');
        }

        $host = $parts['host'] ?? null;
        $database = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        $username = $parts['user'] ?? null;

        if (!is_string($host) || $host === '' || $database === '' || !is_string($username) || $username === '') {
            throw new InvalidArgumentException('DATABASE_URL must define host, database and username.');
        }

        return [
            'host' => $host,
            'port' => is_int($parts['port'] ?? null) ? $parts['port'] : 5432,
            'database' => rawurldecode($database),
            'username' => rawurldecode($username),
            'password' => is_string($parts['pass'] ?? null) ? rawurldecode($parts['pass']) : '',
            'query' => is_string($parts['query'] ?? null) ? $parts['query'] : '',
        ];
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, query: string} $parts
     */
    private static function createDsn(array $parts): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'],
            $parts['port'],
            $parts['database'],
        );

        $query = [];
        parse_str($parts['query'], $query);

        if (isset($query['sslmode']) && is_string($query['sslmode'])) {
            $dsn .= ';sslmode=' . $query['sslmode'];
        }

        return $dsn;
    }
}
