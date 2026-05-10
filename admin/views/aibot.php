<div class="card">
  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div class="form-group" style="min-width:220px;">
      <label for="aibotDate">Дата</label>
      <input id="aibotDate" type="date" value="<?= htmlspecialchars($aibotDate) ?>">
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <button class="btn" type="button" id="btnState">Статус</button>
      <button class="btn" type="button" id="btnAnnounceGet">Анонс (кеш)</button>
      <button class="btn" type="button" id="btnAnnounceGen">Сгенерировать анонс</button>
      <button class="btn" type="button" id="btnDailyGet">Саммари (кеш)</button>
      <button class="btn" type="button" id="btnDailyRun">Сгенерировать саммари</button>
    </div>
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; align-items:center;">
    <span class="pill" id="pillDb">DB</span>
    <span class="pill" id="pillGemini">Gemini</span>
    <span class="pill" id="pillDaily">Daily</span>
    <span class="small-muted" id="aibotMeta"></span>
  </div>

  <div class="settings-grid" style="margin-top:18px;">
    <div class="card" style="padding:18px;">
      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
        <div style="font-weight:900;">Промт бота</div>
        <button class="btn" type="button" id="btnPromptSave">Сохранить</button>
      </div>
      <div class="small-muted" id="promptMeta" style="margin-top:6px;"></div>
      <div class="form-group" style="margin-top:10px;">
        <textarea id="promptText" placeholder="Сюда вставь системный промт (правила, стиль, ссылки)."><?= htmlspecialchars($aibotPrompt) ?></textarea>
      </div>
    </div>

    <div class="card" style="padding:18px;">
      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
        <div style="font-weight:900;">База знаний</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn" type="button" id="btnKbNew">Новая запись</button>
          <button class="btn" type="button" id="btnKbRefresh">Обновить</button>
        </div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; align-items:flex-end;">
        <div class="form-group" style="flex:1; min-width:260px;">
          <label for="kbImportUrl">Импорт URL (только veranda.my)</label>
          <input id="kbImportUrl" type="url" placeholder="https://veranda.my/links/menu.php">
        </div>
        <button class="btn" type="button" id="btnKbImport">Импортировать</button>
      </div>

      <div class="table-wrap" style="margin-top:12px; overflow:auto;">
        <table style="width:max-content; min-width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px 10px;">ID</th>
              <th style="text-align:left; padding:8px 10px;">Заголовок</th>
              <th style="text-align:left; padding:8px 10px;">Теги</th>
              <th style="text-align:left; padding:8px 10px;">URL</th>
              <th style="text-align:left; padding:8px 10px;">Updated</th>
              <th style="text-align:left; padding:8px 10px;"></th>
            </tr>
          </thead>
          <tbody id="kbTbody">
            <?php foreach ($aibotKbItems as $it): ?>
              <tr data-id="<?= (int)($it['id'] ?? 0) ?>">
                <td style="padding:8px 10px; white-space:nowrap;"><?= (int)($it['id'] ?? 0) ?></td>
                <td style="padding:8px 10px;"><?= htmlspecialchars((string)($it['title'] ?? '')) ?></td>
                <td style="padding:8px 10px; white-space:nowrap;"><?= htmlspecialchars((string)($it['tags'] ?? '')) ?></td>
                <td style="padding:8px 10px;"><?= htmlspecialchars((string)($it['source_url'] ?? '')) ?></td>
                <td style="padding:8px 10px; white-space:nowrap;"><?= htmlspecialchars((string)($it['updated_at'] ?? '')) ?></td>
                <td style="padding:8px 10px; white-space:nowrap;">
                  <button class="btn" type="button" data-act="edit">Edit</button>
                  <button class="btn btn-danger" type="button" data-act="del">Del</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="padding:16px; margin-top:14px;" id="kbEditor" hidden>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
          <div style="font-weight:900;">Редактор</div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn" type="button" id="btnKbSave">Сохранить</button>
            <button class="btn" type="button" id="btnKbCancel">Закрыть</button>
          </div>
        </div>
        <input type="hidden" id="kbId" value="">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
          <div class="form-group">
            <label for="kbTitle">Заголовок</label>
            <input id="kbTitle" type="text">
          </div>
          <div class="form-group">
            <label for="kbTags">Теги</label>
            <input id="kbTags" type="text" placeholder="menu, breakfast, rules">
          </div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px; align-items:end;">
          <div class="form-group">
            <label for="kbSourceUrl">Source URL</label>
            <input id="kbSourceUrl" type="url" placeholder="https://veranda.my/links/...">
          </div>
          <div class="form-group" style="display:flex; gap:10px; align-items:center;">
            <label style="margin:0; font-size:12px; font-weight:800; color:var(--muted, rgba(255,255,255,0.62));">
              <input id="kbIsActive" type="checkbox" checked> Активно
            </label>
          </div>
        </div>
        <div class="form-group" style="margin-top:12px;">
          <label for="kbContent">Текст</label>
          <textarea id="kbContent" style="min-height:220px;"></textarea>
        </div>
        <div class="small-muted" id="kbEditorMeta"></div>
      </div>
    </div>

    <div class="card" style="padding:18px;">
      <div style="font-weight:900;">Вывод</div>
      <div class="small-muted" id="outMeta" style="margin-top:6px;"></div>
      <div class="card" style="padding:14px; margin-top:10px; overflow:auto;" id="outBox"></div>
    </div>
  </div>
</div>

