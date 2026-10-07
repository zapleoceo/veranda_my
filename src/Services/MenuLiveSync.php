<?php

declare(strict_types=1);

namespace App\Services;

use App\Classes\Database as LegacyDatabase;
use App\Classes\PosterAPI;
use App\Classes\PosterMenuSync;
use App\Infrastructure\Config;
use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

/**
 * Синк меню из Poster по просмотру страницы — чтобы состав и цены на
 * /links/menu были почти онлайн, а страница при этом открывалась из БД.
 *
 * Тот же PosterMenuSync, что и у часового крона (cron/menu_sync.php), и те же
 * ключи system_meta: крон остаётся страховкой, когда меню никто не открывает.
 * Синк занимает ~1 с, параллельные просмотры не дублируют его (GET_LOCK).
 */
final class MenuLiveSync
{
    private const LOCK = 'veranda_menu_sync';

    public function __construct(
        private readonly Database $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Сколько секунд назад был последний синк (null — не было или не разобрать). */
    public function ageSeconds(): ?int
    {
        $mt = $this->db->t('system_meta');
        try {
            $at = $this->db->query("SELECT meta_value FROM {$mt} WHERE meta_key='menu_last_sync_at' LIMIT 1")->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($at) || $at === '') {
            return null;
        }
        try {
            $ts = (new \DateTimeImmutable($at, self::syncTimeZone()))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }

        return max(0, time() - $ts);
    }

    /**
     * Синк, если данные старше $maxAgeSec. Если синк уже идёт в другом
     * запросе — не ждём и не дублируем. Ошибки не пробрасываем: меню
     * показывается из того, что уже есть в БД.
     */
    public function refreshIfStale(int $maxAgeSec): void
    {
        $age = $this->ageSeconds();
        if ($age !== null && $age < $maxAgeSec) {
            return;
        }

        $got = $this->db->query('SELECT GET_LOCK(?, 0)', [self::LOCK])->fetchColumn();
        if ((int) $got !== 1) {
            return;
        }
        try {
            // Пока ждали замок, синк мог пройти в соседнем запросе.
            $age = $this->ageSeconds();
            if ($age !== null && $age < $maxAgeSec) {
                return;
            }
            $this->runSync();
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private function runSync(): void
    {
        $token = Config::get('POSTER_API_TOKEN');
        if ($token === '') {
            return;
        }

        $tz = self::syncTimeZone();
        $now = static fn(): string => (new \DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');

        try {
            $legacy = new LegacyDatabase(
                Config::get('DB_HOST', 'localhost'),
                Config::get('DB_NAME'),
                Config::get('DB_USER'),
                Config::get('DB_PASS'),
                Config::get('DB_TABLE_SUFFIX'),
            );
            $result = (new PosterMenuSync(new PosterAPI($token), $legacy))->sync();
            $summary = sprintf(
                'duration_ms=%d, items_seen=%d, workshops=%d, categories=%d',
                (int) ($result['duration_ms'] ?? 0),
                (int) ($result['items_seen'] ?? 0),
                (int) ($result['workshops'] ?? 0),
                (int) ($result['categories'] ?? 0),
            );
            $this->putMeta(['menu_last_sync_at' => $now(), 'menu_last_sync_result' => $summary, 'menu_last_sync_error' => '']);
        } catch (\Throwable $e) {
            $this->logger->warning('menu.live_sync_failed', ['err' => $e->getMessage()]);
            // Метку времени двигаем и при ошибке — иначе каждый просмотр
            // долбил бы упавший Poster.
            $this->putMeta([
                'menu_last_sync_at' => $now(),
                'menu_last_sync_result' => 'ok=0',
                'menu_last_sync_error' => mb_substr($e->getMessage(), 0, 250, 'UTF-8'),
            ]);
        }
    }

    /** @param array<string,string> $pairs */
    private function putMeta(array $pairs): void
    {
        $mt = $this->db->t('system_meta');
        foreach ($pairs as $k => $v) {
            $this->db->query(
                "INSERT INTO {$mt} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
                [$k, $v]
            );
        }
    }

    /** Та же зона, в которой cron/menu_sync.php пишет menu_last_sync_at. */
    private static function syncTimeZone(): \DateTimeZone
    {
        $ids = timezone_identifiers_list();
        $spot = trim(Config::get('POSTER_SPOT_TIMEZONE'));
        if ($spot === '' || !in_array($spot, $ids, true)) {
            $spot = 'Asia/Ho_Chi_Minh';
        }
        $api = trim(Config::get('POSTER_API_TIMEZONE'));
        if ($api === '' || !in_array($api, $ids, true)) {
            $api = $spot;
        }

        return new \DateTimeZone($api);
    }
}
