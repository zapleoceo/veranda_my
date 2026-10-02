<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Banya\MonthlyPayout;
use App\Infrastructure\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /internal/banya-month-summary[?ym=YYYY-MM]
 *
 * Машинный эндпоинт для ежемесячного отчёта бани: cron на сервере Веры
 * (myAI) 1-го числа в 10:00 забирает отсюда сумму за прошлый месяц и шлёт
 * текст в Telegram-группу «Веранда и Баня» от имени Димы.
 *
 * Без сессии — доступ только по заголовку X-Report-Secret, равному
 * BANYA_REPORT_SECRET из .env. Нет ключа в .env → 503 (не «открыто всем»).
 */
final class BanyaReportController
{
    public function monthSummary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = static function (int $status, array $data) use ($response): ResponseInterface {
            $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        };

        $secret = trim((string) Config::get('BANYA_REPORT_SECRET'));
        if ($secret === '') {
            return $json(503, ['ok' => false, 'error' => 'BANYA_REPORT_SECRET is not configured']);
        }
        if (!hash_equals($secret, $request->getHeaderLine('X-Report-Secret'))) {
            return $json(401, ['ok' => false, 'error' => 'unauthorized']);
        }

        $ym = (string) ($request->getQueryParams()['ym'] ?? '');
        $period = $ym === ''
            ? MonthlyPayout::previousMonth(new \DateTimeImmutable('now'))
            : MonthlyPayout::monthOf($ym);
        if ($period === null) {
            return $json(400, ['ok' => false, 'error' => 'bad ym, expected YYYY-MM']);
        }

        $token = trim((string) (Config::get('POSTER_API_TOKEN') ?: ($_ENV['POSTER_API_TOKEN'] ?? '')));
        if ($token === '') {
            return $json(500, ['ok' => false, 'error' => 'POSTER_API_TOKEN is not configured']);
        }

        require_once __DIR__ . '/../../banya/Model.php';
        try {
            $t = (new \Banya\Model($token))->periodTotals($period['from'], $period['to']);
        } catch (\Throwable $e) {
            return $json(502, ['ok' => false, 'error' => 'poster: ' . $e->getMessage()]);
        }

        $withoutVnd = intdiv($t['without_minor'] + 50, 100);
        return $json(200, [
            'ok'          => true,
            'ym'          => $period['ym'],
            'from'        => $period['from'],
            'to'          => $period['to'],
            'checks'      => $t['checks'],
            'sum_vnd'     => intdiv($t['sum_minor'] + 50, 100),
            'hookah_vnd'  => intdiv($t['hookah_minor'] + 50, 100),
            'without_vnd' => $withoutVnd,
            'payout_pct'  => MonthlyPayout::PAYOUT_PCT,
            'payout_vnd'  => MonthlyPayout::payout($withoutVnd),
            'text'        => MonthlyPayout::message($withoutVnd),
        ]);
    }
}
