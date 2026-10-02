<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Клиент AIbroker (aib.zapleo.com) — общий брокер ключей к ИИ.
 *
 * Чат у брокера только асинхронный: POST /v1/jobs → опрос GET /v1/jobs/{id}.
 * Здесь это спрятано за одним вызовом structured(): отдать сообщения и
 * JSON-схему, получить разобранный массив.
 */
final class AiBrokerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $projectKey,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(rtrim(Config::get('AIBROKER_URL'), '/'), Config::get('AIBROKER_PROJECT_KEY'));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->projectKey !== '';
    }

    /**
     * Запрос со строгой JSON-схемой: провайдер грамматически ограничен схемой,
     * поэтому невалидный JSON вернуться не может.
     *
     * @param array<array{role:string,content:string}> $messages
     * @param array<string,mixed>                      $schema
     * @return array<string,mixed>
     *
     * @throws \RuntimeException брокер недоступен, задача упала или не уложилась в срок
     */
    public function structured(array $messages, string $schemaName, array $schema, int $maxTokens = 4000, int $deadlineSec = 60): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AIbroker не настроен (AIBROKER_URL / AIBROKER_PROJECT_KEY)');
        }

        $job = $this->request('POST', '/v1/jobs?capability=structured', [
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.2,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
            ],
        ]);
        $jobId = (int) ($job['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new \RuntimeException('AIbroker не принял задачу: ' . substr((string) json_encode($job, JSON_UNESCAPED_UNICODE), 0, 200));
        }

        $deadline = time() + $deadlineSec;
        $wait = max(1, (int) ($job['poll_after_s'] ?? 2));
        while (time() < $deadline) {
            sleep($wait);
            $res = $this->request('GET', '/v1/jobs/' . $jobId, null);
            $status = (string) ($res['status'] ?? '');
            if ($status === 'pending') {
                $wait = max(1, (int) ($res['poll_after_s'] ?? 2));
                continue;
            }
            if ($status !== 'done') {
                throw new \RuntimeException('AIbroker: ' . substr((string) ($res['error'] ?? 'неизвестная ошибка'), 0, 200));
            }
            $data = json_decode((string) ($res['text'] ?? ''), true);
            if (!is_array($data)) {
                throw new \RuntimeException('AIbroker вернул не JSON');
            }

            return $data;
        }

        throw new \RuntimeException("AIbroker: задача {$jobId} не завершилась за {$deadlineSec} с");
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['X-Project-Key: ' . $this->projectKey, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('AIbroker недоступен: ' . $err);
        }
        $data = json_decode((string) $raw, true);
        if ($code >= 400 || !is_array($data)) {
            throw new \RuntimeException("AIbroker HTTP {$code}: " . substr((string) $raw, 0, 200));
        }

        return $data;
    }
}
