<div id="ai-root">

  <!-- ── Status ─────────────────────────────────────────────────────────── -->
  <div class="ai-bar">
    <span class="ai-pill" id="pillDb">DB</span>
    <span class="ai-pill" id="pillGemini">AI</span>
    <span class="ai-meta" id="aibotMeta"></span>
    <button class="ai-btn" id="btnRefreshState" title="Обновить статус">↻</button>
  </div>

  <!-- ── Test ───────────────────────────────────────────────────────────── -->
  <div class="ai-test-row">
    <input id="botTestQ" type="text" placeholder="Проверить бота — задай вопрос...">
    <button class="ai-btn-primary" id="btnBotTest">→</button>
  </div>
  <div id="botTestResult" class="ai-result" style="display:none"></div>

  <!-- ── Bot identity ───────────────────────────────────────────────────── -->
  <div class="ai-block">
    <div class="ai-label">Личность бота</div>
    <textarea id="botIdentity" rows="4" placeholder="Как зовут бота, каким тоном отвечает, что умеет..."><?= htmlspecialchars($aibotIdentity) ?></textarea>
    <div class="ai-save-row">
      <button class="ai-btn-primary" id="btnIdentitySave">Сохранить</button>
      <span class="ai-ts" id="identitySavedAt"></span>
    </div>
  </div>

  <!-- ── Forbidden topics ───────────────────────────────────────────────── -->
  <div class="ai-block">
    <div class="ai-label">Запрещённые темы <span class="ai-hint">— каждая строка отдельно</span></div>
    <textarea id="botForbidden" rows="3" placeholder="закупочные цены&#10;зарплаты сотрудников"><?= htmlspecialchars($aibotForbidden) ?></textarea>
    <div class="ai-save-row">
      <button class="ai-btn-primary" id="btnForbiddenSave">Сохранить</button>
      <span class="ai-ts" id="forbiddenSavedAt"></span>
    </div>
  </div>

  <!-- ── Knowledge base ─────────────────────────────────────────────────── -->
  <div class="ai-block">
    <div class="ai-label ai-label-row">
      <span>База знаний</span>
      <div class="ai-row">
        <input id="kbImportUrl" type="text" placeholder="https://veranda.my/..." class="ai-url-input">
        <button class="ai-btn" id="btnKbImport">↗ URL</button>
        <button class="ai-btn-primary" id="btnKbAdd">+ Добавить</button>
      </div>
    </div>

    <table class="ai-table">
      <thead>
        <tr>
          <th>Название</th>
          <th>Источник</th>
          <th>Доступ</th>
          <th>●</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="kbBody">
        <?php foreach ($aibotKbItems as $ki): ?>
        <tr data-id="<?= (int)$ki['id'] ?>">
          <td><?= htmlspecialchars((string)($ki['title'] ?? '')) ?></td>
          <td><?php if (!empty($ki['source_url'])): ?><a href="<?= htmlspecialchars((string)$ki['source_url']) ?>" target="_blank">🔗</a><?php else: ?><span class="ai-muted">текст</span><?php endif; ?></td>
          <td><?php $acc = (string)($ki['access'] ?? 'public'); ?>
            <span class="ai-acc ai-acc-<?= $acc ?>"><?= match($acc) { 'members' => 'Своим', 'never' => 'Скрыто', default => 'Все' } ?></span></td>
          <td><?= $ki['is_active'] ? '<span class="ai-dot-on">●</span>' : '<span class="ai-dot-off">○</span>' ?></td>
          <td class="ai-actions">
            <button class="ai-btn btn-kb-edit" data-id="<?= (int)$ki['id'] ?>">✏</button>
            <button class="ai-btn ai-btn-del btn-kb-del" data-id="<?= (int)$ki['id'] ?>">✕</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($aibotKbItems)): ?>
        <tr id="kbEmpty"><td colspan="5" class="ai-empty">Пусто. Добавьте документ или импортируйте URL.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── Service (collapsed) ────────────────────────────────────────────── -->
  <details class="ai-service">
    <summary>Poster · Логи · Операции</summary>
    <div class="ai-service-body">

      <!-- Poster -->
      <div class="ai-sub">
        <span class="ai-label">Poster POS — наличие блюд</span>
        <div class="ai-row" style="margin-top:5px">
          <button class="ai-btn" id="btnPosterRefresh">↻ Обновить</button>
          <span class="ai-ts" id="posterUpdatedAt"></span>
        </div>
        <div id="posterStatus" class="ai-muted" style="margin-top:4px; font-size:11px;"></div>
      </div>

      <!-- Logs -->
      <div class="ai-sub">
        <div class="ai-row">
          <span class="ai-label">Логи</span>
          <span class="ai-muted" id="logFilePath" style="font-size:11px;"></span>
          <button class="ai-btn" id="btnLogTail">Показать</button>
        </div>
        <pre id="logOutput" class="ai-log"></pre>
      </div>

      <!-- Operations -->
      <div class="ai-sub">
        <span class="ai-label">Операции</span>
        <div class="ai-row" style="margin-top:5px">
          <input type="date" id="aibotDate" value="<?= htmlspecialchars($aibotDate) ?>" class="ai-date">
          <button class="ai-btn" id="btnAnnounceGet">Анонс</button>
          <button class="ai-btn" id="btnAnnounceGen">Генерировать анонс</button>
          <button class="ai-btn" id="btnDailyGet">Саммари</button>
          <button class="ai-btn" id="btnDailyRun">Генерировать саммари</button>
        </div>
        <div id="opsOutput" style="margin-top:8px; font-size:12px;"></div>
      </div>

    </div>
  </details>

