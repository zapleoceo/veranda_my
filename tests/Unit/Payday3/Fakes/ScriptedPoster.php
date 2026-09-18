<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3\Fakes;

use App\Classes\PosterAPI;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Poster stand-in: answers per method from a map (value or callable) and
 * records every call. No network.
 */
final class ScriptedPoster extends PosterAPI implements PosterApiProviderInterface
{
    /** @var list<array{method:string, params:array, http:string}> */
    public array $calls = [];

    /** @param array<string, mixed|callable(array):mixed> $responses */
    public function __construct(public array $responses = [])
    {
        parent::__construct('test-token');
    }

    public function client(): PosterAPI { return $this; }

    public function request(string $method, array $params = [], string $httpMethod = 'GET', bool $isV3 = false)
    {
        $this->calls[] = ['method' => $method, 'params' => $params, 'http' => $httpMethod];
        if (!array_key_exists($method, $this->responses)) return [];
        $r = $this->responses[$method];
        return $r instanceof \Closure ? $r($params) : $r;
    }

    /** @return list<array{method:string, params:array, http:string}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn($c) => $c['method'] === $method));
    }
}
