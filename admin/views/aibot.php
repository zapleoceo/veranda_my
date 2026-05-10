<div class="card" id="aibot-root">

  <!-- ── Status ─────────────────────────────────────────────────────────────── -->
  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
    <span class="pill" id="pillDb" title="База данных">DB</span>
    <span class="pill" id="pillGemini" title="Gemini AI">AI</span>
    <span class="small-muted" id="aibotMeta" style="flex:1;"></span>
    <button class="btn btn-sm" id="btnRefreshState" type="button">↻ Статус</button>
  </div>

  <!-- ── Test bot ────────────────────────────────────────────────────────────── -->
  <div style="margin-bottom:24px;">
    <div style="font-weight:700; font-size:15px; margin-bottom:8px;">Проверить бота</div>
    <div style="display:flex; gap:8px;">
      <input id="botTestQ" type="text" placeholder="Задай вопрос боту..."
             style="flex:1; padding:8px 12px; border:1px solid #ccc; border-radius:6px; font-size:13px;">
      <button class="btn" id="btnBotTest" type="button">Спросить</button>
    </div>
    <div id="botTestResult"
         style="margin-top:10px; padding:12px; background:#f9f9f9; border-radius:6px; font-size:13px; display:none;"></div>
  </div>

  <hr style="margin:0 0 24px; border:none; border-top:1px solid #e0e0e0;">

  <!-- ── Bot identity ────────────────────────────────────────────────────────── -->
  <div style="margin-bottom:24px;">
    <div style="font-weight:700; font-size:15px; margin-bottom:4px;">Кто такой бот</div>
    <div class="small-muted" style="margin-bottom:8px;">
      Опиши бота: как его зовут, каким тоном он отвечает, что умеет. Это главная инструкция для ИИ.
    </div>
    <textarea id="botIdentity" rows="5"
              style="width:100%; box-sizing:border-box; font-family:inherit; font-size:13px;
                     padding:10px; border:1px solid #ccc; border-radius:6px; resize:vertical;"
    ><?= htmlspecialchars($aibotIdentity) ?></textarea>
    <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
      <button class="btn" id="btnIdentitySave" type="button">Сохранить</button>
      <span class="small-muted" id="identitySavedAt"></span>
    </div>
  </div>

  <!-- ── Forbidden topics ────────────────────────────────────────────────────── -->
  <div style="margin-bottom:24px;">
    <div style="font-weight:700; font-size:15px; margin-bottom:4px;">Запрещённые темы</div>
    <div class="small-muted" style="margin-bottom:8px;">
      Каждая строка — тема, которую бот <b>никогда</b> не раскрывает (закупочные цены, зарплаты и т.п.).
    </div>
    <textarea id="botForbidden" rows="4"
              style="width:100%; box-sizing:border-box; font-family:inherit; font-size:13px;
                     padding:10px; border:1px solid #ccc; border-radius:6px; resize:vertical;"
    ><?= htmlspecialchars($aibotForbidden) ?></textarea>
    <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
      <button class="btn" id="btnForbiddenSave" type="button">Сохранить</button>
      <span class="small-muted" id="forbiddenSavedAt"></span>
    </div>
  </div>

  <hr style="margin:0 0 24px; border:none; border-top:1px solid #e0e0e0;">

  <!-- ── Knowledge base ─────────────────────────────────────────────────────── -->
  <div style="margin-bottom:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;
                gap:8px; margin-bottom:8px;">
      <div style="font-weight:700; font-size:15px;">База знаний</div>
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <input id="kbImportUrl" type="text" placeholder="https://veranda.my/..."
               style="width:230px; padding:6px 10px; border:1px solid #ccc; border-radius:6px; font-size:13px;">
        <button class="btn btn-sm" id="btnKbImport" type="button">Импорт URL</button>
        <button class="btn" id="btnKbAdd" type="button">+ Добавить</button>
      </div>
    </div>
    <div class="small-muted" style="margin-bottom:10px;">
      Документы, которые бот использует для ответов.
      <b>Для всех</b> — доступно любому. <b>Только своим</b> — только авторизованным чатам. <b>Скрыто</b> — временно отключено.<br>
      Если указан URL — бот читает страницу при каждом запросе (кеш 2 ч).
    </div>

    <div class="table-wrap" style="overflow:auto;">
      <table id="kbTable" style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr style="background:#f5f5f5; text-align:left;">
            <th style="padding:8px 10px;">Название</th>
            <th style="padding:8px 10px;">Источник</th>
            <th style="padding:8px 10px;">Доступ</th>
            <th style="padding:8px 10px;">Статус</th>
            <th style="padding:8px 10px;"></th>
          </tr>
        </thead>
        <tbody id="kbBody">
          <?php foreach ($aibotKbItems as $ki): ?>
          <tr data-id="<?= (int)$ki['id'] ?>">
            <td style="padding:8px 10px;"><?= htmlspecialchars((string)($ki['title'] ?? '')) ?></td>
            <td style="padding:8px 10px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
              <?php if (!empty($ki['source_url'])): ?>
                <a href="<?= htmlspecialchars((string)$ki['source_url']) ?>" target="_blank" style="font-size:12px;">🔗 URL</a>
              <?php else: ?>
                <span class="small-muted">текст</span>
              <?php endif; ?>
            </td>
            <td style="padding:8px 10px;">
              <span class="pill pill-<?= htmlspecialchars((string)($ki['access'] ?? 'public')) ?>"><?= match((string)($ki['access'] ?? 'public')) { 'members' => 'Только своим', 'never' => 'Скрыто', default => 'Для всех' } ?></span>
            </td>
            <td style="padding:8px 10px;">
              <?= $ki['is_active'] ? '<span style="color:#2e7d32">●</span>' : '<span style="color:#999">○</span>' ?>
            </td>
            <td style="padding:8px 10px; white-space:nowrap;">
              <button class="btn btn-sm btn-kb-edit" type="button" data-id="<?= (int)$ki['id'] ?>">✏</button>
              <button class="btn btn-sm btn-kb-del" type="button" data-id="<?= (int)$ki['id'] ?>" style="color:#c00;">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($aibotKbItems)): ?>
          <tr id="kbEmpty">
            <td colspan="5" style="padding:16px 10px; color:#999; text-align:center;">
              База знаний пуста. Добавьте документ или импортируйте страницу по URL.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <hr style="margin:0 0 16px; border:none; border-top:1px solid #e0e0e0;">

  <!-- ── Service (collapsed) ────────────────────────────────────────────────── -->
  <details id="serviceDetails">
    <summary style="cursor:pointer; font-weight:600; color:#666; font-size:13px; user-select:none;">
      Служебное (логи, анонс, саммари)
    </summary>
    <div style="margin-top:14px;">

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
        <label style="font-size:13px; color:#555;">Дата:</label>
        <input type="date" id="aibotDate" value="<?= htmlspecialchars($aibotDate) ?>"
               style="padding:4px 8px; border:1px solid #ccc; border-radius:6px; font-size:13px;">
      </div>

      <!-- Logs -->
      <div style="margin-bottom:16px;">
        <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
          <button class="btn btn-sm" id="btnLogTail" type="button">Показать лог</button>
          <span class="small-muted" id="logFilePath"></span>
        </div>
        <pre id="logOutput"
             style="display:none; max-height:280px; overflow:auto; font-size:11px;
                    background:#f5f5f5; padding:10px; border-radius:6px; white-space:pre-wrap; margin:0;"></pre>
      </div>

      <!-- Operations -->
      <div>
        <div style="font-weight:600; font-size:13px; margin-bottom:8px; color:#555;">Операции</div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
          <button class="btn btn-sm" id="btnAnnounceGet" type="button">Анонс (кеш)</button>
          <button class="btn btn-sm" id="btnAnnounceGen" type="button">Сгенерировать анонс</button>
          <button class="btn btn-sm" id="btnDailyGet" type="button">Саммари (кеш)</button>
          <button class="btn btn-sm" id="btnDailyRun" type="button">Сгенерировать саммари</button>
        </div>
        <div id="opsOutput" style="font-size:13px;"></div>
      </div>

    </div>
  </details>

