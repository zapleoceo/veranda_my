<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../src/classes/PosterAdminAjax.php';

use App\Payday2\LocalSettings;
use App\Classes\PosterAdminAjax;

final class PosterAdminEditCheckTestModel
{
    public const PAY_KEYS = ['payedCash', 'payedCard', 'payedCert', 'payedEwallet', 'payedBonus', 'payedThirdParty'];

    public static function normalizeParams(array $p): array
    {
        $out = [];
        foreach (self::PAY_KEYS as $k) {
            $out[$k] = self::i($p[$k] ?? 0);
        }
        $out['usesPayedCert'] = ((int)($p['usesPayedCert'] ?? 0) === 1) ? 1 : 0;
        $sum = 0;
        foreach (self::PAY_KEYS as $k) $sum += (int)$out[$k];
        $out['payedSum'] = $sum;
        return $out;
    }

    public static function totalFromParams(array $p): int
    {
        $v = self::i($p['payedSum'] ?? 0);
        if ($v > 0) return $v;
        $sum = 0;
        foreach (self::PAY_KEYS as $k) $sum += self::i($p[$k] ?? 0);
        return $sum;
    }

    public static function buildCaseParams(array $baseParams, int $total, array $parts, int $usesPayedCert): array
    {
        $out = $baseParams;
        foreach (self::PAY_KEYS as $k) $out[$k] = 0;
        foreach ($parts as $k => $v) {
            if (!in_array($k, self::PAY_KEYS, true)) continue;
            $out[$k] = self::i($v);
        }
        $sum = 0;
        foreach (self::PAY_KEYS as $k) $sum += self::i($out[$k] ?? 0);
        $delta = $total - $sum;
        $lastKey = null;
        foreach (array_keys($parts) as $k) {
            if (in_array($k, self::PAY_KEYS, true)) $lastKey = $k;
        }
        if ($lastKey !== null && $delta !== 0) {
            $out[$lastKey] = max(0, self::i($out[$lastKey] ?? 0) + $delta);
        }
        $sum2 = 0;
        foreach (self::PAY_KEYS as $k) $sum2 += self::i($out[$k] ?? 0);
        $out['payedSum'] = $sum2;
        $out['usesPayedCert'] = ($usesPayedCert === 1) ? 1 : 0;
        return $out;
    }

    private static function i(mixed $v): int
    {
        $n = (int)$v;
        return $n < 0 ? 0 : $n;
    }
}

final class PosterAdminEditCheckTesterService
{
    private PosterAdminAjax $client;
    private int $txId;
    private array $baseParams;
    private int $total;
    private int $origUsesPayedCert;

