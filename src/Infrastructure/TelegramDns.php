<?php
declare(strict_types=1);

namespace App\Infrastructure;

/**
 * DNS-обход для api.telegram.org.
 *
 * С 2026-09-19 локальный резолвер сервера (unbound на 127.0.0.1) не резолвит
 * telegram-домены. Причина — его собственная настройка `do-tcp: no`: ответы
 * DNS Telegram требуют TCP, без него unbound молча сдаётся. Сеть хостинга
 * ни при чём: та же рекурсия с TCP с этого сервера резолвит штатно
 * (проверено unbound-host 2026-10-03). Починить можно только root-доступом.
 *
 * До этого обход ставил CURLOPT_DOH_URL, но curl 7.64 на сервере печатает
 * отладку DoH-подзапросов прямо в stderr процесса, игнорируя CURLOPT_STDERR, —
 * telegram.log вырос до 150 МБ. Теперь адрес берём отдельным тихим запросом
 * к DoH JSON API, кэшируем и подставляем через CURLOPT_RESOLVE.
 *
 * Файл без зависимостей: подключается и автолоадером, и require_once из
 * легаси-скриптов без composer.
 */
final class TelegramDns
{
    public const DOH_URL = 'https://1.1.1.1/dns-query';
    public const HOST = 'api.telegram.org';

    private const CACHE_TTL = 21600;      // 6 ч — IP Telegram меняются редко
    private const RETRY_AFTER_FAIL = 600; // DoH недоступен — не долбим его каждый запрос

    private static ?string $memo = null;
    private static bool $resolved = false;

    public static function apply(\CurlHandle $ch): void
    {
        $ip = self::ip();
        if ($ip !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [self::HOST . ':443:' . $ip]);
            return;
        }
        // Адреса нет ни свежего, ни старого — шумный, но рабочий запасной путь.
        if (defined('CURLOPT_DOH_URL')) {
            curl_setopt($ch, CURLOPT_DOH_URL, self::DOH_URL);
        }
    }

    public static function applyIfTelegram(\CurlHandle $ch, string $url): void
    {
        if (str_contains($url, self::HOST)) {
            self::apply($ch);
        }
    }

    /** IPv4 api.telegram.org: из памяти процесса → из файла-кэша → из DoH. */
    public static function ip(): ?string
    {
        if (self::$resolved) {
            return self::$memo;
        }
        self::$resolved = true;
        $file = sys_get_temp_dir() . '/veranda-telegram-dns.json';
        $cache = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $cachedIp = is_array($cache) && self::isIpv4($cache['ip'] ?? null) ? (string) $cache['ip'] : null;

        if ((int) ($cache['until'] ?? 0) > time()) {
            // Свежий адрес — или свежая отметка «DoH недоступен»: не ждём таймаут на каждом запросе.
            return self::$memo = $cachedIp;
        }

        $fresh = self::lookup();
        if ($fresh !== null) {
            self::save($file, $fresh, time() + self::CACHE_TTL);
            return self::$memo = $fresh;
        }
        if ($cachedIp !== null) {
            // DoH временно недоступен — старый адрес лучше, чем ничего.
            self::save($file, $cachedIp, time() + self::RETRY_AFTER_FAIL);
            return self::$memo = $cachedIp;
        }

        self::save($file, null, time() + self::RETRY_AFTER_FAIL);

        return null;
    }

    private static function lookup(): ?string
    {
        $ch = curl_init(self::DOH_URL . '?name=' . self::HOST . '&type=A');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        foreach ((array) ($data['Answer'] ?? []) as $answer) {
            if ((int) ($answer['type'] ?? 0) === 1 && self::isIpv4($answer['data'] ?? null)) {
                return (string) $answer['data'];
            }
        }

        return null;
    }

    private static function save(string $file, ?string $ip, int $until): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['ip' => $ip, 'until' => $until])) !== false) {
            @rename($tmp, $file);
        }
    }

    private static function isIpv4(mixed $v): bool
    {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
