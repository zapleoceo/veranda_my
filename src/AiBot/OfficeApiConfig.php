<?php

declare(strict_types=1);

namespace App\AiBot;

/** Адрес и сервисный токен офисного API. Токен не попадает ни в логи, ни в строковые представления. */
final class OfficeApiConfig
{
    private function __construct(public readonly string $baseUrl, private readonly string $token) {}

    /**
     * @param callable(string):string $get чтение ключа конфигурации (Config::get)
     * @return self|null null — не настроено (оба ключа обязательны), отправитель остаётся заглушкой
     */
    public static function fromConfig(callable $get): ?self
    {
        $url = rtrim(trim((string) $get('OFFICE_API_URL')), '/');
        $token = trim((string) $get('OFFICE_SERVICE_TOKEN'));
        if ($url === '' || $token === '' || !self::schemeAllowed($url)) {
            return null; // токен по открытому каналу не отдаём
        }
        return new self($url, $token);
    }

    /** https всегда; http только для localhost/127.0.0.1/[::1] (тесты, локальный стенд). */
    private static function schemeAllowed(string $url): bool
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true));
    }

    public function token(): string
    {
        return $this->token;
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '***'];
    }
}
