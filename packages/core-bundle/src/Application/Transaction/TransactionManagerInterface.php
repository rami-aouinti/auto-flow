<?php

declare(strict_types=1);

namespace AutoFlow\\CoreBundle\\Application\\Transaction;

/**
 * Application port for an atomic persistence boundary.
 *
 * Keep Doctrine and connection objects in Infrastructure adapters.
 */
interface TransactionManagerInterface
{
    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    public function transactional(callable $operation): mixed;
}
