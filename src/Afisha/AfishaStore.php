<?php

declare(strict_types=1);

namespace App\Afisha;

/**
 * Хранилище недельной афиши — JSON-файлы в cache/afisha/.
 *
 * Почему файлы, а не БД: главную читает каждый посетитель, и она не должна
 * зависеть от базы. Папка cache/ исключена из rsync-деплоя, так что афиша
 * переживает выкатки. Пропал файл — сайт просто покажет стандартное расписание.
 *
 * На каждую неделю хранится текущая версия и одна предыдущая (для отката).
 */
final class AfishaStore
{
    private const SEEN_LIMIT = 300;

    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/cache/afisha';
    }

    /** @return array<string,mixed>|null */
    public function week(string $weekStart): ?array
    {
        return $this->read($this->path($weekStart));
    }

    /**
     * Карточки недели на нужном языке: [день недели 0..6 => type/title/time/note].
     *
     * @return array<int,array{type:string,title:string,time:string,note:string}>
     */
    public function cardsFor(string $weekStart, string $locale): array
    {
        $out = [];
        foreach ((array) ($this->week($weekStart)['days'] ?? []) as $weekday => $card) {
            $t = $card[$locale] ?? $card['en'] ?? $card['ru'] ?? null;
            if (!is_array($t) || ($t['title'] ?? '') === '') {
                continue;
            }
            $out[(int) $weekday] = [
                'type' => (string) ($card['type'] ?? 'other'),
                'title' => (string) $t['title'],
                'time' => (string) ($t['time'] ?? ''),
                'note' => (string) ($t['note'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Сохранить неделю; прежняя версия уходит в .prev для отката.
     *
     * @param array<string,mixed> $data
     */
    public function save(string $weekStart, array $data): void
    {
        $this->ensureDir();
        $path = $this->path($weekStart);
        $prev = $this->prevPath($weekStart);
        if (is_file($path)) {
            copy($path, $prev);
        } else {
            // Раньше недели не было: откат должен вернуть «нет афиши», а не старую .prev.
            $this->write($prev, ['empty' => true]);
        }
        $this->write($path, $data);
    }

    /** Вернуть предыдущую версию недели. false — откатывать нечего. */
    public function undo(string $weekStart): bool
    {
        $prevPath = $this->prevPath($weekStart);
        $prev = $this->read($prevPath);
        if ($prev === null) {
            return false;
        }
        if (!empty($prev['empty'])) {
            @unlink($this->path($weekStart));
        } else {
            $this->write($this->path($weekStart), $prev);
        }
        @unlink($prevPath);

        return true;
    }

    /**
     * Отметить сообщение обработанным. false — уже видели (повторная доставка
     * вебхука), обрабатывать второй раз не нужно.
     */
    public function markSeen(string $key): bool
    {
        $this->ensureDir();
        $path = $this->dir . '/seen.json';
        $seen = $this->read($path) ?? [];
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = time();
        if (count($seen) > self::SEEN_LIMIT) {
            asort($seen);
            $seen = array_slice($seen, -self::SEEN_LIMIT, null, true);
        }
        $this->write($path, $seen);

        return true;
    }

    private function path(string $weekStart): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            throw new \InvalidArgumentException('week_start должен быть YYYY-MM-DD');
        }

        return $this->dir . '/week-' . $weekStart . '.json';
    }

    private function prevPath(string $weekStart): string
    {
        return substr($this->path($weekStart), 0, -5) . '.prev.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('не могу создать ' . $this->dir);
        }
    }

    /** @return array<string|int,mixed>|null */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** Атомарно: посетитель никогда не увидит наполовину записанный файл. */
    private function write(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('не могу записать ' . $path);
        }
    }
}
