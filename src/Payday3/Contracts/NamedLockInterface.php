<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

/**
 * Cross-request mutual exclusion (MySQL GET_LOCK). Used around
 * "check that it doesn't exist yet → create in Poster" sequences, which
 * are otherwise racy: two parallel clicks both pass the check.
 */
interface NamedLockInterface
{
    /**
     * Run $fn while holding the lock $name; released in `finally`.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws \DomainException when the lock isn't acquired within $timeoutSeconds
     */
    public function synchronized(string $name, int $timeoutSeconds, callable $fn): mixed;
}
