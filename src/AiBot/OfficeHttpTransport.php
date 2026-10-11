<?php

declare(strict_types=1);

namespace App\AiBot;

/** Транспорт к офисному API (подменяется в тестах). Сетевой сбой — OfficeTransportException. */
interface OfficeHttpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string} имена заголовков в нижнем регистре
     * @throws OfficeTransportException
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array;
}