</div>

<!-- ── KB editor modal ─────────────────────────────────────────────────────────── -->
<div id="kbModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
                          z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:10px; padding:24px; width:min(580px,95vw);
              max-height:90vh; overflow-y:auto; box-shadow:0 8px 40px rgba(0,0,0,.2);">
    <div style="font-weight:700; font-size:16px; margin-bottom:16px;" id="kbModalTitle">Документ</div>

    <input type="hidden" id="kbId">

    <div class="form-group">
      <label>Название *</label>
      <input type="text" id="kbTitle" style="width:100%; box-sizing:border-box;">
    </div>

    <div class="form-group" style="margin-top:12px;">
      <label>URL источника <span class="small-muted">(если есть — бот читает страницу)</span></label>
      <input type="text" id="kbUrl" placeholder="https://veranda.my/..."
             style="width:100%; box-sizing:border-box;">
    </div>

    <div class="form-group" style="margin-top:12px;">
      <label>Содержимое <span class="small-muted">(можно пусто если есть URL)</span></label>
      <textarea id="kbContent" rows="7"
                style="width:100%; box-sizing:border-box; font-family:inherit; font-size:13px;
                       padding:8px; border:1px solid #ccc; border-radius:6px; resize:vertical;"></textarea>
    </div>

    <div style="display:flex; gap:16px; margin-top:12px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <label style="font-weight:600; display:block; margin-bottom:4px;">Доступ</label>
        <select id="kbAccess" style="padding:6px 10px; border:1px solid #ccc; border-radius:6px;">
          <option value="public">Для всех</option>
          <option value="members">Только своим</option>
          <option value="never">Скрыто</option>
        </select>
      </div>
      <div style="display:flex; align-items:center; gap:6px;">
        <input type="checkbox" id="kbActive" checked style="width:16px; height:16px;">
        <label for="kbActive">Активен</label>
      </div>
    </div>

    <div style="display:flex; gap:8px; margin-top:20px; justify-content:flex-end;">
      <button class="btn btn-sm" id="btnKbCancel" type="button">Отмена</button>
      <button class="btn" id="btnKbSave" type="button">Сохранить</button>
    </div>
    <div class="small-muted" id="kbModalErr" style="margin-top:8px; color:#c00;"></div>
  </div>
</div>

<style>
.pill { display:inline-block; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600; background:#e0e0e0; color:#555; }
.pill.ok  { background:#c8e6c9; color:#2e7d32; }
.pill.err { background:#ffcdd2; color:#b71c1c; }
.pill.pill-public  { background:#e3f2fd; color:#1565c0; }
.pill.pill-members { background:#fff3e0; color:#e65100; }
.pill.pill-never   { background:#f5f5f5; color:#999; }
.btn-sm { padding:4px 10px; font-size:12px; }
#aibot-root details summary::-webkit-details-marker { color:#999; }
</style>