    public function __construct(PosterAdminAjax $client, int $txId)
    {
        if ($txId <= 0) {
            throw new RuntimeException('txId должен быть > 0');
        }
        $this->client = $client;
        $this->txId = $txId;
        $this->baseParams = $this->client->getActions($txId);
        $this->total = PosterAdminEditCheckTestModel::totalFromParams($this->baseParams);
        $this->origUsesPayedCert = ((int)($this->baseParams['usesPayedCert'] ?? 0) === 1) ? 1 : 0;
        if ($this->total <= 0) {
            throw new RuntimeException('Не удалось определить сумму чека (payedSum=0)');
        }
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function run(): array
    {
        $cases = $this->buildCases();
        $results = [];

        $restoreParams = $this->baseParams;
        foreach (PosterAdminEditCheckTestModel::PAY_KEYS as $k) {
            $restoreParams[$k] = (int)($this->baseParams[$k] ?? 0);
        }
        $restoreParams['payedSum'] = PosterAdminEditCheckTestModel::totalFromParams($restoreParams);
        $restoreParams['usesPayedCert'] = $this->origUsesPayedCert;

        try {
            foreach ($cases as $case) {
                $results[] = $this->runOne($case);
            }
        } finally {
            try {
                $this->client->editCheck($this->txId, $restoreParams);
            } catch (\Throwable) {
            }
        }

        return [
            'txId' => $this->txId,
            'total' => $this->total,
            'cases' => $results,
        ];
    }

    private function runOne(array $case): array
    {
        $sent = $case['params'];
        $sentNorm = PosterAdminEditCheckTestModel::normalizeParams($sent);
        $sentNonZero = [];
        foreach (PosterAdminEditCheckTestModel::PAY_KEYS as $k) {
            if ((int)$sentNorm[$k] > 0) $sentNonZero[$k] = (int)$sentNorm[$k];
        }

        $res = [
            'name' => (string)($case['name'] ?? ''),
            'nonZeroCount' => count($sentNonZero),
            'sent' => $sentNonZero,
            'usesPayedCert' => (int)($sentNorm['usesPayedCert'] ?? 0),
            'sentSum' => (int)($sentNorm['payedSum'] ?? 0),
            'ok' => false,
            'poster' => null,
            'error' => null,
            'after' => null,
        ];

        if ((int)$sentNorm['payedSum'] !== $this->total) {
            $res['error'] = 'internal: sentSum != total';
            return $res;
        }
        if ($res['nonZeroCount'] > 3) {
            $res['error'] = 'internal: more than 3 non-zero pay fields';
            return $res;
        }

        try {
            $posterRes = $this->client->editCheck($this->txId, $sent);
            $res['poster'] = $this->trimPosterResult($posterRes);
            $res['ok'] = (bool)($posterRes['status'] ?? false);
        } catch (\Throwable $e) {
            $res['error'] = $this->trimError($e->getMessage());
            return $res;
        }

        try {
            $afterParams = $this->client->getActions($this->txId);
            $afterNorm = PosterAdminEditCheckTestModel::normalizeParams($afterParams);
            $afterNonZero = [];
            foreach (PosterAdminEditCheckTestModel::PAY_KEYS as $k) {
                if ((int)$afterNorm[$k] > 0) $afterNonZero[$k] = (int)$afterNorm[$k];
            }
            $res['after'] = [
                'sent' => $afterNonZero,
                'usesPayedCert' => (int)($afterNorm['usesPayedCert'] ?? 0),
                'sum' => (int)($afterNorm['payedSum'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $res['after'] = ['error' => $this->trimError($e->getMessage())];
        }

        return $res;
    }

    private function buildCases(): array
    {
        $total = $this->total;
        $base = $this->baseParams;
        $cases = [];

        foreach (PosterAdminEditCheckTestModel::PAY_KEYS as $k) {
            $p = PosterAdminEditCheckTestModel::buildCaseParams($base, $total, [$k => $total], $this->origUsesPayedCert);
            $cases = array_merge($cases, $this->withUsesPayedCertVariants("ONE: {$k}", $p));
        }

        $pairs = [];
        $keys = PosterAdminEditCheckTestModel::PAY_KEYS;
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $pairs[] = [$keys[$i], $keys[$j]];
            }
        }
        foreach ($pairs as [$a, $b]) {
            $half = intdiv($total, 2);
            $p1 = PosterAdminEditCheckTestModel::buildCaseParams($base, $total, [$a => $half, $b => $total - $half], $this->origUsesPayedCert);
            $cases = array_merge($cases, $this->withUsesPayedCertVariants("TWO: {$a}+{$b} half", $p1));

            $x = min(1000, $total);
            $p2 = PosterAdminEditCheckTestModel::buildCaseParams($base, $total, [$a => $x, $b => $total - $x], $this->origUsesPayedCert);
            $cases = array_merge($cases, $this->withUsesPayedCertVariants("TWO: {$a}+{$b} 1000/rest", $p2));
        }

        $triples = [];
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                for ($k = $j + 1; $k < count($keys); $k++) {
                    $triples[] = [$keys[$i], $keys[$j], $keys[$k]];
                }
            }
        }
        foreach ($triples as [$a, $b, $c]) {
            $x = min(1000, $total);
            $y = min(1000, max(0, $total - $x));
            $p1 = PosterAdminEditCheckTestModel::buildCaseParams($base, $total, [$a => $x, $b => $y, $c => max(0, $total - $x - $y)], $this->origUsesPayedCert);
            $cases = array_merge($cases, $this->withUsesPayedCertVariants("THREE: {$a}+{$b}+{$c} 1000/1000/rest", $p1));

            $p10 = (int)floor($total * 0.10);
            $p20 = (int)floor($total * 0.20);
            $p1b = PosterAdminEditCheckTestModel::buildCaseParams($base, $total, [$a => $p10, $b => $p20, $c => max(0, $total - $p10 - $p20)], $this->origUsesPayedCert);
            $cases = array_merge($cases, $this->withUsesPayedCertVariants("THREE: {$a}+{$b}+{$c} 10/20/70", $p1b));
        }

        return $cases;
    }

