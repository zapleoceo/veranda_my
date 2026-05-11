<div id="ai-root">

  <div class="ai-top">
    <div class="ai-bar">
      <span class="ai-pill" id="pillDb">DB</span>
      <span class="ai-pill" id="pillGemini">AI</span>
      <span class="ai-pill err" id="pillBlock" style="display:none">⊘ 0с</span>
      <span class="ai-meta" id="aibotMeta"></span>
      <button class="ai-btn ai-btn-warn" id="btnBlockReset" style="display:none" title="Снять блокировку AI">Сброс</button>
      <button class="ai-btn" id="btnRefreshState" title="Обновить статус">↻</button>
    </div>

    <div class="ai-test-row">
      <input id="botTestQ" type="text" placeholder="Проверить бота — задай вопрос...">
      <button class="ai-btn-primary" id="btnBotTest">→</button>
    </div>
  </div>
  <div id="botTestResult" class="ai-result" style="display:none"></div>

  <!-- ── Bot identity ───────────────────────────────────────────────────── -->
  <div class="ai-grid2">
    <div class="ai-block">
      <div class="ai-label">Личность бота</div>
      <textarea id="botIdentity" rows="3" placeholder="Как зовут бота, каким тоном отвечает, что умеет..."><?= htmlspecialchars($aibotIdentity) ?></textarea>
      <div class="ai-save-row">
        <button class="ai-btn-primary" id="btnIdentitySave">Сохранить</button>
        <span class="ai-ts" id="identitySavedAt"></span>
      </div>
    </div>

    <div class="ai-block">
      <div class="ai-label">Запрещённые темы <span class="ai-hint">— каждая строка отдельно</span></div>
      <textarea id="botForbidden" rows="2" placeholder="закупочные цены&#10;зарплаты сотрудников"><?= htmlspecialchars($aibotForbidden) ?></textarea>
      <div class="ai-save-row">
        <button class="ai-btn-primary" id="btnForbiddenSave">Сохранить</button>
        <span class="ai-ts" id="forbiddenSavedAt"></span>
      </div>
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
          <th>Категория</th>
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
          <td><span class="ai-cat"><?= htmlspecialchars((string)($ki['category'] ?? 'other')) ?></span></td>
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
        <tr id="kbEmpty"><td colspan="6" class="ai-empty">Пусто. Добавьте документ или импортируйте URL.</td></tr>
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
    <div class="ai-row" style="margin-top:10px; gap:16px; flex-wrap:wrap">
      <div>
        <label style="display:block; margin-bottom:3px; font-size:11px; color:#777;">Категория</label>
        <select id="kbCategory">
          <option value="other">other</option>
          <option value="contacts">contacts</option>
          <option value="hours">hours</option>
          <option value="menu">menu</option>
          <option value="events">events</option>
          <option value="policies">policies</option>
          <option value="location">location</option>
          <option value="team">team</option>
        </select>
      </div>
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
    <div class="ai-field" style="margin-top:8px">
      <label>Теги <span class="ai-hint">(через запятую, или оставь пусто — автозаполнится)</span></label>
      <input type="text" id="kbTags" placeholder="часы работы, режим, выходные">
    </div>
    <div class="ai-row" style="justify-content:flex-end; margin-top:14px; gap:6px">
      <button class="ai-btn" id="btnKbCancel">Отмена</button>
      <button class="ai-btn-primary" id="btnKbSave">Сохранить</button>
    </div>
    <div class="ai-err" id="kbModalErr"></div>
  </div>
</div>

<style>
#ai-root {
  font-size: 13px;
  color-scheme: dark;
  --bg: #000;
  --card: rgba(255,255,255,0.06);
  --card2: rgba(255,255,255,0.09);
  --text: rgba(255,255,255,0.92);
  --muted: rgba(255,255,255,0.62);
  --accent: #B88746;
  --accent2: rgba(184,135,70,0.22);
  --border: rgba(255,255,255,0.10);
  color: var(--text);
}
#ai-root a { color: var(--accent); text-decoration-color: rgba(184,135,70,0.45); }
#ai-root a:hover { text-decoration-color: rgba(184,135,70,0.75); }

/* Layout blocks */
.ai-top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.ai-bar  { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ai-meta { flex: 1; font-size: 11px; color: var(--muted); }
.ai-row  { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ai-grid2 { display: grid; grid-template-columns: 1fr; gap: 10px; }
.ai-block { border-top: 1px solid var(--border); padding: 8px 0 4px; }
.ai-save-row { display: flex; align-items: center; gap: 8px; margin-top: 5px; }
.ai-ts   { font-size: 11px; color: var(--muted); }
.ai-muted { color: var(--muted); }
.ai-empty { padding: 12px; text-align: center; color: rgba(255,255,255,0.45); font-size: 12px; }

/* Labels */
.ai-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 5px; }
.ai-label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.ai-hint { font-size: 11px; font-weight: 400; text-transform: none; letter-spacing: 0; color: rgba(255,255,255,0.45); }

/* Pills */
.ai-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: var(--card2); color: rgba(255,255,255,0.70); border: 1px solid var(--border); }
.ai-pill.ok  { background: rgba(34,197,94,0.14); color: rgba(255,255,255,0.88); border-color: rgba(34,197,94,0.35); }
.ai-pill.err { background: rgba(239,68,68,0.14); color: rgba(255,255,255,0.88); border-color: rgba(239,68,68,0.35); }

