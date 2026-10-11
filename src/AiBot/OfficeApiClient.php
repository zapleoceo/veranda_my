<?php

declare(strict_types=1);

namespace App\AiBot;

/**
 * HTTP-клиент офисного API (/office/v1). Токен — только в заголовке Authorization;
 * в исключениях и логах его нет. Коды HTTP не бросают исключений: решает вызывающий.
 */
final class OfficeApiClient
{
    public function __construct(
        private readonly OfficeApiConfig $config,
        private readonly OfficeHttpTransport $transport,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /** @param array<string,mixed> $payload */
    public function createTask(array $payload): OfficeApiResponse
    {
        return $this->call('POST', '/office/v1/tasks', $payload);
    }

    /** @param array<string,mixed> $payload */
    public function answer(string $taskId, array $payload): OfficeApiResponse
    {
        return $this->call('POST', '/office/v1/tasks/' . rawurlencode($taskId) . '/answers', $payload);
    }

    public function listTasks(?string $updatedSince, ?string $cursor, int $limit = 100): OfficeApiResponse
    {
        $q = array_filter(['updated_since' => $updatedSince, 'cursor' => $cursor, 'limit' => (string) $limit],
            static fn($v) => $v !== null && $v !== '');
        return $this->call('GET', '/office/v1/tasks?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986), null);
    }

    public function delivered(string $taskId, int $revision): OfficeApiResponse
    {
        return $this->call('POST', '/office/v1/tasks/' . rawurlencode($taskId) . '/delivered', ['revision' => $revision]);
    }

    /** @param array<string,mixed>|null $payload */
    private function call(string $method, string $path, ?array $payload): OfficeApiResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->config->token(), 'Accept' => 'application/json'];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        try {
            $r = $this->transport->send($method, $this->config->baseUrl . $path, $headers, $body, $this->timeoutSeconds);
        } catch (OfficeTransportException $e) {
            throw new OfficeTransportException(str_replace($this->config->token(), '***', $e->getMessage()));
        } catch (\Throwable $e) {
            throw new OfficeTransportException('office transport: ' . str_replace($this->config->token(), '***', get_class($e)));
        }
        $data = json_decode($r['body'], true);
        $ra = $r['headers']['retry-after'] ?? null;
        return new OfficeApiResponse(
            (int) $r['status'],
            is_array($data) ? $data : [],
            $ra !== null && ctype_digit(trim($ra)) ? min(3600, max(1, (int) $ra)) : null,
        );
    }
}
