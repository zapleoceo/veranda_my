<?php

declare(strict_types=1);

namespace App\Schedule\Http;

use App\Schedule\Services\HeatmapBuilder;
use App\Schedule\Services\PeriodBuilder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin controller — two responsibilities:
 *   1. Decide between AJAX dispatch and HTML page render based on the
 *      query string.
 *   2. Resolve the right Action class for an AJAX command from the
 *      action map.
 *
 * Each AJAX endpoint is its own Action class (Single Responsibility).
 * Actions are NOT injected into the constructor — only their map of
 * names → class FQCNs is. The actual Action instance is resolved
 * lazily through the container on dispatch (Open/Closed: adding a new
 * AJAX endpoint = new class + one entry in the container, controller
 * untouched).
 */
final class ScheduleController
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly PeriodBuilder        $periodBuilder,
        private readonly HeatmapBuilder       $heatmapBuilder,
        private readonly JsonResponder        $json,
        private readonly ContainerInterface   $container,
        /** @var array<string, class-string> ajax-name → Action FQCN */
        private readonly array                $ajaxActionMap,
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
        return !is_array($perms) || !empty($perms['schedule']);
    }

    private function dispatchAjax(string $ajax, ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $cls = $this->ajaxActionMap[$ajax] ?? null;
        if ($cls === null) {
            return $this->json->fail($res, 'Unknown ajax: ' . $ajax, 404);
        }
        $action = $this->container->get($cls);  // lazy resolution
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
                      . '<link rel="stylesheet" href="/schedule/assets/css/schedule.css?v=20260520_perf">';

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
