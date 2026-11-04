<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class PostgresConnection
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly string $databaseUrl,
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function get(): PDO
    {
        $this->connection ??= PostgresConnectionFactory::create($this->databaseUrl);

        return $this->connection;
    }
}
