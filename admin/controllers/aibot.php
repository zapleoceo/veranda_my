<?php

$ctx          = require __DIR__ . '/../../testai/bootstrap.php';
$settingsRepo = $ctx['settingsRepo'];
$kbRepo       = $ctx['kbRepo'];
$msgRepo      = $ctx['msgRepo'];
$dailyRepo    = $ctx['dailyRepo'];
$dailySvc     = $ctx['dailySvc'];
$announceSvc  = $ctx['announcementSvc'];
$responder    = $ctx['responder'];
$poster       = $ctx['poster'];
$gemini       = $ctx['gemini'];
$sanitizer    = $ctx['sanitizer'];
$fetcher      = $ctx['fetcher'];
$log          = $ctx['log'];
$cfg          = $ctx['cfg'];

$aibotDate = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aibotDate)) $aibotDate = date('Y-m-d');

// ─── JSON response helper ─────────────────────────────────────────────────────

$json = function (array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

$requirePost = function () use ($json): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $json(['ok' => false, 'error' => 'method_not_allowed'], 405);
};

// ─── AJAX dispatcher ─────────────────────────────────────────────────────────

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {

    // ── Status ───────────────────────────────────────────────────────────────
    if ($ajax === 'state') {
        $dbOk = false;
        try { $r = $ctx['db']->query("SELECT 1 AS ok")->fetch(); $dbOk = is_array($r) && (int)($r['ok'] ?? 0) === 1; } catch (\Throwable) {}
        $totals   = $msgRepo->getTotals();
        $counts   = $msgRepo->getCountsForDay($aibotDate);
        $dailyRow = $dailyRepo->getByDay($aibotDate);
        $logFile  = ($log instanceof \App\Classes\TestAI\Infra\Logger) ? $log->filePath() : '';
        $blockUntil = $settingsRepo->get('gemini_block_until');
        $nextUntil  = $settingsRepo->get('gemini_next_allowed_until');
        $blockTs    = $blockUntil !== '' ? (int)strtotime($blockUntil) : 0;
        $nextTs     = $nextUntil !== '' ? (int)strtotime($nextUntil) : 0;
        $blockRem   = ($blockTs > 0 && $blockTs > time()) ? ($blockTs - time()) : 0;
        $nextRem    = ($nextTs > 0 && $nextTs > time()) ? ($nextTs - time()) : 0;
        $blockRemaining = max($blockRem, $nextRem);
        $canCall = $gemini->canCall();
        $json([
            'ok'                  => true,
            'db_ok'               => $dbOk,
            'gemini_can_call'     => $canCall,
            'gemini_ready'        => ($canCall && $blockRemaining === 0) ? 1 : 0,
            'gemini_model'        => (string)$cfg->geminiModel,
            'daily_exists'        => $dailyRow ? 1 : 0,
            'raw_total'           => (int)($totals['raw_total'] ?? 0),
            'raw_last_received_at'=> (string)($totals['raw_last_received_at'] ?? ''),
            'day_count'           => (int)($counts['count'] ?? 0),
            'log_file'            => $logFile,
            'block_remaining'     => $blockRemaining,
            'identity_updated_at' => $settingsRepo->getWithMeta('bot_identity')['updated_at'] ?? '',
            'forbidden_updated_at'=> $settingsRepo->getWithMeta('bot_forbidden')['updated_at'] ?? '',
        ]);
    }

    if ($ajax === 'block_reset') {
        $requirePost();
        $settingsRepo->set('gemini_block_until', '');
        $settingsRepo->set('gemini_next_allowed_until', '');
        $json(['ok' => true]);
    }

    // ── Logs ─────────────────────────────────────────────────────────────────
    if ($ajax === 'log_tail') {
        $n    = max(1, min(400, (int)($_GET['n'] ?? 120)));
        $file = ($log instanceof \App\Classes\TestAI\Infra\Logger) ? $log->filePath() : '';
        if ($file === '' || !is_file($file)) $json(['ok' => false, 'error' => 'no_log'], 400);
        $size = (int)@filesize($file);
        if ($size <= 0) $json(['ok' => true, 'tail' => '']);
        $fh  = @fopen($file, 'rb');
        if (!$fh) $json(['ok' => false, 'error' => 'open_failed'], 500);
        @fseek($fh, -min(260000, $size), SEEK_END);
        $buf = (string)@fread($fh, 260000);
        @fclose($fh);
        $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $buf))));
        $json(['ok' => true, 'tail' => implode("\n", array_slice($lines, max(0, count($lines) - $n)))]);
    }

    // ── Settings: identity ────────────────────────────────────────────────────
    if ($ajax === 'identity_get') {
        $json(['ok' => true, 'value' => $settingsRepo->get('bot_identity'), 'updated_at' => $settingsRepo->getWithMeta('bot_identity')['updated_at'] ?? '']);
    }
    if ($ajax === 'identity_save') {
        $requirePost();
        $settingsRepo->set('bot_identity', (string)($_POST['value'] ?? ''));
        $json(['ok' => true, 'updated_at' => $settingsRepo->getWithMeta('bot_identity')['updated_at'] ?? '']);
    }

    // ── Settings: forbidden topics ────────────────────────────────────────────
    if ($ajax === 'forbidden_get') {
        $json(['ok' => true, 'value' => $settingsRepo->get('bot_forbidden'), 'updated_at' => $settingsRepo->getWithMeta('bot_forbidden')['updated_at'] ?? '']);
    }
    if ($ajax === 'forbidden_save') {
        $requirePost();
        $settingsRepo->set('bot_forbidden', (string)($_POST['value'] ?? ''));
        $json(['ok' => true, 'updated_at' => $settingsRepo->getWithMeta('bot_forbidden')['updated_at'] ?? '']);
    }

    // ── KB list / get / save / delete ─────────────────────────────────────────
    if ($ajax === 'kb_list') {
        $json(['ok' => true, 'items' => $kbRepo->list(100, 0)]);
    }

    if ($ajax === 'kb_get') {
        $row = $kbRepo->getById((int)($_GET['id'] ?? 0));
        if (!$row) $json(['ok' => false, 'error' => 'not_found'], 404);
        $json(['ok' => true, 'item' => $row]);
    }

    if ($ajax === 'kb_save') {
        $requirePost();
        $id       = (int)($_POST['id'] ?? 0);
        $title    = (string)($_POST['title'] ?? '');
        $content  = (string)($_POST['content'] ?? '');
        $url      = (string)($_POST['source_url'] ?? '');
        $access   = (string)($_POST['access'] ?? 'public');
        $isActive = (int)($_POST['is_active'] ?? 1);
        $newId    = $kbRepo->upsert($id > 0 ? $id : null, $title, $content, $url, $access, $isActive);
        if ($newId <= 0) $json(['ok' => false, 'error' => 'save_failed'], 500);
        $json(['ok' => true, 'id' => $newId, 'item' => $kbRepo->getById($newId)]);
    }

    if ($ajax === 'kb_delete') {
        $requirePost();
        $ok = $kbRepo->delete((int)($_POST['id'] ?? 0));
        $json(['ok' => $ok], $ok ? 200 : 400);
    }

    // ── KB import from URL ────────────────────────────────────────────────────
    if ($ajax === 'kb_import_url') {
        $requirePost();
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url === '') $json(['ok' => false, 'error' => 'missing_url'], 400);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');

        $text = $fetcher->fetch($url, 20000);
        if ($text === '') $json(['ok' => false, 'error' => 'fetch_failed_or_not_allowed'], 400);

        // Try to extract page title via curl
        $title = $url;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: veranda-ai-admin/2.0']);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (is_string($raw) && preg_match('/<title[^>]*>(.*?)<\/title>/is', $raw, $m)) {
            $t = trim(html_entity_decode((string)($m[1] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($t !== '') $title = $t;
        }

        $newId = $kbRepo->upsert(null, $title, $text, $url, 'public', 1);
        if ($newId <= 0) $json(['ok' => false, 'error' => 'save_failed'], 500);
        $json(['ok' => true, 'id' => $newId, 'item' => $kbRepo->getById($newId)]);
    }

    // ── Bot test ──────────────────────────────────────────────────────────────
    if ($ajax === 'bot_test') {
        $requirePost();
        $q = trim((string)($_POST['question'] ?? ''));
        if ($q === '') $json(['ok' => false, 'error' => 'missing_question'], 400);
        // Admin test is always authorized
        $html = $responder->respond($q, [], true);
        $json(['ok' => true, 'html' => $html]);
    }

    // ── Poster availability ───────────────────────────────────────────────────
    if ($ajax === 'poster_refresh') {
        if (!$poster->isConfigured()) $json(['ok' => false, 'error' => 'poster_not_configured'], 400);
        $text = $poster->getAvailabilityText(forceRefresh: true);
        $json(['ok' => true, 'text' => $text, 'updated_at' => $poster->lastUpdatedAt()]);
    }
    if ($ajax === 'poster_status') {
        $json(['ok' => true,
            'configured' => $poster->isConfigured(),
            'text'       => $poster->getAvailabilityText(),
            'updated_at' => $poster->lastUpdatedAt(),
        ]);
    }

    // ── Announce / daily (operations) ─────────────────────────────────────────
    if ($ajax === 'announce_get') {
        $json(['ok' => true, 'date' => $aibotDate, 'html' => $announceSvc->getCached($aibotDate)]);
    }
    if ($ajax === 'announce_generate') {
        $json(['ok' => true, 'date' => $aibotDate, 'html' => $announceSvc->generate($aibotDate)]);
    }
    if ($ajax === 'daily_get') {
        $row = $dailyRepo->getByDay($aibotDate);
        $json(['ok' => true, 'exists' => $row ? 1 : 0, 'summary_text' => $row ? (string)($row['summary_text'] ?? '') : '', 'created_at' => $row ? (string)($row['created_at'] ?? '') : '']);
    }
    if ($ajax === 'daily_run') {
        $ok  = $dailySvc->runDay($aibotDate);
        $row = $dailyRepo->getByDay($aibotDate);
        $json(['ok' => $ok, 'exists' => $row ? 1 : 0, 'summary_text' => $row ? (string)($row['summary_text'] ?? '') : ''], $ok ? 200 : 400);
    }

    $json(['ok' => false, 'error' => 'unknown_action'], 400);
}

// ─── Page data for view ───────────────────────────────────────────────────────

$aibotIdentity  = $settingsRepo->get('bot_identity');
$aibotForbidden = $settingsRepo->get('bot_forbidden');
$aibotKbItems   = $kbRepo->list(100, 0);
