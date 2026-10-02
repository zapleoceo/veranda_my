<?php

declare(strict_types=1);

namespace App\Banya;

/**
 * Ежемесячная выплата бане: «Сумма без кальянов» за прошлый месяц и 20% от неё.
 *
 * Чистые функции без сети — расчёт суммы делает \Banya\Model::monthSummary(),
 * здесь только период, процент и текст сообщения, которое 1-го числа уходит
 * в Telegram-группу «Веранда и Баня» (до автоматизации Дима писал его руками
 * ровно в этом формате).
 */
final class MonthlyPayout
{
    public const PAYOUT_PCT = 20;
    public const TZ = 'Asia/Ho_Chi_Minh';

    /**
     * Предыдущий календарный месяц относительно $now (в часовом поясе ресторана).
     *
     * @return array{ym:string, from:string, to:string}
     */
    public static function previousMonth(\DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new \DateTimeZone(self::TZ));
        $first = $local->modify('first day of last month');
        return [
            'ym'   => $first->format('Y-m'),
            'from' => $first->format('Y-m-01'),
            'to'   => $first->format('Y-m-t'),
        ];
    }

    /**
     * Период по явному «YYYY-MM».
     *
     * @return array{ym:string, from:string, to:string}|null
     */
    public static function monthOf(string $ym): ?array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])\z/', $ym) !== 1) {
            return null;
        }
        $first = new \DateTimeImmutable($ym . '-01', new \DateTimeZone(self::TZ));
        return ['ym' => $ym, 'from' => $first->format('Y-m-01'), 'to' => $first->format('Y-m-t')];
    }

    /** 20% от суммы, округление до донга. */
    public static function payout(int $withoutHookahVnd): int
    {
        return intdiv($withoutHookahVnd * self::PAYOUT_PCT + 50, 100);
    }

    public static function message(int $withoutHookahVnd): string
    {
        return 'Сумма без кальянов: ' . self::fmt($withoutHookahVnd) . "\n"
             . 'К выплате ' . self::fmt(self::payout($withoutHookahVnd));
    }

    private static function fmt(int $vnd): string
    {
        return number_format($vnd, 0, '.', ' ');
    }
}
