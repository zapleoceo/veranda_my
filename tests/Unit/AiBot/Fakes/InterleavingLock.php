<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot\Fakes;

use App\Payday3\Contracts\NamedLockInterface;

/**
 * Пропускающий лок с «вклиниванием»: перед СЛЕДУЮЩИМ захватом лока с именем
 * $on выполняется $before — так моделируется другой callback, успевший
 * изменить черновик между проверкой вне лока и захватом лока.
 */
final class InterleavingLock implements NamedLockInterface
{
    /** @var list<string> */
    public array $names = [];
    public ?\Closure $before = null;
    public string $on = '';

    public function synchronized(string $name, int $timeoutSeconds, callable $fn): mixed
    {
        $this->names[] = $name;
        if ($this->before !== null && $name === $this->on) {
            $hook = $this->before;
            $this->before = null;
            $hook();
        }
        return $fn();
    }
}
