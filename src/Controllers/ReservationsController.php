<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PosterReservationsService;
use App\Services\ReservationMessagingService;
use App\Services\ReservationsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ReservationsController
{
    public function __construct(
        private readonly ReservationsService        $reservations,
        private readonly PosterReservationsService  $poster,
        private readonly ReservationMessagingService $messaging,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $ajax  = $query['ajax'] ?? '';
        $method = $request->getMethod();

        return match ($ajax) {
            'get_res'                  => $this->_getRes($request, $response),
            'save_res'                 => $this->_saveRes($request, $response),
            'res_halls_list'           => $this->_hallsList($request, $response),
            'res_hall_tables_list'     => $this->_hallTablesList($request, $response),
            'vposter'                  => $this->_vposter($request, $response),
            'resend'                   => $this->_resend($request, $response),
            'toggle_deleted'           => $this->_toggleDeleted($request, $response),
            'res_table_update'         => $this->_tableUpdate($request, $response),
            'res_soon_hours'           => $this->_soonHours($request, $response),
            'res_preorder_min_per_guest' => $this->_minPerGuest($request, $response),
            'res_hall_rotate'          => $this->_hallRotate($request, $response),
            'res_hall_data'            => $this->_hallData($request, $response),
            default                    => $this->_page($request, $response),
        };
    }

    private function _getRes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id  = (int)($request->getQueryParams()['id'] ?? 0);
        $row = $this->reservations->getReservation($id);
        if (!$row) {
            return $this->_json($response, ['ok' => false, 'error' => 'Not found'], 404);
        }
        return $this->_json($response, ['ok' => true, 'data' => $row]);
    }

    private function _saveRes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $id   = (int)($body['id'] ?? 0);
        if ($id <= 0) return $this->_json($response, ['ok' => false, 'error' => 'Invalid ID'], 400);

        $allowed = ['start_time', 'guests', 'table_num', 'name', 'phone', 'whatsapp_phone',
            'comment', 'preorder_text', 'preorder_ru', 'tg_user_id', 'tg_username',
            'zalo_user_id', 'zalo_phone', 'lang', 'total_amount', 'qr_url', 'qr_code',
            'duration', 'spot_id', 'hall_id', 'poster_table_id'];

        $updates = [];
        foreach ($allowed as $f) {
            if (isset($body[$f])) {
                $updates[$f] = $body[$f] === '' ? null : $body[$f];
            }
        }

        $posterTableId = (int)($body['poster_table_id'] ?? 0);
        if ($posterTableId > 0) {
            if (!$this->_can('vposter_button') && !$this->_can('admin')) {
                return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $spotId = max(1, (int)($body['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
            $hallId = max(1, (int)($body['hall_id'] ?? 2));
            $found  = $this->poster->resolveTableLabel($spotId, $hallId, $posterTableId);
            if (!$found) return $this->_json($response, ['ok' => false, 'error' => 'Bad poster_table_id'], 400);
            $updates['spot_id']        = $spotId;
            $updates['hall_id']        = $hallId;
            $updates['poster_table_id'] = $posterTableId;
            $updates['table_num']      = $found['label'];
        } else {
            unset($updates['spot_id'], $updates['hall_id'], $updates['poster_table_id']);
        }

        if (empty($updates)) return $this->_json($response, ['ok' => false, 'error' => 'No fields to update'], 400);

        try {
            $this->reservations->updateReservation($id, $updates);
            return $this->_json($response, ['ok' => true]);
        } catch (\Throwable $e) {
            return $this->_json($response, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function _hallsList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->_can('vposter_button') && !$this->_can('admin')) {
            return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $spotId = max(1, (int)($request->getQueryParams()['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
        if (!$this->poster->isAvailable()) {
            return $this->_json($response, ['ok' => false, 'error' => 'Poster API not configured'], 500);
        }
        return $this->_json($response, ['ok' => true, 'spot_id' => $spotId, 'halls' => $this->poster->getHallsList($spotId)]);
    }

    private function _hallTablesList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->_can('vposter_button') && !$this->_can('admin')) {
            return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        }
        if (!$this->poster->isAvailable()) {
            return $this->_json($response, ['ok' => false, 'error' => 'Poster disabled'], 500);
        }
        $q      = $request->getQueryParams();
        $spotId = max(1, (int)($q['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
        $hallId = max(1, (int)($q['hall_id'] ?? 2));
        return $this->_json($response, [
            'ok' => true, 'spot_id' => $spotId, 'hall_id' => $hallId,
            'tables' => $this->poster->getHallTables($spotId, $hallId),
        ]);
    }

    private function _vposter(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        if (!$this->_can('vposter_button')) {
            return $this->_json($response, ['ok' => false, 'error' => 'У вас нет прав для создания брони в Poster'], 403);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $id   = (int)($body['id'] ?? 0);
        if ($id <= 0) return $this->_json($response, ['ok' => false, 'error' => 'Invalid ID'], 400);

        $row = $this->reservations->getReservation($id);
        if (!$row) return $this->_json($response, ['ok' => false, 'error' => 'Reservation not found'], 404);
        if (!$this->poster->isAvailable()) {
            return $this->_json($response, ['ok' => false, 'error' => 'Poster API not configured'], 500);
        }

        $actor = (string)($_SESSION['user_email'] ?? '');
        $res   = $this->poster->pushToPoster($id, $row, $actor);
        return $this->_json($response, $res, $res['ok'] ? 200 : 500);
    }

    private function _resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $body   = (array)($request->getParsedBody() ?? []);
        $id     = (int)($body['id'] ?? 0);
        $target = strtolower(trim((string)($body['target'] ?? 'both')));
        if ($id <= 0) return $this->_json($response, ['ok' => false, 'error' => 'Invalid ID'], 400);

        $row = $this->reservations->getReservation($id);
        if (!$row) return $this->_json($response, ['ok' => false, 'error' => 'Reservation not found'], 404);

        $res = $this->messaging->resend($row, $target);
        return $this->_json($response, $res, $res['ok'] ? 200 : 500);
    }

    private function _toggleDeleted(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $body    = (array)($request->getParsedBody() ?? []);
        $id      = (int)($body['id'] ?? 0);
        $deleted = (int)($body['deleted'] ?? 1) === 1;
        $email   = (string)($_SESSION['user_email'] ?? '');
        if ($id <= 0) return $this->_json($response, ['ok' => false, 'error' => 'Invalid ID'], 400);

        try {
            $row       = $this->reservations->toggleDeleted($id, $deleted, $email);
            $deletedAt = (string)($row['deleted_at'] ?? '');
            $isDeleted = $deletedAt !== '' && $deletedAt !== '0000-00-00 00:00:00';
            return $this->_json($response, [
                'ok'         => true,
                'deleted'    => $isDeleted,
                'deleted_at' => $isDeleted ? date('d.m.Y H:i', strtotime($deletedAt)) : '',
                'deleted_by' => (string)($row['deleted_by'] ?? ''),
            ]);
        } catch (\Throwable) {
            return $this->_json($response, ['ok' => false, 'error' => 'DB error'], 500);
        }
    }

    private function _tableUpdate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        if (!$this->_can('admin')) return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        if (!$this->poster->isAvailable()) {
            return $this->_json($response, ['ok' => false, 'error' => 'Poster disabled'], 500);
        }
        $body          = (array)($request->getParsedBody() ?? []);
        $spotId        = max(1, (int)($body['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
        $hallId        = max(1, (int)($body['hall_id'] ?? 2));
        $posterTableId = (int)($body['poster_table_id'] ?? 0);
        if ($posterTableId <= 0) return $this->_json($response, ['ok' => false, 'error' => 'Bad poster_table_id'], 400);

        $this->poster->updateTableSettings($spotId, $hallId, $posterTableId, [
            'scheme_num'     => $body['scheme_num'] ?? null,
            'display_name'   => $body['display_name'] ?? '',
            'show_on_canvas' => (int)($body['show_on_canvas'] ?? 0) === 1,
            'bookable'       => (int)($body['bookable'] ?? 0) === 1,
            'capacity'       => $body['capacity'] ?? 0,
        ]);
        return $this->_json($response, ['ok' => true, 'hall_id' => $hallId, 'spot_id' => $spotId, 'poster_table_id' => $posterTableId]);
    }

    private function _soonHours(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        if (!$this->_can('admin')) return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        $body = (array)($request->getParsedBody() ?? []);
        $h    = (int)($body['soon_hours'] ?? 2);
        $this->reservations->updateSoonHours($h);
        return $this->_json($response, ['ok' => true, 'soon_hours' => max(0, min(24, $h))]);
    }

    private function _minPerGuest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        if (!$this->_can('admin')) return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        $body = (array)($request->getParsedBody() ?? []);
        $v    = (int)($body['min_per_guest'] ?? 0);
        $this->reservations->updateMinPreorderPerGuest($v);
        return $this->_json($response, ['ok' => true, 'min_per_guest' => max(0, $v)]);
    }

    private function _hallRotate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        if (!$this->_can('admin')) return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        $body   = (array)($request->getParsedBody() ?? []);
        $spotId = max(1, (int)($body['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
        $hallId = max(1, (int)($body['hall_id'] ?? 2));
        $rot    = (int)($body['rotate_180'] ?? 0) === 1 ? 1 : 0;
        $this->poster->updateHallRotate($spotId, $hallId, $rot);
        return $this->_json($response, ['ok' => true, 'spot_id' => $spotId, 'hall_id' => $hallId, 'rotate_180' => $rot]);
    }

    private function _hallData(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->_can('admin')) return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        $q      = $request->getQueryParams();
        $spotId = max(1, (int)($q['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
        $hallId = max(1, (int)($q['hall_id'] ?? 2));
        return $this->_json($response, $this->poster->getHallData($spotId, $hallId, $this->reservations->getSettings()));
    }

    private function _page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q           = $request->getQueryParams();
        $dateFrom    = $q['date_from'] ?? date('Y-m-d');
        $dateTo      = $q['date_to'] ?? date('Y-m-d', strtotime('+1 month'));
        $showDeleted = !empty($q['show_deleted']);
        $showPoster  = !isset($q['show_poster']) || !empty($q['show_poster']);
        $sort        = in_array($q['sort'] ?? '', ['id', 'qr_code', 'created_at', 'start_time', 'table_num', 'guests', 'name', 'phone', 'total_amount'], true) ? $q['sort'] : 'start_time';
        $order       = strtolower($q['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d', strtotime('+1 month'));

        $resSpotId  = max(1, (int)($q['spot_id'] ?? (int)($_ENV['POSTER_SPOT_ID'] ?? 1)));
        $resHallId  = max(1, (int)($q['hall_id'] ?? 2));
        $settings   = $this->reservations->getSettings();
        $ourRows    = $this->reservations->getReservationsList($dateFrom, $dateTo, $showDeleted, $sort, $order);
        $posterRows = $showPoster && $this->poster->isAvailable()
            ? $this->poster->getPosterRows($resSpotId, $dateFrom, $dateTo, $showDeleted)
            : [];
        $viewRows    = $this->poster->mergeRows($ourRows, $posterRows, $showPoster, $sort, $order);
        $resRotate180 = $this->poster->getHallRotate($resSpotId, $resHallId);

        $hasPosterAccess = $this->_can('vposter_button');
        $canManageTables = $this->_can('admin');
        $userEmail       = (string)($_SESSION['user_email'] ?? '');
        $resSoonHours         = $settings['soon_hours'];
        $resMinPreorderPerGuest = $settings['min_preorder_per_guest'];

        ob_start();
        require __DIR__ . '/../Views/reservations.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function _json(ResponseInterface $response, array $data, int $code = 200): ResponseInterface
    {
        $response->getBody()->write((string)json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($code);
    }

    private function _can(string $perm): bool
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        return !is_array($perms) || !empty($perms[$perm]);
    }
}
