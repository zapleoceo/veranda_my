<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot\Fakes;

use App\AiBot\OfficeHttpTransport;

/** Офисный API без сети: отдаёт заранее заданные ответы по порядку, пишет запросы. */
final class ScriptedOfficeTransport implements OfficeHttpTransport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];
    /** @var list<array{status:int,headers?:array<string,string>,body?:array}|\Throwable> */
    private array $queue = [];

    /** @param array<string,mixed> $body */
    public function push(int $status, array $body = [], array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => $body];
        return $this;
    }

    public function pushError(\Throwable $e): self
    {
        $this->queue[] = $e;
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('unexpected office request: ' . $method . ' ' . $url);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return ['status' => $next['status'], 'headers' => $next['headers'] ?? [], 'body' => (string) json_encode($next['body'] ?? [])];
    }

    /** @return list<array<string,mixed>> декодированные тела POST */
    public function bodies(): array
    {
        return array_values(array_map(static fn($c) => json_decode((string) $c['body'], true), array_filter($this->calls, static fn($c) => $c['body'] !== null)));
    }
}
