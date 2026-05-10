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
        <div style="font-weight:900;">Инструкции (наглядно)</div>
      </div>

      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px;">
        <div class="small-muted" id="mapMeta">
          <?= $aibotInstrMapUpdatedAt ? ('Обновлено: ' . htmlspecialchars($aibotInstrMapUpdatedAt)) : '' ?>
        </div>
        <button class="btn" type="button" id="btnMapSave">Сохранить галочки</button>
      </div>

      <div class="table-wrap" style="margin-top:10px; overflow:auto;">
        <table style="width:max-content; min-width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px 10px;">Блок</th>
              <th style="text-align:left; padding:8px 10px;">Чат (Telegram)</th>
              <th style="text-align:left; padding:8px 10px;">Саммари (JSON)</th>
              <th style="text-align:left; padding:8px 10px;">Анонс (сайт HTML)</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="padding:8px 10px; white-space:nowrap;">Common prompt</td>
              <td style="padding:8px 10px;"><input data-map="chat" data-block="common_prompt" type="checkbox" <?= !empty($aibotInstrMap['chat']['common_prompt']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="daily" data-block="common_prompt" type="checkbox" <?= !empty($aibotInstrMap['daily']['common_prompt']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="announce" data-block="common_prompt" type="checkbox" <?= !empty($aibotInstrMap['announce']['common_prompt']) ? 'checked' : '' ?>></td>
            </tr>
            <tr>
              <td style="padding:8px 10px; white-space:nowrap;">System base</td>
              <td style="padding:8px 10px;"><input data-map="chat" data-block="system_base" type="checkbox" <?= !empty($aibotInstrMap['chat']['system_base']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="daily" data-block="system_base" type="checkbox" <?= !empty($aibotInstrMap['daily']['system_base']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="announce" data-block="system_base" type="checkbox" <?= !empty($aibotInstrMap['announce']['system_base']) ? 'checked' : '' ?>></td>
            </tr>
            <tr>
              <td style="padding:8px 10px; white-space:nowrap;">System chat</td>
              <td style="padding:8px 10px;"><input data-map="chat" data-block="system_chat" type="checkbox" <?= !empty($aibotInstrMap['chat']['system_chat']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="daily" data-block="system_chat" type="checkbox" <?= !empty($aibotInstrMap['daily']['system_chat']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="announce" data-block="system_chat" type="checkbox" <?= !empty($aibotInstrMap['announce']['system_chat']) ? 'checked' : '' ?>></td>
            </tr>
            <tr>
              <td style="padding:8px 10px; white-space:nowrap;">System daily</td>
              <td style="padding:8px 10px;"><input data-map="chat" data-block="system_daily" type="checkbox" <?= !empty($aibotInstrMap['chat']['system_daily']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="daily" data-block="system_daily" type="checkbox" <?= !empty($aibotInstrMap['daily']['system_daily']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="announce" data-block="system_daily" type="checkbox" <?= !empty($aibotInstrMap['announce']['system_daily']) ? 'checked' : '' ?>></td>
            </tr>
            <tr>
              <td style="padding:8px 10px; white-space:nowrap;">System announce</td>
              <td style="padding:8px 10px;"><input data-map="chat" data-block="system_announce" type="checkbox" <?= !empty($aibotInstrMap['chat']['system_announce']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="daily" data-block="system_announce" type="checkbox" <?= !empty($aibotInstrMap['daily']['system_announce']) ? 'checked' : '' ?>></td>
              <td style="padding:8px 10px;"><input data-map="announce" data-block="system_announce" type="checkbox" <?= !empty($aibotInstrMap['announce']['system_announce']) ? 'checked' : '' ?>></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:14px;">
        <div style="font-weight:800; font-size:13px;">Common prompt (для всех запросов)</div>
        <button class="btn" type="button" id="btnPromptSave">Сохранить common</button>
      </div>
      <div class="small-muted" id="promptMeta" style="margin-top:6px;"></div>
      <div class="form-group" style="margin-top:10px;">
        <textarea id="promptText" placeholder="Факты/правила ресторана, стиль, ссылки."><?= htmlspecialchars($aibotPrompt) ?></textarea>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:14px;">
        <div class="card" style="padding:14px;">
          <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:13px;">System base (для всех)</div>
            <button class="btn" type="button" id="btnBaseSave">Сохранить</button>
          </div>
          <div class="small-muted" id="baseMeta" style="margin-top:6px;"></div>
          <div class="form-group" style="margin-top:10px;">
            <textarea id="baseText" placeholder="Общие правила поведения, политика, точность."><?= htmlspecialchars($aibotSystemBase) ?></textarea>
          </div>
        </div>

        <div class="card" style="padding:14px;">
          <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:13px;">System chat (Telegram)</div>
            <button class="btn" type="button" id="btnChatSave">Сохранить</button>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-top:10px;">
            <div class="form-group" style="min-width:160px; margin:0;">
              <label for="langChat">Язык</label>
              <select id="langChat">
                <option value="auto" <?= ($aibotLangChat === 'auto' ? 'selected' : '') ?>>auto</option>
                <option value="ru" <?= ($aibotLangChat === 'ru' ? 'selected' : '') ?>>ru</option>
                <option value="en" <?= ($aibotLangChat === 'en' ? 'selected' : '') ?>>en</option>
              </select>
            </div>
            <div class="small-muted" id="chatMeta" style="margin:0;"></div>
          </div>
          <div class="form-group" style="margin-top:10px;">
            <textarea id="chatText" placeholder="Формат Telegram HTML, ограничения тегов."><?= htmlspecialchars($aibotSystemChat) ?></textarea>
          </div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
        <div class="card" style="padding:14px;">
          <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:13px;">System daily (саммари JSON)</div>
            <button class="btn" type="button" id="btnDailySysSave">Сохранить</button>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-top:10px;">
            <div class="form-group" style="min-width:160px; margin:0;">
              <label for="langDaily">Язык</label>
              <select id="langDaily">
                <option value="auto" <?= ($aibotLangDaily === 'auto' ? 'selected' : '') ?>>auto</option>
                <option value="ru" <?= ($aibotLangDaily === 'ru' ? 'selected' : '') ?>>ru</option>
                <option value="en" <?= ($aibotLangDaily === 'en' ? 'selected' : '') ?>>en</option>
              </select>
            </div>
            <div class="small-muted" id="dailySysMeta" style="margin:0;"></div>
          </div>
          <div class="form-group" style="margin-top:10px;">
            <textarea id="dailySysText" placeholder="JSON контракт и правила извлечения событий."><?= htmlspecialchars($aibotSystemDaily) ?></textarea>
          </div>
        </div>

        <div class="card" style="padding:14px;">
          <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:13px;">System announce (анонс сайта)</div>
            <button class="btn" type="button" id="btnAnnounceSysSave">Сохранить</button>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-top:10px;">
            <div class="form-group" style="min-width:160px; margin:0;">
              <label for="langAnnounce">Язык</label>
              <select id="langAnnounce">
                <option value="auto" <?= ($aibotLangAnnounce === 'auto' ? 'selected' : '') ?>>auto</option>
                <option value="ru" <?= ($aibotLangAnnounce === 'ru' ? 'selected' : '') ?>>ru</option>
                <option value="en" <?= ($aibotLangAnnounce === 'en' ? 'selected' : '') ?>>en</option>
              </select>
            </div>
            <div class="small-muted" id="announceSysMeta" style="margin:0;"></div>
          </div>
          <div class="form-group" style="margin-top:10px;">
            <textarea id="announceSysText" placeholder="HTML для сайта (допустимые теги)."><?= htmlspecialchars($aibotSystemAnnounce) ?></textarea>
          </div>
          <div class="small-muted" style="margin-top:10px;">
            Live источники: если у записи в базе знаний пустой текст, но указан URL, бот подтянет актуальные данные по URL во время ответа.
          </div>
        </div>
      </div>

      <div class="card" style="padding:14px; margin-top:12px;">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
          <div style="font-weight:800; font-size:13px;">Поведение (без хардкода)</div>
          <button class="btn" type="button" id="btnBehaviorSave">Сохранить</button>
        </div>
        <div class="small-muted" id="behaviorMeta" style="margin-top:6px;">
          <?= $aibotBehaviorUpdatedAt ? ('Обновлено: ' . htmlspecialchars($aibotBehaviorUpdatedAt)) : '' ?>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <textarea id="behaviorJson" placeholder="JSON настройки поведения (детекторы, KB, daily injection)."><?= htmlspecialchars($aibotBehaviorJson) ?></textarea>
        </div>
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
      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
        <div style="font-weight:900;">Проверка контекста</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn" type="button" id="btnCtxPreview">Показать контекст</button>
          <button class="btn" type="button" id="btnLogTail">Логи</button>
        </div>
      </div>
      <div class="small-muted" style="margin-top:6px;">
        Показывает, какие документы (и какие live URL) попадут в запрос к модели для заданного вопроса.
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label for="ctxQuestion">Вопрос</label>
        <input id="ctxQuestion" type="text" placeholder="Например: какое сегодня меню?">
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label for="ctxMode">Режим</label>
        <select id="ctxMode">
          <option value="chat">chat</option>
          <option value="daily">daily</option>
          <option value="announce">announce</option>
        </select>
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label for="ctxChatId">Chat ID (необязательно, чтобы увидеть историю)</label>
        <input id="ctxChatId" type="text" placeholder="169510539">
      </div>
      <div class="small-muted" id="ctxMeta" style="margin-top:8px;"></div>
    </div>

    <div class="card" style="padding:18px;">
      <div style="font-weight:900;">Вывод</div>
      <div class="small-muted" id="outMeta" style="margin-top:6px;"></div>
      <div class="card" style="padding:14px; margin-top:10px; overflow:auto;" id="outBox"></div>
    </div>
  </div>
</div>
