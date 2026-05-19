<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /schedule — страница «График смен».
 *
 * Текущее состояние: визуальный каркас страницы (блочная модель из мокапа
 * docs/mockups/schedule.html), без save-логики. Когда UX подтверждён,
 * добавляются: таблицы schedule_snapshots / schedule_zones / schedule_staff_tags,
 * AJAX-эндпоинты `?ajax=load|save_snapshot|list_snapshots|halls`, drag-n-drop через
 * SortableJS. До тех пор страница рендерит демо-данные и работает как
 * интерактивный прототип, в котором можно показать партнёрам логику.
 */
class ScheduleController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Per-page permission gate (legacy-style — same pattern as Banya/Roma/etc).
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['schedule'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $pageTitle    = 'График смен';
        $currentPath  = '/schedule';
        $headExtra    = '<link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens2">' . "\n"
                      . '<link rel="stylesheet" href="/assets/css/schedule.css?v=20260517_v4_floating_tip">';

        ob_start();
        require __DIR__ . '/../Views/schedule_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
