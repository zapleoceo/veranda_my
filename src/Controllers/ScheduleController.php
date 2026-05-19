<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Schedule\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /schedule — страница «График смен».
 *
 * GET  /schedule                 → HTML page (layout + sidebar + state)
 * GET  /schedule?ajax=load       → JSON: state + employees + halls + zones + snapshots
 * POST /schedule?ajax=save       → save current state as new snapshot
 * GET  /schedule?ajax=snapshots  → list of saved snapshots
 * GET  /schedule?ajax=snapshot&id=N → load specific snapshot's JSON
 * POST /schedule?ajax=add_zone   → create custom zone
 * POST /schedule?ajax=del_zone   → soft-delete custom zone
 *
 * Поскольку state — это один JSON blob, save/load работают одной транзакцией
 * без отдельного diff'а. Front-end держит state в JS и пишет целиком.
 */
class ScheduleController
{
    public function __construct(private readonly ScheduleStateService $service) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Per-page permission gate
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['schedule'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $query = $request->getQueryParams();
        $ajax  = (string)($query['ajax'] ?? '');
        if ($ajax !== '') {
            return $this->_handleAjax($ajax, $request, $response);
        }

        return $this->_handlePage($request, $response);
    }

    // ────────────────────────────────────────────────────────────────
    //  HTML page
    // ────────────────────────────────────────────────────────────────

    private function _handlePage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $periodFrom = $this->_normalizeDate($query['from'] ?? null)
            ?: date('Y-m-d', strtotime('monday this week'));
        $periodTo   = $this->_normalizeDate($query['to']   ?? null)
            ?: date('Y-m-d', strtotime($periodFrom . ' +13 days'));

        // Server-rendered bootstrap state (JS use this to hydrate without an
        // extra AJAX hop on first paint).
        $state     = $this->service->loadCurrent();
        $employees = $this->_buildEmployeeRoster();
        $halls     = $this->_buildHallList();
        $zones     = $this->service->listZones();
        $snapshots = $this->service->listSnapshots();

        $pageTitle    = 'График смен';
        $currentPath  = '/schedule';
        $headExtra    = '<link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens2">' . "\n"
                      . '<link rel="stylesheet" href="/assets/css/schedule.css?v=20260517_v7_persistence">';

        ob_start();
        require __DIR__ . '/../Views/schedule_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    // ────────────────────────────────────────────────────────────────
    //  AJAX
    // ────────────────────────────────────────────────────────────────

    private function _handleAjax(string $ajax, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $method = $request->getMethod();

        return match ($ajax) {
            'load'      => $this->_ajaxLoad($request, $response),
            'save'      => $this->_ajaxSave($request, $response),
            'snapshots' => $this->_ajaxListSnapshots($response),
            'snapshot'  => $this->_ajaxLoadSnapshot($request, $response),
            'del_snap'  => $this->_ajaxDeleteSnapshot($request, $response),
            'add_zone'  => $this->_ajaxAddZone($request, $response),
            'del_zone'  => $this->_ajaxDelZone($request, $response),
            default     => $this->_json($response, ['ok' => false, 'error' => 'Unknown ajax: ' . $ajax], 400),
        };
    }

    private function _ajaxLoad(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->_json($response, [
            'ok'         => true,
            'state'      => $this->service->loadCurrent(),
            'employees'  => $this->_buildEmployeeRoster(),
            'halls'      => $this->_buildHallList(),
            'zones'      => $this->service->listZones(),
            'snapshots'  => $this->service->listSnapshots(),
        ]);
    }

