<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3\Fakes;

use App\Payday3\Contracts\SessionStoreInterface;

final class InMemorySessionStore implements SessionStoreInterface
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}
