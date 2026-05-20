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
use App\Schedule\Http\Actions\SaveAction;
use App\Schedule\Http\Actions\SaveStaffTagsAction;
use App\Schedule\Services\HeatmapBuilder;
use App\Schedule\Services\PeriodBuilder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin controller: page render or AJAX-dispatch. Every AJAX endpoint is its
 * own Action class (Single Responsibility) — they're constructed via the
 * DI container. The controller's job is just to choose the right one.
 */
final class ScheduleController
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly PeriodBuilder        $periodBuilder,
        private readonly HeatmapBuilder       $heatmapBuilder,
        private readonly JsonResponder        $json,
        // Actions: one per AJAX command
        private readonly LoadAction            $loadAction,
        private readonly SaveAction            $saveAction,
        private readonly ListSnapshotsAction   $listSnapshotsAction,
        private readonly LoadSnapshotAction    $loadSnapshotAction,
        private readonly DeleteSnapshotAction  $deleteSnapshotAction,
        private readonly AddZoneAction         $addZoneAction,
        private readonly DeleteZoneAction      $deleteZoneAction,
        private readonly SaveStaffTagsAction   $saveStaffTagsAction,
        private readonly ReloadPosterAction    $reloadPosterAction,
        private readonly DebugPosterAction     $debugPosterAction,
    ) {}

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

    private function canAccess(): bool
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        return !is_array($perms) || !empty($perms['schedule']);
    }

    private function dispatchAjax(string $ajax, ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $action = match ($ajax) {
            'load'            => $this->loadAction,
            'save'            => $this->saveAction,
            'snapshots'       => $this->listSnapshotsAction,
            'snapshot'        => $this->loadSnapshotAction,
            'del_snap'        => $this->deleteSnapshotAction,
            'add_zone'        => $this->addZoneAction,
            'del_zone'        => $this->deleteZoneAction,
            'save_staff_tags' => $this->saveStaffTagsAction,
            'reload_poster'   => $this->reloadPosterAction,
            'debug_poster'    => $this->debugPosterAction,
            default           => null,
        };
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

        $state     = $this->service->loadCurrent();
        $employees = $this->service->fetchEmployees();
        $halls     = $this->service->fetchHalls();
        $zones     = $this->service->listZones();
        $snapshots = $this->service->listSnapshots();

        $days    = $this->periodBuilder->build($periodFrom, $periodTo);
        $heatmap = $this->heatmapBuilder->build($state['blocks'] ?? [], (array) ($state['shifts'] ?? []), $days);

        $pageTitle    = 'График смен';
        $currentPath  = '/schedule';
        $headExtra    = '<link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens2">' . "\n"
                      . '<link rel="stylesheet" href="/schedule/assets/css/schedule.css?v=20260520_head2row">';

        // Variables exposed to the view template
        $viewVars = compact(
            'state', 'employees', 'halls', 'zones', 'snapshots',
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
