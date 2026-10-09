<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot\Fakes;

use App\Infrastructure\HttpClient;

/** Telegram без сети: пишет вызовы, отвечает ok + растущий message_id. */
final class RecordingHttp extends HttpClient
{
    /** @var list<array{method:string, params:array}> */
    public array $calls = [];
    private int $nextId = 900;

    public function __construct() {}

    public function postJson(string $url, array $params = []): array|null
    {
        $this->calls[] = ['method' => (string) substr($url, strrpos($url, '/') + 1), 'params' => $params];
        return ['ok' => true, 'result' => ['message_id' => ++$this->nextId]];
    }

    public function getJson(string $url, array $params = []): array|null
    {
        throw new \LogicException('network is not allowed in tests');
    }

    /** @return list<array{method:string, params:array}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn($c) => $c['method'] === $method));
    }
}
