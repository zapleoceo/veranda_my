<?php

declare(strict_types=1);

namespace App\Afisha;

/**
 * Чистая логика афиши: проверка ответа ИИ и слияние с сохранённой неделей.
 * Без ввода-вывода — ответу модели здесь не доверяем, всё режем и проверяем.
 */
final class AfishaPlan
{
    public const TYPES = ['film', 'music', 'games', 'chill', 'other'];
    public const LOCALES = ['ru', 'en', 'vi'];

    private const MAX_TITLE = 60;
    private const MAX_TIME = 12;
    private const MAX_SESSION = 120;
    private const MAX_SESSIONS = 6;
    private const MAX_NOTE = 220;

    /** На сколько недель вперёд принимаем анонс (текущая + две следующие). */
    private const MAX_WEEKS_AHEAD = 2;

    /**
     * Превратить ответ ИИ в проверенный план.
     *
     * @param array<string,mixed> $ai
     * @return array{kind:string,week_start:string,summary:string,days:array<int,array<string,mixed>>}|null
     *         null — сообщение не про расписание
     *
     * @throws \InvalidArgumentException сообщение про расписание, но ответ непригоден
     */
    public static function fromAi(array $ai, \DateTimeImmutable $today): ?array
    {
        $kind = (string) ($ai['kind'] ?? 'none');
        if (empty($ai['relevant']) || !in_array($kind, ['full', 'patch'], true)) {
            return null;
        }

        $weekStart = self::normalizeWeekStart((string) ($ai['week_start'] ?? ''), $today);

        $days = [];
        foreach ((array) ($ai['days'] ?? []) as $day) {
            if (!is_array($day)) {
                continue;
            }
            $weekday = (int) ($day['weekday'] ?? -1);
            $card = self::card($day);
            if ($weekday < 0 || $weekday > 6 || $card === null) {
                continue;
            }
            $days[$weekday] = $card;
        }
        if ($days === []) {
            throw new \InvalidArgumentException('в ответе нет ни одного пригодного дня');
        }
        ksort($days);

        return [
            'kind' => $kind,
            'week_start' => $weekStart,
            'summary' => self::clean((string) ($ai['summary_ru'] ?? ''), 200),
            'days' => $days,
        ];
    }

    /**
     * full — новая афиша целиком заменяет неделю (не названные дни уйдут в
     * стандартное расписание); patch — правит только названные дни.
     *
     * @param array<int,array<string,mixed>> $existing
     * @param array<int,array<string,mixed>> $new
     * @return array<int,array<string,mixed>>
     */
    public static function merge(array $existing, array $new, string $kind): array
    {
        $out = $kind === 'full' ? $new : $new + $existing;
        ksort($out);

        return $out;
    }

    /** Понедельник недели, к которой относится дата из ответа. */
    private static function normalizeWeekStart(string $raw, \DateTimeImmutable $today): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $today->getTimezone());
        if ($date === false) {
            throw new \InvalidArgumentException("непонятная дата недели: «{$raw}»");
        }
        $monday = $date->modify('monday this week');
        $thisMonday = $today->setTime(0, 0)->modify('monday this week');
        $weeks = (int) round(($monday->getTimestamp() - $thisMonday->getTimestamp()) / 604800);
        if ($weeks < 0 || $weeks > self::MAX_WEEKS_AHEAD) {
            throw new \InvalidArgumentException('неделя ' . $monday->format('d.m') . ' вне допустимого окна');
        }

        return $monday->format('Y-m-d');
    }

    /**
     * @param array<string,mixed> $day
     * @return array<string,mixed>|null
     */
    private static function card(array $day): ?array
    {
        $ru = self::texts((array) ($day['ru'] ?? []));
        if ($ru['title'] === '') {
            return null;
        }
        $card = [
            'type' => in_array($day['type'] ?? '', self::TYPES, true) ? (string) $day['type'] : 'other',
            'ru' => $ru,
        ];
        foreach (['en', 'vi'] as $loc) {
            $t = self::texts((array) ($day[$loc] ?? []));
            $card[$loc] = $t['title'] !== '' ? $t : $ru; // перевода нет — лучше русский, чем пусто
        }

        return $card;
    }

    /**
     * @param array<string,mixed> $t
     * @return array{title:string,sessions:array<array{time:string,text:string}>,note:string}
     */
    private static function texts(array $t): array
    {
        $sessions = [];
        foreach (array_slice((array) ($t['sessions'] ?? []), 0, self::MAX_SESSIONS) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $text = self::clean((string) ($s['text'] ?? ''), self::MAX_SESSION);
            $time = self::clean((string) ($s['time'] ?? ''), self::MAX_TIME);
            // Исполнитель не известен — оставляем только время начала, не гадаем.
            if ($text !== '' || $time !== '') {
                $sessions[] = ['time' => $time, 'text' => $text];
            }
        }

        return [
            'title' => self::clean((string) ($t['title'] ?? ''), self::MAX_TITLE),
            'sessions' => $sessions,
            'note' => self::clean((string) ($t['note'] ?? ''), self::MAX_NOTE),
        ];
    }

    /**
     * Строки карточки дня — каждое событие со своим временем начала, в столбик.
     * Нет событий со временем — показываем описание дня.
     *
     * @param array<string,mixed> $texts тексты одного языка
     * @return string[]
     */
    public static function lines(array $texts): array
    {
        $lines = [];
        foreach ((array) ($texts['sessions'] ?? []) as $s) {
            $time = (string) ($s['time'] ?? '');
            $text = (string) ($s['text'] ?? '');
            $lines[] = $time !== '' && $text !== '' ? $time . ' — ' . $text : $time . $text;
        }
        if ($lines === [] && isset($texts['time'])) {
            // Карточки старого формата (общая строка времени + заметка).
            return self::legacyLines((string) $texts['time'], (string) ($texts['note'] ?? ''));
        }

        return $lines !== [] ? $lines : array_values(array_filter([(string) ($texts['note'] ?? '')]));
    }

    /**
     * Стандартное расписание из словаря хранит время и заметку двумя строками.
     * «18:00 — … · 20:00 — …» разбираем на отдельные начала; одиночное «19:00»
     * ставим перед заметкой; «весь вечер» оставляем как есть.
     *
     * @return string[]
     */
    public static function legacyLines(string $time, string $note): array
    {
        if (preg_match('/^\d{1,2}:\d{2}\s*—/u', $note)) {
            return array_map('trim', explode(' · ', $note));
        }
        if ($time !== '' && $note !== '') {
            return [$time . ' — ' . $note];
        }

        return array_values(array_filter([$time . $note]));
    }

    private static function clean(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', strip_tags($s)));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
