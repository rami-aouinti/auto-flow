<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class PostgresConnection
{
    private ?PDO $connection = null;

    public function __construct(private readonly string $databaseUrl)
    {
    }

    public function get(): PDO
    {
        $this->connection ??= self::connect($this->databaseUrl);

        return $this->connection;
    }

    private static function connect(string $databaseUrl): PDO
    {
        $parts = parse_url($databaseUrl);

        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
            throw new \InvalidArgumentException('OFFER_DATABASE_URL must be a PostgreSQL URL.');
        }

        $host = $parts['host'] ?? null;
        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        $username = $parts['user'] ?? null;

        if (!is_string($host) || $host === '' || $database === '' || !is_string($username) || $username === '') {
            throw new \InvalidArgumentException('OFFER_DATABASE_URL must define host, database and username.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            is_int($parts['port'] ?? null) ? $parts['port'] : 5432,
            rawurldecode($database),
        );

        return new PDO(
            $dsn,
            rawurldecode($username),
            rawurldecode((string) ($parts['pass'] ?? '')),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
