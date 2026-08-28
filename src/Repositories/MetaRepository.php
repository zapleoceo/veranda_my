<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\Database;

class MetaRepository
{
    public function __construct(private readonly Database $db) {}

    public function getMany(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(fn($v) => trim((string) $v), $keys),
            fn($v) => $v !== ''
        )));

        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $table = $this->db->t('system_meta');

        try {
            $rows = $this->db->query(
                "SELECT meta_key, meta_value FROM {$table} WHERE meta_key IN ({$placeholders})",
                $keys
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $k = (string) ($row['meta_key'] ?? '');
            if ($k !== '') {
                $out[$k] = (string) ($row['meta_value'] ?? '');
            }
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        $result = $this->getMany([$key]);
        return $result[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $table = $this->db->t('system_meta');
        $this->db->query(
            "INSERT INTO {$table} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    }

    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->set((string) $key, (string) $value);
        }
    }

    /**
     * Инкремент суточного счётчика прогонов.
     *
     * Зачем: сводка синков считала прогоны грепом по лог-файлам, ища строки
     * вроде «Updated sync marker» и «DONE duration_ms». После перехода сервисов
     * на структурное логирование эти строки писаться перестали, и отчёт стал
     * показывать yesterday=0 при полностью рабочих кронах — то есть врал в
     * самую тревожную сторону.
     *
     * Счётчик в БД от формата логов не зависит и переживает ротацию.
     * Храним JSON вида {"2026-08-27": 288, "2026-08-28": 137}, обрезая
     * хвост до $keepDays суток, чтобы ключ не разрастался.
     */
    public function bumpDailyRun(string $key, string $date, int $keepDays = 7): void
    {
        $decoded = json_decode($this->get($key, '{}'), true);
        $counts  = is_array($decoded) ? $decoded : [];

        $counts[$date] = (int) ($counts[$date] ?? 0) + 1;

        krsort($counts);
        $counts = array_slice($counts, 0, max(1, $keepDays), true);

        $this->set($key, (string) json_encode($counts));
    }

    /** Число прогонов за конкретную дату (0, если данных нет). */
    public function dailyRunCount(string $key, string $date): int
    {
        $decoded = json_decode($this->get($key, '{}'), true);
        return is_array($decoded) ? (int) ($decoded[$date] ?? 0) : 0;
    }
}