    private function withUsesPayedCertVariants(string $name, array $params): array
    {
        $hasCert = ((int)($params['payedCert'] ?? 0) > 0);
        if (!$hasCert) {
            return [[
                'name' => $name,
                'params' => $params,
            ]];
        }
        $p0 = $params;
        $p0['usesPayedCert'] = 0;
        $p1 = $params;
        $p1['usesPayedCert'] = 1;
        return [
            ['name' => $name . ' usesPayedCert=0', 'params' => $p0],
            ['name' => $name . ' usesPayedCert=1', 'params' => $p1],
        ];
    }

    private function trimPosterResult(array $r): array
    {
        if (isset($r['raw'])) {
            $r['raw'] = mb_substr((string)$r['raw'], 0, 300);
        }
        if (isset($r['response']) && is_string($r['response'])) {
            $r['response'] = mb_substr($r['response'], 0, 300);
        }
        if (isset($r['message']) && is_string($r['message'])) {
            $r['message'] = mb_substr($r['message'], 0, 300);
        }
        return $r;
    }

    private function trimError(string $msg): string
    {
        $s = trim($msg);
        $s = preg_replace('/\s+/', ' ', $s) ?: $s;
        return mb_substr($s, 0, 350);
    }
}

final class PosterAdminEditCheckTesterController
{
    public function handle(): void
    {
        $txId = (int)($_GET['txId'] ?? 18029);
        if ($txId <= 0) $txId = 18029;
        $run = (string)($_GET['run'] ?? '') === '1';

        $payload = LocalSettings::merged();
        $cfg = isset($payload['poster_admin']) && is_array($payload['poster_admin']) ? $payload['poster_admin'] : [];

        $view = new PosterAdminEditCheckTesterView();
        $view->render($txId, $run, $cfg);
    }
}

