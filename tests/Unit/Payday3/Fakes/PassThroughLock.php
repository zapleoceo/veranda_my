<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3\Fakes;

use App\Payday3\Contracts\NamedLockInterface;

/** Records lock names; runs the callback. */
final class PassThroughLock implements NamedLockInterface
{
    /** @var list<string> */
    public array $names = [];
    public int $held = 0;

    public function synchronized(string $name, int $timeoutSeconds, callable $fn): mixed
    {
        $this->names[] = $name;
        $this->held++;
        try {
            return $fn();
        } finally {
            $this->held--;
        }
    }
}
