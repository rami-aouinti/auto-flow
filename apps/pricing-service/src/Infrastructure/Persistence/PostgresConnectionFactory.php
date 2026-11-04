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

        if (!is_array($parts)) {
            throw new InvalidArgumentException('DATABASE_URL is not a valid URL.');
        }

        self::assertSupportedScheme($parts['scheme'] ?? null);

        return [
            'host' => self::requiredPart($parts, 'host'),
            'port' => self::port($parts),
            'database' => self::database($parts),
            'username' => rawurldecode(self::requiredPart($parts, 'user')),
            'password' => rawurldecode(self::optionalPart($parts, 'pass')),
            'query' => self::optionalPart($parts, 'query'),
        ];
    }

    private static function assertSupportedScheme(mixed $scheme): void
    {
        if (!in_array($scheme, ['postgres', 'postgresql'], true)) {
            throw new InvalidArgumentException('DATABASE_URL must use the postgres or postgresql scheme.');
        }
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function requiredPart(array $parts, string $key): string
    {
        $value = $parts[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('DATABASE_URL must define a non-empty "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function optionalPart(array $parts, string $key): string
    {
        $value = $parts[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function port(array $parts): int
    {
        $port = $parts['port'] ?? null;

        return is_int($port) ? $port : 5432;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function database(array $parts): string
    {
        $database = ltrim(self::requiredPart($parts, 'path'), '/');

        if ($database === '') {
            throw new InvalidArgumentException('DATABASE_URL must define a database name.');
        }

        return rawurldecode($database);
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
