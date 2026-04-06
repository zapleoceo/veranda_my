<?php
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '#') === 0) continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/Database.php';

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
$dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
$dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
$dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
$dbPass = (string)($_ENV['DB_PASS'] ?? '');
$dbSuffix = trim((string)($_ENV['DB_TABLE_SUFFIX'] ?? ''));

$splitName = function (string $raw): array {
    $s = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($s === '') return ['ru' => '', 'en' => ''];

    $hasCyr = function (string $x): bool { return (bool)preg_match('/\p{Cyrillic}/u', $x); };
    $hasLat = function (string $x): bool { return (bool)preg_match('/[A-Za-z]/', $x); };

    $seps = [' / ', '/', ' | ', '|', ' — ', ' – ', ' - ', '—', '–', '-'];
    foreach ($seps as $sep) {
        if (mb_strpos($s, $sep) === false) continue;
        $parts = array_map('trim', preg_split('/' . preg_quote($sep, '/') . '/u', $s, 2));
        if (count($parts) !== 2) continue;
        [$a, $b] = $parts;
        if ($a === '' || $b === '') continue;
        $aC = $hasCyr($a); $aL = $hasLat($a);
        $bC = $hasCyr($b); $bL = $hasLat($b);
        if (($aC && !$aL) && ($bL && !$bC)) return ['ru' => $a, 'en' => $b];
        if (($bC && !$bL) && ($aL && !$aC)) return ['ru' => $b, 'en' => $a];
    }

    $tokens = preg_split('/\s+/u', $s) ?: [];
    $ru = [];
    $en = [];
    foreach ($tokens as $tok) {
        $t = trim($tok);
        if ($t === '') continue;
        $tC = $hasCyr($t);
        $tL = $hasLat($t);
        if ($tC && !$tL) { $ru[] = $t; continue; }
        if ($tL && !$tC) { $en[] = $t; continue; }
        if (!$en && $ru) { $ru[] = $t; continue; }
        if (!$ru && $en) { $en[] = $t; continue; }
        if ($ru) $ru[] = $t;
        else $en[] = $t;
    }
    $ruS = trim(preg_replace('/\s+/u', ' ', implode(' ', $ru)));
    $enS = trim(preg_replace('/\s+/u', ' ', implode(' ', $en)));
    if ($ruS === '' && $enS === '') return ['ru' => $s, 'en' => ''];
    if ($ruS === '') return ['ru' => $s, 'en' => $enS];
    if ($enS === '') return ['ru' => $ruS, 'en' => ''];
    return ['ru' => $ruS, 'en' => $enS];
};