    private function _ajaxSave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'POST required'], 405);
        }
        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body) || !isset($body['state']) || !is_array($body['state'])) {
            return $this->_json($response, ['ok' => false, 'error' => 'Bad payload: state required'], 400);
        }
        $label = (string)($body['label'] ?? 'auto');
        $email = (string)($_SESSION['user_email'] ?? '');
        try {
            $id = $this->service->saveSnapshot($body['state'], $label, $email);
            return $this->_json($response, [
                'ok'        => true,
                'id'        => $id,
                'snapshots' => $this->service->listSnapshots(),
            ]);
        } catch (\Throwable $e) {
            return $this->_json($response, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function _ajaxListSnapshots(ResponseInterface $response): ResponseInterface
    {
        return $this->_json($response, [
            'ok' => true,
            'snapshots' => $this->service->listSnapshots(),
        ]);
    }

    private function _ajaxLoadSnapshot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = (int)($request->getQueryParams()['id'] ?? 0);
        if ($id <= 0) {
            return $this->_json($response, ['ok' => false, 'error' => 'id required'], 400);
        }
        $state = $this->service->loadSnapshot($id);
        if ($state === null) {
            return $this->_json($response, ['ok' => false, 'error' => 'snapshot not found'], 404);
        }
        return $this->_json($response, ['ok' => true, 'state' => $state]);
    }

    private function _ajaxDeleteSnapshot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'POST required'], 405);
        }
        $body = json_decode((string)$request->getBody(), true);
        $id   = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->_json($response, ['ok' => false, 'error' => 'id required'], 400);
        }
        $ok = $this->service->deleteSnapshot($id);
        return $this->_json($response, [
            'ok'        => $ok,
            'snapshots' => $this->service->listSnapshots(),
            'error'     => $ok ? null : 'cannot delete current snapshot',
        ]);
    }

    private function _ajaxAddZone(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'POST required'], 405);
        }
        $body = json_decode((string)$request->getBody(), true);
        $name = trim((string)($body['name'] ?? ''));
        $icon = trim((string)($body['icon'] ?? '🌿')) ?: '🌿';
        if ($name === '') {
            return $this->_json($response, ['ok' => false, 'error' => 'name required'], 400);
        }
        $id = $this->service->addZone($name, $icon);
        return $this->_json($response, [
            'ok'    => true,
            'id'    => $id,
            'zones' => $this->service->listZones(),
        ]);
    }

    private function _ajaxDelZone(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->_json($response, ['ok' => false, 'error' => 'POST required'], 405);
        }
        $body = json_decode((string)$request->getBody(), true);
        $id   = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->_json($response, ['ok' => false, 'error' => 'id required'], 400);
        }
        $this->service->deleteZone($id);
        return $this->_json($response, [
            'ok'    => true,
            'zones' => $this->service->listZones(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    //  Data builders — Poster halls + employees (с TODO для live Poster API)
    // ────────────────────────────────────────────────────────────────

    /**
     * TODO: replace with PosterApiClient `access.getEmployees` JOIN со staff_tags.
     * Сейчас отдаёт hardcoded список соответствующий демо-данным мокапа +
     * накладывает staff_tags из БД, если они есть.
     */
    private function _buildEmployeeRoster(): array
    {
        $hardcoded = [
            ['id' => 5,  'name' => 'Султан', 'poster_role' => 'bartender', 'tag' => 'Бар',       'can_senior_default' => true,  'rate' => 65000],
            ['id' => 7,  'name' => 'Оля',    'poster_role' => 'host',      'tag' => 'Хост',      'can_senior_default' => false, 'rate' => 55000],
            ['id' => 12, 'name' => 'Лёша',   'poster_role' => 'host',      'tag' => 'Хост',      'can_senior_default' => true,  'rate' => 60000],
            ['id' => 18, 'name' => 'Phai',   'poster_role' => 'waiter',    'tag' => 'Официант',  'can_senior_default' => true,  'rate' => 50000],
            ['id' => 19, 'name' => 'Long',   'poster_role' => 'waiter',    'tag' => 'Официант',  'can_senior_default' => false, 'rate' => 48000],
            ['id' => 22, 'name' => 'An',     'poster_role' => 'waiter',    'tag' => 'Баня',      'can_senior_default' => false, 'rate' => 55000],
            ['id' => 25, 'name' => 'Саша',   'poster_role' => 'waiter',    'tag' => 'Официант',  'can_senior_default' => false, 'rate' => 52000],
            ['id' => 26, 'name' => 'Вася',   'poster_role' => 'waiter',    'tag' => 'Официант',  'can_senior_default' => false, 'rate' => 50000],
        ];
        $tags = $this->service->getStaffTags();
        $out = [];
        foreach ($hardcoded as $e) {
            $uid = (int)$e['id'];
            $userTag = $tags[$uid] ?? null;
            $out[] = [
                'id'             => $uid,
                'name'           => $e['name'],
                'poster_role'    => $e['poster_role'],
                'tag'            => $userTag['custom_tag'] !== '' ? $userTag['custom_tag'] : $e['tag'],
                'in_schedule'    => $userTag ? $userTag['in_schedule']    : true,
                'can_be_senior'  => $userTag ? $userTag['can_be_senior']  : $e['can_senior_default'],
                'only_in_blocks' => $userTag['only_in_blocks'] ?? '',
                'rate_per_hour'  => $userTag && $userTag['rate_per_hour'] > 0 ? $userTag['rate_per_hour'] : $e['rate'],
            ];
        }
        return $out;
    }

    /**
     * TODO: replace with Poster `dash.getHalls` / `spots.getList`.
     * Сейчас hardcoded — три зала, два уже в дефолтных блоках.
     */
    private function _buildHallList(): array
    {
        return [
            ['id' => 1, 'name' => 'Главный зал', 'icon' => '🏛'],
            ['id' => 2, 'name' => 'Баня',        'icon' => '♨'],
            ['id' => 3, 'name' => 'Roma',        'icon' => '🌿'],
            ['id' => 4, 'name' => 'Терраса',     'icon' => '🏖'],
            ['id' => 5, 'name' => 'VIP-зал',     'icon' => '🍷'],
        ];
    }

    // ────────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────────

    private function _normalizeDate(?string $s): ?string
    {
        if (!is_string($s) || $s === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    private function _json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
