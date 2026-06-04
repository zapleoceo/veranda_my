<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Выручка vs погода Нячанга.
 *
 * PHP-часть: агрегирует poster_checks по дням → JSON в шаблон.
 * JS-часть:  тянет осадки/ветер из Open-Meteo (бесплатно, без ключа)
 *            и строит два synchronized графика + коэффициент корреляции.
 */
final class WeatherController
{
    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pc = $this->db->t('poster_checks');

        // Вся доступная история — дневные агрегаты.
        $rows = $this->db->query(
            "SELECT
                day_date,
                SUM(payed_card + payed_third_party + tip_sum) AS revenue_cents,
                COUNT(*)                                       AS checks
             FROM {$pc}
             WHERE day_date IS NOT NULL
               AND day_date >= '2025-01-01'
             GROUP BY day_date
             ORDER BY day_date ASC"
        )->fetchAll();

        // Конвертируем центы → VND (Poster хранит в центах).
        $days = [];
        foreach ($rows as $r) {
            $days[] = [
                'date'    => $r['day_date'],
                'revenue' => (int) round((int) $r['revenue_cents'] / 100),
                'checks'  => (int) $r['checks'],
            ];
        }

        $daysJson = json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $dateMin  = $days[0]['date']  ?? '2025-01-01';
        $dateMax  = $days[count($days) - 1]['date'] ?? date('Y-m-d');
        $userEmail = (string) $request->getAttribute('user_email', '');

        ob_start();
        require __DIR__ . '/../../Views/admin/weather.php';
        $content = ob_get_clean();

        return $this->layout($response, (string) $content, $userEmail);
    }

    private function layout(ResponseInterface $response, string $content, string $userEmail): ResponseInterface
    {
        $pageTitle  = 'Выручка и погода';
        $headExtra  = '';
        $flashOk    = '';
        $flashErr   = '';
        $currentPath = '/admin/weather';
        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
