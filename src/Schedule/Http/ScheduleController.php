<?php

declare(strict_types=1);

namespace App\Schedule\Http;

use App\Schedule\Http\Actions\AddZoneAction;
use App\Schedule\Http\Actions\DebugPosterAction;
use App\Schedule\Http\Actions\DeleteSnapshotAction;
use App\Schedule\Http\Actions\DeleteZoneAction;
use App\Schedule\Http\Actions\ListSnapshotsAction;
use App\Schedule\Http\Actions\LoadAction;
use App\Schedule\Http\Actions\LoadSnapshotAction;
use App\Schedule\Http\Actions\ReloadPosterAction;
use App\Schedule\Http\Actions\RenameSnapshotAction;
use App\Schedule\Http\Actions\SaveAction;
use App\Schedule\Http\Actions\SaveStaffTagsAction;
use App\Schedule\Http\Actions\SaveVersionAction;
use App\Schedule\Services\HeatmapBuilder;
use App\Schedule\Services\PeriodBuilder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin controller — page render or AJAX-dispatch. Every AJAX endpoint
 * is its own Action class (Single Responsibility); the controller's
 * dispatchAjax just routes to the right one via a name→action map
 * assembled from the constructor-injected instances.
 *
 * Adding a new AJAX endpoint:
 *   1. New `App\Schedule\Http\Actions\XxxAction` class.
 *   2. Add the property in the constructor (or to the wider DI
 *      container if you want it lazy).
 *   3. Add one entry to `$this->ajaxActions` initialiser below.
 *
 * (Earlier iteration injected `ContainerInterface` + a class-string
 * map for lazy resolution, but PHP-DI's autowiring of the controller
 * via the factory was unreliable in production — fell back to the
 * boring eager-inject pattern that works.)
 */
final class ScheduleController
{
    /** @var array<string, callable> */
    private readonly array $ajaxActions;

    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly PeriodBuilder        $periodBuilder,
        private readonly HeatmapBuilder       $heatmapBuilder,
        private readonly JsonResponder        $json,
        LoadAction            $load,
        SaveAction            $save,
        SaveVersionAction     $saveVersion,
        ListSnapshotsAction   $listSnapshots,
        LoadSnapshotAction    $loadSnapshot,
        DeleteSnapshotAction  $deleteSnapshot,
        RenameSnapshotAction  $renameSnapshot,
        AddZoneAction         $addZone,
        DeleteZoneAction      $deleteZone,
        SaveStaffTagsAction   $saveStaffTags,
        ReloadPosterAction    $reloadPoster,
        DebugPosterAction     $debugPoster,
    ) {
        $this->ajaxActions = [
            'load'            => $load,
            'save'            => $save,
            'save_version'    => $saveVersion,
            'snapshots'       => $listSnapshots,
            'snapshot'        => $loadSnapshot,
            'del_snap'        => $deleteSnapshot,
            'rename_snap'     => $renameSnapshot,
            'add_zone'        => $addZone,
            'del_zone'        => $deleteZone,
            'save_staff_tags' => $saveStaffTags,
            'reload_poster'   => $reloadPoster,
            'debug_poster'    => $debugPoster,
        ];
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->canAccess()) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $ajax = (string) ($request->getQueryParams()['ajax'] ?? '');
        return $ajax !== ''
            ? $this->dispatchAjax($ajax, $request, $response)
            : $this->renderPage($request, $response);
    }

    /**
     * GET /schedule/v/{code} — public, no auth required.
     * Renders a stripped-down read-only view of a named version: the
     * grid + the hourly heatmap. NO salary, NO totals, NO controls —
     * just the people data.
     */
    public function publicVersion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        $code = (string) ($args['code'] ?? '');
        $snap = $this->service->loadByShareCode($code);
        if ($snap === null) {
            $response->getBody()->write('Версия не найдена');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        // Derive the period from the actual shift dates in the snapshot.
        // Empty state → blank-but-valid 2-week window so the page still renders.
        $shifts = (array) ($snap['state']['shifts'] ?? []);
        $isos   = array_keys($shifts);
        sort($isos);
        if ($isos !== []) {
            $periodFrom = (string) reset($isos);
            $periodTo   = (string) end($isos);
        } else {
            $range = PeriodBuilder::defaultRange();
            $periodFrom = $range['from'];
            $periodTo   = $range['to'];
        }

        $state     = $snap['state'];
        $employees = $this->service->fetchEmployees();
        $halls     = $this->service->fetchHalls();
        $days      = $this->periodBuilder->build($periodFrom, $periodTo);
        $heatmap   = $this->heatmapBuilder->build($state['blocks'] ?? [], $shifts, $days);

        $viewVars = compact('state', 'employees', 'halls', 'days', 'heatmap', 'periodFrom', 'periodTo')
                  + ['versionLabel' => $snap['label'], 'versionCreatedAt' => $snap['created_at']];
        extract($viewVars, EXTR_SKIP);

        ob_start();
        require __DIR__ . '/../Views/public.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
                        ->withHeader('Cache-Control', 'private, max-age=300');
    }

    private function canAccess(): bool
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        // Strict: missing/corrupt session denies. (Was permissive — if
        // ever a route bypassed AuthMiddleware, the door was open.)
        if (!is_array($perms)) return false;
        return !empty($perms['schedule']);
    }

    private function dispatchAjax(string $ajax, ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $action = $this->ajaxActions[$ajax] ?? null;
        if ($action === null) {
            return $this->json->fail($res, 'Unknown ajax: ' . $ajax, 404);
        }
        return $action($req, $res);
    }

    private function renderPage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query      = $request->getQueryParams();
        $periodFrom = PeriodBuilder::normalizeDate($query['from'] ?? null);
        $periodTo   = PeriodBuilder::normalizeDate($query['to']   ?? null);
        if ($periodFrom === null || $periodTo === null) {
            $range = PeriodBuilder::defaultRange();
            $periodFrom ??= $range['from'];
            $periodTo   ??= $range['to'];
        }

        $loaded    = $this->service->loadCurrent();
        $state     = $loaded['state'];
        $stateVer  = (int) ($loaded['version'] ?? 0);
        $employees = $this->service->fetchEmployees();
        $halls     = $this->service->fetchHalls();
        $zones     = $this->service->listZones();
        $snapshots = $this->service->listSnapshots();

        $days    = $this->periodBuilder->build($periodFrom, $periodTo);
        $heatmap = $this->heatmapBuilder->build($state['blocks'] ?? [], (array) ($state['shifts'] ?? []), $days);

        $pageTitle    = 'График смен';
        $currentPath  = '/schedule';
        $headExtra    = '<link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens2">' . "\n"
                      . '<link rel="stylesheet" href="/schedule/assets/css/schedule.css?v=20260521_concurrency">';

        // Variables exposed to the view template
        $viewVars = compact(
            'state', 'stateVer', 'employees', 'halls', 'zones', 'snapshots',
            'periodFrom', 'periodTo', 'days', 'heatmap'
        );
        extract($viewVars, EXTR_SKIP);

        ob_start();
        require __DIR__ . '/../Views/content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
