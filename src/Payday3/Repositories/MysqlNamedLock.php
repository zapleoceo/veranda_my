<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\NamedLockInterface;

/** MySQL GET_LOCK / RELEASE_LOCK implementation of NamedLockInterface. */
final class MysqlNamedLock implements NamedLockInterface
{
    public function __construct(private readonly Database $db) {}

    public function synchronized(string $name, int $timeoutSeconds, callable $fn): mixed
    {
        // MySQL caps lock names at 64 chars; hash long ones, keep a readable prefix.
        $key = strlen($name) <= 64 ? $name : substr($name, 0, 23) . '_' . sha1($name);
        $got = $this->db->query('SELECT GET_LOCK(?, ?)', [$key, max(0, $timeoutSeconds)])->fetchColumn();
        if ((int)$got !== 1) {
            throw new \DomainException('Операция уже выполняется в другой вкладке — повторите через несколько секунд.');
        }
        try {
            return $fn();
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }
}