/* Buttons */
.ai-btn { padding: 3px 10px; font-size: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--card); color: var(--text); cursor: pointer; white-space: nowrap; }
.ai-btn:hover { background: rgba(255,255,255,0.10); }
.ai-btn-primary { padding: 4px 14px; font-size: 12px; border: 1px solid rgba(184,135,70,0.45); border-radius: 10px; background: var(--accent); color: #fffaf4; cursor: pointer; white-space: nowrap; font-weight: 800; }
.ai-btn-primary:hover { filter: brightness(1.05); }
.ai-btn-del { color: rgba(255,120,120,0.95); border-color: rgba(239,68,68,0.35); }
.ai-btn-del:hover { background: rgba(239,68,68,0.12); }
.ai-btn-warn { background: rgba(184,135,70,0.10); border-color: rgba(184,135,70,0.35); color: rgba(255,255,255,0.92); }
.ai-btn-warn:hover { background: rgba(184,135,70,0.16); }

/* Inputs */
#ai-root input[type="text"],
#ai-root input[type="date"],
#ai-root select {
  padding: 6px 10px;
  border: 1px solid var(--border);
  border-radius: 12px;
  font-size: 12px;
  font-family: inherit;
  background: rgba(255,255,255,0.03);
  color: var(--text);
  outline: none;
}
#ai-root textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  font-family: inherit;
  font-size: 13px;
  resize: vertical;
  min-height: 68px;
  background: rgba(255,255,255,0.03);
  color: var(--text);
  outline: none;
}
#ai-root input::placeholder,
#ai-root textarea::placeholder { color: rgba(255,255,255,0.45); }
#ai-root input:focus,
#ai-root textarea:focus,
#ai-root select:focus { border-color: rgba(184,135,70,0.45); box-shadow: 0 0 0 2px rgba(184,135,70,0.14); }

.ai-test-row { display: flex; gap: 6px; flex: 1; min-width: min(520px, 100%); }
.ai-test-row input { flex: 1; padding: 9px 12px; border: 1px solid var(--border); border-radius: 12px; font-size: 13px; font-family: inherit; background: rgba(255,255,255,0.03); color: var(--text); outline: none; }
.ai-url-input { width: 200px; }
.ai-date { width: 130px; }

/* Result */
.ai-result { padding: 10px 12px; background: rgba(255,255,255,0.03); border-radius: 12px; font-size: 13px; margin-bottom: 8px; border: 1px solid var(--border); }

/* Table */
.ai-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ai-table th { padding: 6px 10px; background: rgba(255,255,255,0.03); text-align: left; font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.75); border-bottom: 1px solid var(--border); }
.ai-table td { padding: 6px 10px; border-bottom: 1px solid rgba(255,255,255,0.06); vertical-align: middle; color: rgba(255,255,255,0.90); }
.ai-table tr:last-child td { border-bottom: none; }
.ai-table tr:hover td { background: rgba(255,255,255,0.03); }
.ai-actions { white-space: nowrap; }
.ai-dot-on { color: rgba(34,197,94,0.95); }
.ai-dot-off { color: rgba(255,255,255,0.35); }
.ai-acc { font-size: 11px; }
.ai-acc-public  { color: rgba(255,255,255,0.85); }
.ai-acc-members { color: rgba(184,135,70,0.95); }
.ai-acc-never   { color: rgba(255,255,255,0.50); }
.ai-cat { font-size: 10px; color: rgba(255,255,255,0.75); background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08); border-radius: 999px; padding: 1px 8px; }

/* Service section */
.ai-service { margin-top: 10px; border-top: 1px solid var(--border); }
.ai-service > summary { cursor: pointer; padding: 8px 0 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .10em; color: rgba(255,255,255,0.65); user-select: none; list-style: none; }
.ai-service > summary::before { content: '▸ '; }
.ai-service[open] > summary::before { content: '▾ '; }
.ai-service > summary::-webkit-details-marker { display: none; }
.ai-service-body { padding: 8px 0; display: flex; flex-direction: column; gap: 12px; }
.ai-sub { border-left: 2px solid rgba(184,135,70,0.22); padding-left: 10px; }
.ai-log { display: none; max-height: 260px; overflow: auto; font-size: 11px; background: rgba(255,255,255,0.03); padding: 10px 12px; border-radius: 12px; white-space: pre-wrap; margin-top: 6px; border: 1px solid var(--border); }

/* Modal */
.ai-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.68); z-index: 9999; align-items: center; justify-content: center; }
.ai-modal { background: rgba(0,0,0,0.92); border-radius: 16px; padding: 18px; width: min(560px, 95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 50px rgba(0,0,0,.55); border: 1px solid var(--border); }
.ai-modal-title { font-weight: 800; font-size: 15px; margin-bottom: 14px; letter-spacing: 0.02em; }
.ai-field { margin-bottom: 10px; }
.ai-field label { display: block; font-size: 11px; color: rgba(255,255,255,0.65); margin-bottom: 4px; }
.ai-field input, .ai-field textarea, .ai-field select { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid var(--border); border-radius: 12px; font-family: inherit; font-size: 13px; background: rgba(255,255,255,0.03); color: var(--text); outline: none; }
.ai-field textarea { resize: vertical; }
.ai-err { color: rgba(255,120,120,0.95); font-size: 12px; margin-top: 6px; min-height: 16px; }

@media (min-width: 980px) {
  .ai-grid2 { grid-template-columns: 1fr 1fr; }
  .ai-grid2 > .ai-block { border-top: none; border-left: 1px solid var(--border); padding: 0 0 0 10px; }
  .ai-grid2 > .ai-block:first-child { border-left: none; padding-left: 0; }
}
</style>
