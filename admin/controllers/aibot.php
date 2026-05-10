<?php

$ctx = require __DIR__ . '/../../testai/bootstrap.php';
$cfg = $ctx['cfg'];
$db2 = $ctx['db'];
$rawRepo = $ctx['rawRepo'];
$dailyRepo = $ctx['dailyRepo'];
$dailySvc = $ctx['dailySvc'];
$settingsRepo = $ctx['settingsRepo'];
$kbRepo = $ctx['kbRepo'];
$gemini = $ctx['gemini'];
$announcementSvc = $ctx['announcementSvc'];
$log = $ctx['log'] ?? null;

$aibotDate = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aibotDate)) $aibotDate = date('Y-m-d');

$respondJson = function (array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {
    if ($ajax === 'state') {
        $dbOk = false;
        try {
            $row = $db2->query("SELECT 1 AS ok")->fetch();
            $dbOk = is_array($row) && (int)($row['ok'] ?? 0) === 1;
        } catch (\Throwable $e) {}

        $rawTotals = $rawRepo->getTotals();
        $counts = $rawRepo->getCountsForDay($aibotDate);
        $dailyRow = $dailyRepo->getByDay($aibotDate);
        $promptRow = $settingsRepo->getBotPrompt();
        $file = ($log instanceof \App\Classes\TestAILogger) ? $log->filePath() : '';

        $respondJson([
            'ok' => true,
            'date' => $aibotDate,
            'db_ok' => $dbOk,
            'gemini_can_call' => $gemini->canCall(),
            'gemini_model' => (string)$cfg->geminiModel,
            'gemini_proxy_base' => (string)$cfg->geminiProxyBase(),
            'raw_total' => (int)($rawTotals['raw_total'] ?? 0),
            'raw_last_received_at' => (string)($rawTotals['raw_last_received_at'] ?? ''),
            'day_count' => (int)($counts['count'] ?? 0),
            'day_with_media' => (int)($counts['with_media'] ?? 0),
            'day_with_media_text' => (int)($counts['with_media_text'] ?? 0),
            'daily_exists' => $dailyRow ? 1 : 0,
            'prompt_updated_at' => (string)($promptRow['updated_at'] ?? ''),
            'log_file' => $file,
        ]);
    }

    if ($ajax === 'announce_get') {
        $html = $announcementSvc->getCached($aibotDate);
        $respondJson(['ok' => true, 'date' => $aibotDate, 'html' => $html]);
    }

    if ($ajax === 'announce_generate') {
        $html = $announcementSvc->generate($aibotDate);
        $respondJson(['ok' => true, 'date' => $aibotDate, 'html' => $html]);
    }

    if ($ajax === 'daily_get') {
        $row = $dailyRepo->getByDay($aibotDate);
        $respondJson([
            'ok' => true,
            'date' => $aibotDate,
            'exists' => $row ? 1 : 0,
            'summary_text' => $row ? (string)($row['summary_text'] ?? '') : '',
            'events_json' => $row ? (string)($row['events_json'] ?? '') : '',
            'created_at' => $row ? (string)($row['created_at'] ?? '') : '',
        ]);
    }

    if ($ajax === 'daily_run') {
        $okRun = $dailySvc->runDay($aibotDate);
        $row = $dailyRepo->getByDay($aibotDate);
        $respondJson([
            'ok' => $okRun ? true : false,
            'date' => $aibotDate,
            'exists' => $row ? 1 : 0,
            'summary_text' => $row ? (string)($row['summary_text'] ?? '') : '',
            'events_json' => $row ? (string)($row['events_json'] ?? '') : '',
            'created_at' => $row ? (string)($row['created_at'] ?? '') : '',
        ], $okRun ? 200 : 400);
    }

    if ($ajax === 'prompt_get') {
        $row = $settingsRepo->getBotPrompt();
        $respondJson(['ok' => true, 'prompt' => (string)($row['prompt'] ?? ''), 'updated_at' => (string)($row['updated_at'] ?? '')]);
    }

    if ($ajax === 'prompt_save') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $respondJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
        $prompt = (string)($_POST['prompt'] ?? '');
        $settingsRepo->setBotPrompt($prompt, date('Y-m-d H:i:s'));
        $row = $settingsRepo->getBotPrompt();
        $respondJson(['ok' => true, 'updated_at' => (string)($row['updated_at'] ?? '')]);
    }

    if ($ajax === 'kb_list') {
        $limit = (int)($_GET['limit'] ?? 80);
        $rows = $kbRepo->list($limit, 0);
        $respondJson(['ok' => true, 'items' => $rows]);
    }

    if ($ajax === 'kb_get') {
        $id = (int)($_GET['id'] ?? 0);
        $row = $kbRepo->getById($id);
        if (!$row) $respondJson(['ok' => false, 'error' => 'not_found'], 404);
        $respondJson(['ok' => true, 'item' => $row]);
    }

    if ($ajax === 'kb_save') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $respondJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
        $id = isset($_POST['id']) ? (int)($_POST['id'] ?? 0) : 0;
        $title = (string)($_POST['title'] ?? '');
        $content = (string)($_POST['content'] ?? '');
        $sourceUrl = (string)($_POST['source_url'] ?? '');
        $tags = (string)($_POST['tags'] ?? '');
        $isActive = isset($_POST['is_active']) ? (int)($_POST['is_active'] ?? 0) : 1;
        $newId = $kbRepo->upsert($id > 0 ? $id : null, $title, $content, $sourceUrl, $tags, $isActive);
        if ($newId <= 0) $respondJson(['ok' => false, 'error' => 'save_failed'], 500);
        $row = $kbRepo->getById($newId);
        $respondJson(['ok' => true, 'id' => $newId, 'item' => $row]);
    }

    if ($ajax === 'kb_delete') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $respondJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $okDel = $kbRepo->delete($id);
        $respondJson(['ok' => $okDel ? true : false], $okDel ? 200 : 400);
    }

    if ($ajax === 'kb_import_url') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $respondJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url === '') $respondJson(['ok' => false, 'error' => 'missing_url'], 400);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        $u = @parse_url($url);
        $host = is_array($u) ? strtolower((string)($u['host'] ?? '')) : '';
        if ($host !== 'veranda.my') $respondJson(['ok' => false, 'error' => 'only_veranda_my_allowed'], 400);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: veranda-ai-bot-admin/1.0']);
        $html = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($html) || $html === '' || $code < 200 || $code >= 300) $respondJson(['ok' => false, 'error' => 'fetch_failed', 'http_code' => $code], 400);

        $title = $url;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $t = trim((string)html_entity_decode((string)($m[1] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($t !== '') $title = $t;
        }

        $text = $html;
        $text = preg_replace('/<\s*(br|br\/)\s*>/i', "\n", $text);
        $text = preg_replace('/<\/\s*(p|div|li|h1|h2|h3|h4|h5|h6)\s*>/i', "\n", $text);
        if ($text === null) $text = $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace("/\r\n?/", "\n", $text);
        if ($text === null) $text = '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        if ($text === null) $text = '';
        $text = trim($text);
        if (mb_strlen($text) > 20000) $text = mb_substr($text, 0, 20000) . '…';

        $newId = $kbRepo->upsert(null, $title, $text, $url, 'imported,url', 1);
        if ($newId <= 0) $respondJson(['ok' => false, 'error' => 'save_failed'], 500);
        $row = $kbRepo->getById($newId);
        $respondJson(['ok' => true, 'id' => $newId, 'item' => $row]);
    }

    $respondJson(['ok' => false, 'error' => 'unknown_action'], 400);
}

$aibotPromptRow = $settingsRepo->getBotPrompt();
$aibotPrompt = (string)($aibotPromptRow['prompt'] ?? '');
$aibotKbItems = $kbRepo->list(80, 0);
