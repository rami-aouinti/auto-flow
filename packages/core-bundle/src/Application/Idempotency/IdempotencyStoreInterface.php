<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Application\Idempotency;

/**
 * Persistence port for idempotent command/API handling.
 *
 * Implementations must make claim() atomic across concurrent workers.
 * The storage schema, transaction boundary and retention policy are
 * deliberately service-owned; this package does not prescribe a database.
 */
interface IdempotencyStoreInterface
{
    /**
     * Atomically claim a key if it has not already been claimed.
     */
    public function claim(string $key, int $ttlSeconds): bool;

    /**
     * Store a replayable response for a previously claimed key.
     *
     * @param array<string, mixed> $response
     */
    public function complete(string $key, array $response, int $ttlSeconds): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findCompleted(string $key): ?array;

    public function release(string $key): void;
}