final class PosterAdminEditCheckTesterView
{
    public function render(int $txId, bool $run, array $cfg): void
    {
        $error = '';
        $data = null;

        $cfgNormalized = [
            'account' => trim((string)($cfg['account'] ?? '')),
            'pos_session' => trim((string)($cfg['pos_session'] ?? '')),
            'ssid' => trim((string)($cfg['ssid'] ?? '')),
            'csrf' => trim((string)($cfg['csrf'] ?? '')),
            'user_agent' => trim((string)($cfg['user_agent'] ?? '')),
        ];

        if ($run) {
            try {
                $client = new PosterAdminAjax($cfgNormalized);
                $svc = new PosterAdminEditCheckTesterService($client, $txId);
                $data = $svc->run();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ru"><head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Payday2: Poster Admin edit_check tester</title>';
        echo '<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">';
        echo '<style>
            body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:18px;color:#111827;}
            .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
            input{padding:8px 10px;border:1px solid #d1d5db;border-radius:10px;min-width:160px}
            a.btn,button{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;border:1px solid #d1d5db;background:#fff;color:#111827;text-decoration:none;cursor:pointer;font-weight:700}
            a.btn.primary,button.primary{background:#111827;color:#fff;border-color:#111827}
            .muted{color:#6b7280}
            .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:12px;margin:12px 0}
            table{border-collapse:collapse;width:100%;font-size:13px}
            th,td{border:1px solid #e5e7eb;padding:6px 8px;vertical-align:top}
            th{background:#f9fafb;text-align:left}
            .ok{color:#065f46;font-weight:800}
            .bad{color:#991b1b;font-weight:800}
            code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}
        </style>';
        echo '</head><body>';
        echo '<h2 style="margin:0 0 10px 0;">Poster Admin edit_check tester</h2>';
        echo '<div class="muted" style="margin-bottom:14px;">Тесты редактирования чека через Poster Admin cookies из настроек Payday2. Не раскрывает cookies.</div>';

        echo '<div class="row">';
        echo '<form method="get" action="" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0;">';
        echo '<input type="hidden" name="tool" value="poster_admin_edit_check_tester">';
        echo '<label class="muted">txId</label>';
        echo '<input type="number" name="txId" value="' . (int)$txId . '" min="1" step="1">';
        echo '<button class="primary" type="submit" name="run" value="1">Запустить</button>';
        echo '<a class="btn" href="/payday2">Открыть Payday2</a>';
        echo '</form>';
        echo '</div>';

        if ($cfgNormalized['account'] === '' || $cfgNormalized['pos_session'] === '' || $cfgNormalized['ssid'] === '' || $cfgNormalized['csrf'] === '') {
            echo '<div class="err">Poster Admin cookies не настроены. Открой Payday2 → Settings → Poster Admin (Edit check) и заполни account_url / ssid / csrf_cookie_poster / pos_session.</div>';
        }

        if ($error !== '') {
            echo '<div class="err">' . htmlspecialchars($error) . '</div>';
        }

        if (is_array($data) && isset($data['cases']) && is_array($data['cases'])) {
            $cases = $data['cases'];
            $ok = 0;
            $bad = 0;
            $sumMismatch = 0;
            foreach ($cases as $c) {
                if (!is_array($c)) continue;
                if (!empty($c['ok'])) $ok++;
                else $bad++;
                $afterSum = is_array($c['after'] ?? null) ? (int)($c['after']['sum'] ?? 0) : 0;
                if ($afterSum !== 0 && $afterSum !== (int)($data['total'] ?? 0)) $sumMismatch++;
            }
            echo '<div class="row" style="margin-top:10px;">';
            echo '<div><b>txId:</b> ' . (int)($data['txId'] ?? 0) . '</div>';
            echo '<div><b>total:</b> ' . (int)($data['total'] ?? 0) . '</div>';
            echo '<div><b>ok:</b> <span class="ok">' . (int)$ok . '</span></div>';
            echo '<div><b>fail:</b> <span class="bad">' . (int)$bad . '</span></div>';
            echo '<div><b>sum mismatch after:</b> ' . (int)$sumMismatch . '</div>';
            echo '</div>';

            echo '<table>';
            echo '<thead><tr>';
            echo '<th>Case</th>';
            echo '<th>Fields (<=3)</th>';
            echo '<th>usesPayedCert</th>';
            echo '<th>Poster</th>';
            echo '<th>After</th>';
            echo '<th>Error</th>';
            echo '</tr></thead><tbody>';
            foreach ($cases as $c) {
                if (!is_array($c)) continue;
                $name = htmlspecialchars((string)($c['name'] ?? ''));
                $fields = htmlspecialchars(json_encode($c['sent'] ?? [], JSON_UNESCAPED_UNICODE));
                $upc = (int)($c['usesPayedCert'] ?? 0);
                $poster = '';
                if (isset($c['poster']) && $c['poster'] !== null) {
                    $poster = htmlspecialchars(json_encode($c['poster'], JSON_UNESCAPED_UNICODE));
                }
                $after = '';
                if (isset($c['after']) && is_array($c['after'])) {
                    $after = htmlspecialchars(json_encode($c['after'], JSON_UNESCAPED_UNICODE));
                }
                $err = htmlspecialchars((string)($c['error'] ?? ''));
                $okCls = !empty($c['ok']) ? 'ok' : 'bad';
                echo '<tr>';
                echo '<td><span class="' . $okCls . '">' . $name . '</span></td>';
                echo '<td><code>' . $fields . '</code></td>';
                echo '<td>' . $upc . '</td>';
                echo '<td><code>' . $poster . '</code></td>';
                echo '<td><code>' . $after . '</code></td>';
                echo '<td><code>' . $err . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</body></html>';
    }
}

(new PosterAdminEditCheckTesterController())->handle();