</div><!-- #ai-root -->

<!-- ── KB modal ──────────────────────────────────────────────────────────── -->
<div id="kbModal" class="ai-modal-overlay">
  <div class="ai-modal">
    <div class="ai-modal-title" id="kbModalTitle">Документ</div>
    <input type="hidden" id="kbId">
    <div class="ai-field">
      <label>Название *</label>
      <input type="text" id="kbTitle">
    </div>
    <div class="ai-field">
      <label>URL <span class="ai-hint">(бот читает страницу)</span></label>
      <input type="text" id="kbUrl" placeholder="https://veranda.my/...">
    </div>
    <div class="ai-field">
      <label>Содержимое <span class="ai-hint">(или пусто если есть URL)</span></label>
      <textarea id="kbContent" rows="6"></textarea>
    </div>
    <div class="ai-row" style="margin-top:10px; gap:16px">
      <div>
        <label style="display:block; margin-bottom:3px; font-size:11px; color:#777;">Доступ</label>
        <select id="kbAccess">
          <option value="public">Для всех</option>
          <option value="members">Только своим</option>
          <option value="never">Скрыто</option>
        </select>
      </div>
      <div class="ai-row" style="margin-top:18px">
        <input type="checkbox" id="kbActive" checked>
        <label for="kbActive">Активен</label>
      </div>
    </div>
    <div class="ai-row" style="justify-content:flex-end; margin-top:14px; gap:6px">
      <button class="ai-btn" id="btnKbCancel">Отмена</button>
      <button class="ai-btn-primary" id="btnKbSave">Сохранить</button>
    </div>
    <div class="ai-err" id="kbModalErr"></div>
  </div>
</div>

<style>
#ai-root { font-size: 13px; color: #222; }

