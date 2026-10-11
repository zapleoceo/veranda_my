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
        return $url === '' || $token === '' ? null : new self($url, $token);
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