if (($_GET['ajax'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB не настроена'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $dbSuffix);
    $t = $db->t('temp_names_products');
    $pdo = $db->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
        product_id BIGINT PRIMARY KEY,
        name_raw VARCHAR(255) NOT NULL,
        name_ru VARCHAR(255) NOT NULL,
        name_en VARCHAR(255) NOT NULL,
        edit_ru VARCHAR(255) NOT NULL,
        edit_en VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_name_ru (name_ru),
        KEY idx_name_en (name_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $ajax = (string)($_GET['ajax'] ?? '');
    if ($ajax === 'list') {
        $rows = $db->query("SELECT product_id, name_raw, name_ru, name_en, edit_ru, edit_en, updated_at FROM {$t} ORDER BY name_ru, name_raw")->fetchAll();
        echo json_encode(['ok' => true, 'items' => is_array($rows) ? $rows : []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajax === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($payload)) $payload = [];
        $pid = (int)($payload['product_id'] ?? 0);
        $value = trim((string)($payload['value'] ?? ''));
        if ($pid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ru = '';
        $en = '';
        if ($value !== '') {
            $parts = preg_split('/\s*\/\s*/u', $value, 2);
            if (is_array($parts) && count($parts) === 2) {
                $ru = trim((string)($parts[0] ?? ''));
                $en = trim((string)($parts[1] ?? ''));
            } else {
                $ru = $value;
                $en = '';
            }
        }
        if (mb_strlen($ru, 'UTF-8') > 255) $ru = mb_substr($ru, 0, 255, 'UTF-8');
        if (mb_strlen($en, 'UTF-8') > 255) $en = mb_substr($en, 0, 255, 'UTF-8');
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $db->query("UPDATE {$t} SET edit_ru = ?, edit_en = ?, updated_at = ? WHERE product_id = ? LIMIT 1", [$ru, $en, $now, $pid]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajax === 'sync') {
        if ($posterToken === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $api = new \App\Classes\PosterAPI($posterToken);
        $products = $api->request('menu.getProducts', [], 'GET');
        if (!is_array($products)) $products = [];

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "INSERT INTO {$t} (product_id, name_raw, name_ru, name_en, edit_ru, edit_en, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               name_raw = VALUES(name_raw),
               name_ru = VALUES(name_ru),
               name_en = VALUES(name_en),
               updated_at = VALUES(updated_at)"
        );

        $count = 0;
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? $p['id'] ?? 0);
            if ($pid <= 0) continue;
            $rawName = trim((string)($p['product_name'] ?? $p['name'] ?? ''));
            if ($rawName === '') continue;
            if (mb_strlen($rawName, 'UTF-8') > 255) $rawName = mb_substr($rawName, 0, 255, 'UTF-8');
            $sp = $splitName($rawName);
            $ru = (string)($sp['ru'] ?? '');
            $en = (string)($sp['en'] ?? '');
            if (mb_strlen($ru, 'UTF-8') > 255) $ru = mb_substr($ru, 0, 255, 'UTF-8');
            if (mb_strlen($en, 'UTF-8') > 255) $en = mb_substr($en, 0, 255, 'UTF-8');

            $exists = $db->query("SELECT edit_ru, edit_en FROM {$t} WHERE product_id = ? LIMIT 1", [$pid])->fetch();
            $editRu = '';
            $editEn = '';
            if (is_array($exists)) {
                $editRu = (string)($exists['edit_ru'] ?? '');
                $editEn = (string)($exists['edit_en'] ?? '');
            }
            $hasEdit = trim($editRu . $editEn) !== '';
            if (!$hasEdit) { $editRu = $ru; $editEn = $en; }
            if (mb_strlen($editRu, 'UTF-8') > 255) $editRu = mb_substr($editRu, 0, 255, 'UTF-8');
            if (mb_strlen($editEn, 'UTF-8') > 255) $editEn = mb_substr($editEn, 0, 255, 'UTF-8');

            $stmt->execute([$pid, $rawName, $ru, $en, $editRu, $editEn, $now, $now]);
            $count++;
        }
        echo json_encode(['ok' => true, 'count' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
    exit;
}

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TEMP_NAMES</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f5f5f5; color:#111827; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 18px; }
        .top { display:flex; align-items:center; gap: 10px; flex-wrap: wrap; }
        .top h1 { margin: 0; font-size: 18px; font-weight: 900; }
        button { padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.12); background: #fff; font-weight: 900; cursor: pointer; }
        button.primary { background: #1a73e8; color: #fff; border-color:#1a73e8; }
        button:disabled { opacity: 0.5; cursor: default; }
        .status { font-size: 12px; color:#6b7280; font-weight: 800; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; background: #fff; border: 1px solid rgba(0,0,0,0.10); border-radius: 12px; overflow:hidden; }
        th, td { padding: 10px 12px; border-bottom: 1px solid rgba(0,0,0,0.06); vertical-align: top; }
        th { text-align:left; font-size: 12px; color:#6b7280; font-weight: 900; background: rgba(0,0,0,0.03); position: sticky; top: 0; }
        td { font-size: 13px; font-weight: 800; }
        .muted { color:#6b7280; font-weight: 800; }
        .edit { width: 100%; padding: 8px 10px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.12); font-weight: 800; font-size: 13px; }
        .pid { font-variant-numeric: tabular-nums; font-size: 11px; }
        .row-saving { outline: 2px solid rgba(26,115,232,0.25); outline-offset: -2px; }
        @media (max-width: 800px) {
            th:nth-child(1), td:nth-child(1) { display:none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1>TEMP_NAMES</h1>
        <button class="primary" id="syncBtn">Синхронизировать из Poster</button>
        <span class="status" id="status"></span>
    </div>
    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>RU</th>
            <th>EN</th>
            <th>RU / EN (edit)</th>
        </tr>
        </thead>
        <tbody id="tbody"></tbody>
    </table>
</div>

<script>
(() => {
    const tbody = document.getElementById('tbody');
    const syncBtn = document.getElementById('syncBtn');
    const statusEl = document.getElementById('status');
    const setStatus = (t) => { if (statusEl) statusEl.textContent = String(t || ''); };

    const apiUrl = (ajax) => {
        const u = new URL(location.href);
        u.searchParams.set('ajax', ajax);
        return u.toString();
    };

    const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    const rowValue = (r) => {
        const ru = String(r.edit_ru || '').trim();
        const en = String(r.edit_en || '').trim();
        if (!ru && !en) return '';
        if (ru && en) return ru + ' / ' + en;
        return ru || en;
    };

    const loadList = async () => {
        setStatus('Загрузка…');
        const res = await fetch(apiUrl('list'), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        const items = Array.isArray(j.items) ? j.items : [];
        if (tbody) tbody.innerHTML = '';
        items.forEach((r) => {
            const tr = document.createElement('tr');
            tr.dataset.pid = String(r.product_id || '');
            tr.innerHTML = `
                <td class="pid muted">${esc(r.product_id || '')}</td>
                <td>${esc(r.name_ru || '')}</td>
                <td>${esc(r.name_en || '')}</td>
                <td><input class="edit" data-pid="${esc(r.product_id || '')}" value="${esc(rowValue(r))}"></td>
            `;
            tbody.appendChild(tr);
        });
        setStatus('Готово: ' + String(items.length));
    };

    const saveValue = async (pid, value, tr) => {
        if (!pid) return;
        if (tr) tr.classList.add('row-saving');
        try {
            const res = await fetch(apiUrl('update'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: Number(pid), value: String(value || '') }),
            });
            const j = await res.json().catch(() => null);
            if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        } finally {
            if (tr) tr.classList.remove('row-saving');
        }
    };

    if (tbody) {
        tbody.addEventListener('change', (e) => {
            const t = e.target;
            if (!(t instanceof HTMLInputElement)) return;
            if (!t.classList.contains('edit')) return;
            const pid = String(t.dataset.pid || '');
            const tr = t.closest('tr');
            saveValue(pid, t.value, tr).catch((err) => setStatus(String(err && err.message ? err.message : err)));
        });
    }

    if (syncBtn) {
        syncBtn.addEventListener('click', async () => {
            if (syncBtn.disabled) return;
            syncBtn.disabled = true;
            setStatus('Синхронизация…');
            try {
                const res = await fetch(apiUrl('sync'), { headers: { 'Accept': 'application/json' } });
                const j = await res.json().catch(() => null);
                if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                setStatus('Синхронизировано: ' + String(j.count || 0));
                await loadList();
            } catch (err) {
                setStatus(String(err && err.message ? err.message : err));
            } finally {
                syncBtn.disabled = false;
            }
        });
    }

    loadList().catch(async () => {
        try {
            if (syncBtn) {
                syncBtn.disabled = true;
                setStatus('Первичная синхронизация…');
                const res = await fetch(apiUrl('sync'), { headers: { 'Accept': 'application/json' } });
                const j = await res.json().catch(() => null);
                if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                await loadList();
            }
        } catch (e) {
            setStatus(String(e && e.message ? e.message : e));
        } finally {
            if (syncBtn) syncBtn.disabled = false;
        }
    });
})();
</script>
</body>
</html>

