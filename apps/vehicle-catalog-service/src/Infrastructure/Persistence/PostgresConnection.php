<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use PDOException;

final class PostgresConnection
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly string $databaseUrl,
    ) {
    }

    public function get(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $parts = parse_url($this->databaseUrl);

        if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
            throw new PDOException('Vehicle Catalog DATABASE_URL is invalid.');
        }

        $database = ltrim($parts['path'], '/');
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'],
            $parts['port'] ?? 5432,
            $database,
        );

        $this->connection = new PDO(
            $dsn,
            rawurldecode($parts['user'] ?? ''),
            rawurldecode($parts['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );

        return $this->connection;
    }
}
