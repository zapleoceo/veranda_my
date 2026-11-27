<?php

declare(strict_types=1);

namespace App\Payday3\Services;

/**
 * Keeps every balance-card screenshot the operator sends, on disk.
 *
 * Files live in payday3/balance_shots/ as `YYYYmmdd_HHMMSS_microseconds_<hex>.<ext>`
 * (Vietnam time), so the file name alone gives chronological order and
 * the newest one is simply the last name when sorted. Nothing is ever
 * deleted automatically.
 *
 * The daily 08:00 send marks itself with a `.daily_YYYYmmdd` file so a
 * re-run of the cron on the same day does not post twice.
 */
final class BalanceShotStore
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    /** Час ежедневной отправки по Вьетнаму (минуты не важны — cron бьёт каждый час). */
    public const DAILY_HOUR = 8;

    // Older screenshots have no microseconds component.
    private const NAME_RE = '/^\d{8}_\d{6}_(?:\d{6}_)?[0-9a-f]{6}\.(jpg|png)$/D';

    public function __construct(private readonly string $dir) {}

    /**
     * True, когда момент $at приходится на DAILY_HOUR по Вьетнаму.
     * Часовой пояс сервера здесь не влияет: сравнивается сам момент времени.
     */
    public static function isDailySendHour(\DateTimeInterface $at): bool
    {
        $local = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone(self::TZ));
        return (int)$local->format('G') === self::DAILY_HOUR;
    }

    /** payday3/balance_shots next to payday3/local_config.json. */
    public static function defaultDir(): string
    {
        return dirname(__DIR__, 3) . '/payday3/balance_shots';
    }

    /**
     * @return array{name:string, at:string, bytes:int, mime:string}
     * @throws \RuntimeException when the file cannot be written
     */
    public function save(string $bytes, string $mime): array
    {
        $this->ensureDir();
        $ext  = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $now  = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $name = $now->format('Ymd_His_u') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        $tmp  = $path . '.tmp';

        if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось сохранить снимок.');
        }
        return [
            'name'  => $name,
            'at'    => $now->format(\DateTimeInterface::ATOM),
            'bytes' => strlen($bytes),
            'mime'  => $mime,
        ];
    }

    /**
     * Newest first.
     *
     * @return list<array{name:string, at:string, bytes:int, mime:string}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->names() as $name) {
            $out[] = $this->meta($name);
        }
        return $out;
    }

    /** @return array{name:string, at:string, bytes:int, mime:string}|null */
    public function latest(): ?array
    {
        $names = $this->names();
        return $names === [] ? null : $this->meta($names[0]);
    }

    /** Raw bytes of one stored shot, or null when the name is not a valid stored name. */
    public function read(string $name): ?string
    {
        if (!self::isValidName($name)) return null;
        $bytes = @file_get_contents($this->dir . DIRECTORY_SEPARATOR . $name);
        return $bytes === false ? null : $bytes;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_RE, $name) === 1;
    }

    public function dailySent(string $ymd): bool
    {
        return is_file($this->dailyMarker($ymd));
    }

    public function markDailySent(string $ymd): void
    {
        $this->ensureDir();
        @file_put_contents($this->dailyMarker($ymd), date('c'));
    }

    // ─── internals ──────────────────────────────────────────────

    private function dailyMarker(string $ymd): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . '.daily_' . preg_replace('/\D/', '', $ymd);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать папку снимков.');
        }
    }

    /** @return list<string> valid stored names, newest first */
    private function names(): array
    {
        if (!is_dir($this->dir)) return [];
        $files = @scandir($this->dir) ?: [];
        $names = array_values(array_filter($files, [self::class, 'isValidName']));
        rsort($names, SORT_STRING);
        return $names;
    }

    /** @return array{name:string, at:string, bytes:int, mime:string} */
    private function meta(string $name): array
    {
        $path  = $this->dir . DIRECTORY_SEPARATOR . $name;
        $stamp = substr($name, 0, 15);   // YYYYmmdd_HHMMSS
        $dt    = \DateTimeImmutable::createFromFormat('Ymd_His', $stamp, new \DateTimeZone(self::TZ));
        return [
            'name'  => $name,
            'at'    => $dt ? $dt->format(\DateTimeInterface::ATOM) : '',
            'bytes' => (int)(@filesize($path) ?: 0),
            'mime'  => str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/png',
        ];
    }
}
