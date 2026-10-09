<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Bloggers\Support\PosterText;

/**
 * Детерминированный разбор сообщения о выплатах:
 *   «Раздал половину дивидендов за сентябрь: Олег 5 378 800 (переводы 5.000.000 + 378.800), Дима 3 693 200, …»
 *
 * Каждый сегмент «Имя <сумма>[ (переводы a + b)]» → строка. Сумма может быть с
 * пробелами / точками / запятыми как разделителями тысяч. Имена берутся как
 * написаны (без угадывания, кто это). Текст — только ДАННЫЕ.
 *
 * Результат:
 *   people: list<{name:string, amount:int, parts:list<int>, error:?string}>
 *   period: ?string  — «сентябрь» / «сентябрь 2026» (период дивидендов, НЕ дата транзакции)
 *   errors: list<string> — общие ошибки разбора (пусто = всё сошлось)
 */
final class PayoutParser
{
    public const MAX_AMOUNT_VND = 1_000_000_000;
    public const MAX_NAME_LEN = 60;
    public const MAX_PEOPLE = 30;

    private const NUM = '\d{1,3}(?:[ \x{00A0}\x{202F}.,]\d{3})+(?!\d)|\d+';

    private const MONTHS = [
        'январ' => 'январь', 'феврал' => 'февраль', 'март' => 'март', 'апрел' => 'апрель',
        'ма' => 'май', 'июн' => 'июнь', 'июл' => 'июль', 'август' => 'август',
        'сентябр' => 'сентябрь', 'октябр' => 'октябрь', 'ноябр' => 'ноябрь', 'декабр' => 'декабрь',
    ];

    /** @return array{people:list<array{name:string,amount:int,parts:list<int>,error:?string}>,period:?string,errors:list<string>} */
    public function parse(string $text): array
    {
        $people = [];
        $re = '/(?<!\p{L})(\p{Lu}\p{Ll}+(?:[ \-]\p{Lu}\p{Ll}+)?)\s*[:\-–—=]?\s*(' . self::NUM . ')(?:\s*(?:₫|vnd|донг\p{L}*|д\.?))?(?:\s*\(([^()]*)\))?/u';
        if (preg_match_all($re, $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $people[] = self::row($m[1], self::toInt($m[2]), $m[3] ?? '');
            }
        }

        return self::cap($people, self::period($text));
    }

    /**
     * Общий выход для парсера и ИИ-фолбэка: не больше MAX_PEOPLE строк.
     *
     * @param list<array{name:string,amount:int,parts:list<int>,error:?string}> $people
     * @return array{people:list<array{name:string,amount:int,parts:list<int>,error:?string}>,period:?string,errors:list<string>}
     */
    public static function cap(array $people, ?string $period): array
    {
        $errors = $people === [] ? ['Не нашёл ни одной строки «Имя сумма»'] : [];
        if (count($people) > self::MAX_PEOPLE) {
            $errors[] = 'Больше ' . self::MAX_PEOPLE . ' строк — разбейте сообщение';
            $people = array_slice($people, 0, self::MAX_PEOPLE);
        }
        return ['people' => array_values($people), 'period' => $period, 'errors' => $errors];
    }

    /**
     * Проверка/нормализация строки (общая для детерминированного парсера и ИИ-фолбэка).
     *
     * @param list<int>|string $parts строка из скобок «переводы a + b» или уже готовый список
     * @return array{name:string,amount:int,parts:list<int>,error:?string}
     */
    public static function row(string $name, int $amount, array|string $parts): array
    {
        // Poster режет 4-байтовые символы (эмодзи) вместе со всем полем — чистим и ограничиваем длину.
        $name = mb_substr(PosterText::safe(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, self::MAX_NAME_LEN);
        if (is_string($parts)) {
            $list = [];
            if (preg_match_all('/' . self::NUM . '/u', $parts, $pm)) {
                foreach ($pm[0] as $p) {
                    $list[] = self::toInt($p);
                }
            }
            // «(5 000 000)» — одно число без «+» равное сумме: это не разбиение.
            if (count($list) < 2) {
                $list = [];
            }
            $parts = $list;
        }
        $parts = array_values(array_map('intval', $parts));

        $error = null;
        if ($name === '') {
            $error = 'нет имени';
        } elseif ($amount <= 0) {
            $error = 'сумма не распознана';
        } elseif ($amount > self::MAX_AMOUNT_VND) {
            $error = 'сумма больше лимита ' . self::fmt(self::MAX_AMOUNT_VND);
        } elseif ($parts !== [] && array_sum($parts) !== $amount) {
            $error = 'части ' . implode(' + ', array_map([self::class, 'fmt'], $parts))
                . ' = ' . self::fmt(array_sum($parts)) . ' ≠ ' . self::fmt($amount);
        } elseif ($parts !== [] && min($parts) <= 0) {
            $error = 'нулевая часть';
        }

        return ['name' => $name, 'amount' => $amount, 'parts' => $parts, 'error' => $error];
    }

    public static function period(string $text): ?string
    {
        if (!preg_match('/(?<!\p{L})за\s+(январ|феврал|март|апрел|ма(?=[йяе])|июн|июл|август|сентябр|октябр|ноябр|декабр)\p{L}*(?:\s+(\d{4}))?/iu', $text, $m)) {
            return null;
        }
        $month = self::MONTHS[mb_strtolower($m[1])] ?? null;
        if ($month === null) {
            return null;
        }
        return isset($m[2]) && $m[2] !== '' ? $month . ' ' . $m[2] : $month;
    }

    public static function fmt(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }

    private static function toInt(string $s): int
    {
        $digits = preg_replace('/\D/', '', $s) ?? '';
        return $digits === '' || strlen($digits) > 12 ? 0 : (int) $digits;
    }
}