/* Layout blocks */
.ai-bar  { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.ai-meta { flex: 1; font-size: 11px; color: #888; }
.ai-row  { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ai-block { border-top: 1px solid #eee; padding: 10px 0 6px; }
.ai-save-row { display: flex; align-items: center; gap: 8px; margin-top: 5px; }
.ai-ts   { font-size: 11px; color: #999; }
.ai-muted { color: #999; }
.ai-empty { padding: 12px; text-align: center; color: #bbb; font-size: 12px; }

/* Labels */
.ai-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #888; margin-bottom: 5px; }
.ai-label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.ai-hint { font-size: 11px; font-weight: 400; text-transform: none; letter-spacing: 0; color: #bbb; }

/* Pills */
.ai-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #e0e0e0; color: #666; }
.ai-pill.ok  { background: #d4edda; color: #2e7d32; }
.ai-pill.err { background: #ffcdd2; color: #b71c1c; }

/* Buttons */
.ai-btn { padding: 3px 10px; font-size: 12px; border: 1px solid #d0d0d0; border-radius: 4px; background: #f7f7f7; cursor: pointer; white-space: nowrap; }
.ai-btn:hover { background: #eee; }
.ai-btn-primary { padding: 4px 14px; font-size: 12px; border: none; border-radius: 4px; background: #333; color: #fff; cursor: pointer; white-space: nowrap; }
.ai-btn-primary:hover { background: #111; }
.ai-btn-del { color: #c00; }

/* Inputs */
#ai-root input[type="text"],
#ai-root input[type="date"],
#ai-root select { padding: 4px 8px; border: 1px solid #d0d0d0; border-radius: 4px; font-size: 12px; font-family: inherit; }
#ai-root textarea { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #d0d0d0; border-radius: 4px; font-family: inherit; font-size: 13px; resize: vertical; }
.ai-test-row { display: flex; gap: 6px; margin-bottom: 6px; }
.ai-test-row input { flex: 1; padding: 6px 10px; border: 1px solid #d0d0d0; border-radius: 4px; font-size: 13px; font-family: inherit; }
.ai-url-input { width: 200px; }
.ai-date { width: 130px; }

/* Result */
.ai-result { padding: 8px 12px; background: #f9f9f9; border-radius: 4px; font-size: 13px; margin-bottom: 10px; border: 1px solid #eee; }

/* Table */
.ai-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ai-table th { padding: 4px 8px; background: #f5f5f5; text-align: left; font-size: 11px; font-weight: 600; color: #666; border-bottom: 1px solid #e8e8e8; }
.ai-table td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.ai-table tr:last-child td { border-bottom: none; }
.ai-table tr:hover td { background: #fafafa; }
.ai-actions { white-space: nowrap; }
.ai-dot-on { color: #2e7d32; }
.ai-dot-off { color: #ccc; }
.ai-acc { font-size: 11px; }
.ai-acc-public  { color: #1565c0; }
.ai-acc-members { color: #e65100; }
.ai-acc-never   { color: #999; }

/* Service section */
.ai-service { margin-top: 10px; border-top: 1px solid #eee; }
.ai-service > summary { cursor: pointer; padding: 8px 0 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #aaa; user-select: none; list-style: none; }
.ai-service > summary::before { content: '▸ '; }
.ai-service[open] > summary::before { content: '▾ '; }
.ai-service > summary::-webkit-details-marker { display: none; }
.ai-service-body { padding: 8px 0; display: flex; flex-direction: column; gap: 12px; }
.ai-sub { border-left: 2px solid #eee; padding-left: 10px; }
.ai-log { display: none; max-height: 260px; overflow: auto; font-size: 11px; background: #f5f5f5; padding: 8px; border-radius: 4px; white-space: pre-wrap; margin-top: 6px; }

/* Modal */
.ai-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 9999; align-items: center; justify-content: center; }
.ai-modal { background: #fff; border-radius: 8px; padding: 20px; width: min(560px, 95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 6px 30px rgba(0,0,0,.2); }
.ai-modal-title { font-weight: 700; font-size: 15px; margin-bottom: 14px; }
.ai-field { margin-bottom: 10px; }
.ai-field label { display: block; font-size: 11px; color: #666; margin-bottom: 3px; }
.ai-field input, .ai-field textarea, .ai-field select { width: 100%; box-sizing: border-box; padding: 5px 8px; border: 1px solid #d0d0d0; border-radius: 4px; font-family: inherit; font-size: 13px; }
.ai-field textarea { resize: vertical; }
.ai-err { color: #c00; font-size: 12px; margin-top: 6px; min-height: 16px; }
</style>
