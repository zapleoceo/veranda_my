<div class="card">
  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div class="form-group" style="min-width:220px;">
      <label for="aibotDate">Дата</label>
      <div class="small-muted">Фильтр для кеша анонса/саммари и статистики сообщений.</div>
      <input id="aibotDate" type="date" value="<?= htmlspecialchars($aibotDate) ?>">
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <button class="btn" type="button" id="btnState">Статус</button>
      <button class="btn" type="button" id="btnLogTail">Логи</button>
      <button class="btn" type="button" id="btnTour">Мануал</button>
    </div>
  </div>

  <details style="margin-top:12px;">
    <summary style="cursor:pointer; font-weight:800;">Операции (кеш)</summary>
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
      <button class="btn" type="button" id="btnAnnounceGet">Анонс (кеш)</button>
      <button class="btn" type="button" id="btnAnnounceGen">Сгенерировать анонс</button>
      <button class="btn" type="button" id="btnDailyGet">Саммари (кеш)</button>
      <button class="btn" type="button" id="btnDailyRun">Сгенерировать саммари</button>
    </div>
  </details>

  <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; align-items:center;">
    <span class="pill" id="pillDb">DB</span>
    <span class="pill" id="pillGemini">Gemini</span>
    <span class="pill" id="pillDaily">Daily</span>
    <span class="small-muted" id="aibotMeta"></span>
  </div>
  <div class="small-muted" style="margin-top:6px;">
    DB — доступна ли база данных. Gemini — можно ли сейчас вызывать AI (лимиты/кулдаун). Daily — есть ли саммари за выбранную дату.
  </div>

  <div class="settings-grid" style="margin-top:18px;">
    <div class="card" style="padding:18px;">
      <details id="blkMatrix" open>
        <summary style="cursor:pointer; font-weight:900;">Инструкции: матрица применений</summary>
        <div class="small-muted" style="margin-top:6px;">Галочки определяют, какие блоки инструкций попадут в system для chat/daily/announce.</div>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:10px;">
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
      </details>

      <details id="blkCommon" style="margin-top:12px;" open>
        <summary style="cursor:pointer; font-weight:900;">Common prompt</summary>
        <div class="small-muted" style="margin-top:6px;">Факты о ресторане, стиль и постоянные правила. Это попадает во все режимы, если стоит галочка в матрице.</div>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:10px;">
          <div class="small-muted" id="promptMeta"></div>
          <button class="btn" type="button" id="btnPromptSave">Сохранить common</button>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <textarea id="promptText" placeholder="Факты/правила ресторана, стиль, ссылки."><?= htmlspecialchars($aibotPrompt) ?></textarea>
        </div>
      </details>

      <details id="blkSystemBase" style="margin-top:12px;">
        <summary style="cursor:pointer; font-weight:900;">System base</summary>
        <div class="small-muted" style="margin-top:6px;">Общие правила поведения (не выдумывать, уточнять, тон, ограничения).</div>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:10px;">
          <div class="small-muted" id="baseMeta"></div>
          <button class="btn" type="button" id="btnBaseSave">Сохранить</button>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <textarea id="baseText" placeholder="Общие правила поведения, политика, точность."><?= htmlspecialchars($aibotSystemBase) ?></textarea>
        </div>
      </details>

      <details id="blkSystemChat" style="margin-top:12px;">
        <summary style="cursor:pointer; font-weight:900;">System chat (Telegram)</summary>
        <div class="small-muted" style="margin-top:6px;">Правила форматирования ответа в Telegram и язык ответов в чате.</div>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
          <div class="form-group" style="min-width:160px; margin:0;">
            <label for="langChat">Язык</label>
            <div class="small-muted">auto — по языку вопроса; ru/en — принудительно.</div>
            <select id="langChat">
              <option value="auto" <?= ($aibotLangChat === 'auto' ? 'selected' : '') ?>>auto</option>
              <option value="ru" <?= ($aibotLangChat === 'ru' ? 'selected' : '') ?>>ru</option>
              <option value="en" <?= ($aibotLangChat === 'en' ? 'selected' : '') ?>>en</option>
            </select>
          </div>
          <div class="small-muted" id="chatMeta" style="margin:0;"></div>
          <button class="btn" type="button" id="btnChatSave">Сохранить</button>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <label for="chatText">Текст инструкции</label>
          <div class="small-muted">Здесь обычно: допустимые HTML-теги, запреты (например, без br), стиль ответа.</div>
          <textarea id="chatText" placeholder="Формат Telegram HTML, ограничения тегов."><?= htmlspecialchars($aibotSystemChat) ?></textarea>
        </div>
      </details>

      <details id="blkSystemDaily" style="margin-top:12px;">
        <summary style="cursor:pointer; font-weight:900;">System daily (саммари JSON)</summary>
        <div class="small-muted" style="margin-top:6px;">Инструкция для генерации daily summary: строгий JSON контракт, правила извлечения событий.</div>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
          <div class="form-group" style="min-width:160px; margin:0;">
            <label for="langDaily">Язык</label>
            <div class="small-muted">ru — саммари всегда на русском.</div>
            <select id="langDaily">
              <option value="auto" <?= ($aibotLangDaily === 'auto' ? 'selected' : '') ?>>auto</option>
              <option value="ru" <?= ($aibotLangDaily === 'ru' ? 'selected' : '') ?>>ru</option>
              <option value="en" <?= ($aibotLangDaily === 'en' ? 'selected' : '') ?>>en</option>
            </select>
          </div>
          <div class="small-muted" id="dailySysMeta" style="margin:0;"></div>
          <button class="btn" type="button" id="btnDailySysSave">Сохранить</button>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <textarea id="dailySysText" placeholder="JSON контракт и правила извлечения событий."><?= htmlspecialchars($aibotSystemDaily) ?></textarea>
        </div>
      </details>

      <details id="blkSystemAnnounce" style="margin-top:12px;">
        <summary style="cursor:pointer; font-weight:900;">System announce (анонс сайта)</summary>
        <div class="small-muted" style="margin-top:6px;">Инструкция для HTML-анонса (для сайта): допустимые теги, стиль, структура.</div>
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
          <div class="form-group" style="min-width:160px; margin:0;">
            <label for="langAnnounce">Язык</label>
            <div class="small-muted">ru — анонс всегда на русском.</div>
            <select id="langAnnounce">
              <option value="auto" <?= ($aibotLangAnnounce === 'auto' ? 'selected' : '') ?>>auto</option>
              <option value="ru" <?= ($aibotLangAnnounce === 'ru' ? 'selected' : '') ?>>ru</option>
              <option value="en" <?= ($aibotLangAnnounce === 'en' ? 'selected' : '') ?>>en</option>
            </select>
          </div>
          <div class="small-muted" id="announceSysMeta" style="margin:0;"></div>
          <button class="btn" type="button" id="btnAnnounceSysSave">Сохранить</button>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <textarea id="announceSysText" placeholder="HTML для сайта (допустимые теги)."><?= htmlspecialchars($aibotSystemAnnounce) ?></textarea>
        </div>
      </details>

      <details id="blkBehavior" style="margin-top:12px;" open>
      <summary style="cursor:pointer; font-weight:900;">Поведение агента и источников</summary>
      <div class="small-muted" style="margin-top:6px;">Это конфигурация того, какие источники доступны ИИ и как он должен ими пользоваться.</div>

      <div class="card" style="padding:14px; margin-top:10px;">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
          <div style="font-weight:800; font-size:13px;">Поведение (без хардкода)</div>
          <button class="btn" type="button" id="btnBehaviorSave">Сохранить</button>
        </div>
        <div class="small-muted" id="behaviorMeta" style="margin-top:6px;">
          <?= $aibotBehaviorUpdatedAt ? ('Обновлено: ' . htmlspecialchars($aibotBehaviorUpdatedAt)) : '' ?>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
          <div class="card" style="padding:14px;">
            <div style="font-weight:800; font-size:13px;">Агент</div>
            <div class="small-muted" style="margin-top:6px;">Планирует вызовы источников и формирует ответ по их результатам.</div>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top:10px;">
              <label style="margin:0; font-size:12px; font-weight:800; color:var(--muted, rgba(255,255,255,0.62));">
                <input id="behAgentEnable" type="checkbox" checked> Включен
              </label>
              <label style="margin:0; font-size:12px; font-weight:800; color:var(--muted, rgba(255,255,255,0.62));">
                <input id="behAgentAllowDailyGenerate" type="checkbox"> Разрешить daily_generate
              </label>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-top:10px;">
              <div class="form-group" style="margin:0;">
                <label for="behAgentMaxCalls">Макс. вызовов</label>
                <input id="behAgentMaxCalls" type="number" min="0" max="6" step="1" value="3">
              </div>
              <div class="form-group" style="margin:0;">
                <label for="behAgentPlanTemp">Plan temp</label>
                <input id="behAgentPlanTemp" type="number" min="0" max="1" step="0.05" value="0.1">
              </div>
              <div class="form-group" style="margin:0;">
                <label for="behAgentFinalTemp">Final temp</label>
                <input id="behAgentFinalTemp" type="number" min="0" max="1" step="0.05" value="0.35">
              </div>
            </div>
            <div class="form-group" style="margin-top:10px;">
              <label for="behAgentFinalMaxTokens">Max tokens</label>
              <input id="behAgentFinalMaxTokens" type="number" min="200" max="2500" step="50" value="1200">
            </div>
          </div>

          <div class="card" style="padding:14px;">
            <div style="font-weight:800; font-size:13px;">KB / Live</div>
            <div class="small-muted" style="margin-top:6px;">Поиск по базе знаний и live-подтягивание URL (только разрешённые домены).</div>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top:10px;">
              <label style="margin:0; font-size:12px; font-weight:800; color:var(--muted, rgba(255,255,255,0.62));">
                <input id="behKbEnable" type="checkbox" checked> Включен KB
              </label>
              <label style="margin:0; font-size:12px; font-weight:800; color:var(--muted, rgba(255,255,255,0.62));">
                <input id="behKbLiveEnable" type="checkbox" checked> Live fetch
              </label>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
              <div class="form-group" style="margin:0;">
                <label for="behKbLiveMaxDocs">Макс. live-доков</label>
                <input id="behKbLiveMaxDocs" type="number" min="0" max="6" step="1" value="2">
              </div>
              <div class="form-group" style="margin:0;">
                <label for="behKbLiveMaxLen">Макс. длина текста</label>
                <input id="behKbLiveMaxLen" type="number" min="500" max="60000" step="500" value="60000">
              </div>
            </div>
            <div class="form-group" style="margin-top:10px;">
              <label for="behKbCheckTriggers">Фразы “проверь в KB” (по одной на строку)</label>
              <textarea id="behKbCheckTriggers" style="min-height:110px;" placeholder="посмотри в базе знаний&#10;kb&#10;knowledge base"></textarea>
            </div>
          </div>
        </div>

        <div class="card" style="padding:14px; margin-top:12px;">
          <div style="font-weight:800; font-size:13px;">Инструменты (tools)</div>
          <div class="small-muted" style="margin-top:6px;">Галочки определяют, какие источники доступны агенту. Описание помогает агенту выбирать правильно.</div>
          <div class="table-wrap" style="margin-top:10px; overflow:auto;">
            <table style="width:max-content; min-width:100%; border-collapse:collapse;">
              <thead>
                <tr>
                  <th style="text-align:left; padding:8px 10px;">Tool</th>
                  <th style="text-align:left; padding:8px 10px;">Enabled</th>
                  <th style="text-align:left; padding:8px 10px;">Описание</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (['kb_search','kb_fetch_url','daily_get','daily_generate','menu_breakfasts','menu_most_expensive','menu_count_kitchen'] as $t): ?>
                  <tr data-tool="<?= htmlspecialchars($t) ?>">
                    <td style="padding:8px 10px; white-space:nowrap;"><?= htmlspecialchars($t) ?></td>
                    <td style="padding:8px 10px;"><input type="checkbox" data-tool-en="<?= htmlspecialchars($t) ?>"></td>
                    <td style="padding:8px 10px; min-width:380px;">
                      <input type="text" data-tool-desc="<?= htmlspecialchars($t) ?>" style="width:100%;" placeholder="Коротко: что делает и какие args">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
          <div class="card" style="padding:14px;">
            <div style="font-weight:800; font-size:13px;">Меню</div>
            <div class="small-muted" style="margin-top:6px;">Источник меню для инструментов menu_*.</div>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top:10px;">
              <label style="margin:0; font-size:12px; font-weight:800; color:var(--muted, rgba(255,255,255,0.62));">
                <input id="behMenuEnable" type="checkbox" checked> Включен
              </label>
            </div>
            <div class="form-group" style="margin-top:10px;">
              <label for="behMenuUrl">Menu URL</label>
              <input id="behMenuUrl" type="url" placeholder="https://veranda.my/links/menu.php">
            </div>
            <div class="form-group" style="margin-top:10px;">
              <label for="behMenuMaxLen">Макс. длина fetch</label>
              <input id="behMenuMaxLen" type="number" min="5000" max="60000" step="1000" value="60000">
            </div>
          </div>

          <div class="card" style="padding:14px;">
            <div style="font-weight:800; font-size:13px;">Chat policy (agent)</div>
            <div class="small-muted" style="margin-top:6px;">Короткие правила: “используй tool_results / не выдумывай”.</div>
            <div class="form-group" style="margin-top:10px;">
              <label for="behChatSystemAppend">System append</label>
              <textarea id="behChatSystemAppend" style="min-height:160px;" placeholder="Use payload.tool_results ..."></textarea>
            </div>
          </div>
        </div>

        <details style="margin-top:12px;">
          <summary style="cursor:pointer; font-weight:800;">Advanced: JSON</summary>
          <div class="form-group" style="margin-top:10px;">
            <textarea id="behaviorJson" placeholder="JSON настройки поведения."><?= htmlspecialchars($aibotBehaviorJson) ?></textarea>
          </div>
        </details>
      </div>
      </details>
    </div>

    <details id="blkKb" class="card" style="padding:18px;" open>
      <summary style="cursor:pointer; font-weight:900;">База знаний (KB)</summary>
      <div class="small-muted" style="margin-top:6px;">Редактируемые записи для ответов бота. Если у записи пустой текст, но указан URL, контент может подтягиваться live.</div>

      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px;">
        <div class="small-muted">Список и редактор</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn" type="button" id="btnKbNew">Новая запись</button>
          <button class="btn" type="button" id="btnKbRefresh">Обновить</button>
        </div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; align-items:flex-end;">
        <div class="form-group" style="flex:1; min-width:260px;">
          <label for="kbImportUrl">Импорт URL (только veranda.my)</label>
          <div class="small-muted">Создаёт запись KB из страницы и кладёт текст в поле “Текст”.</div>
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
            <div class="small-muted">Короткое имя документа (например: “Меню”, “Контакты”, “Правила бронирования”).</div>
            <input id="kbTitle" type="text">
          </div>
          <div class="form-group">
            <label for="kbTags">Теги</label>
            <div class="small-muted">Через запятую: помогает поиску (например: меню, завтраки, бар).</div>
            <input id="kbTags" type="text" placeholder="menu, breakfast, rules">
          </div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px; align-items:end;">
          <div class="form-group">
            <label for="kbSourceUrl">Source URL</label>
            <div class="small-muted">Если “Текст” пустой, может использоваться как live-источник.</div>
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
          <div class="small-muted">Если заполнен — это “стабильный” источник. Если пусто + есть URL — будет подтягиваться live (если включено).</div>
          <textarea id="kbContent" style="min-height:220px;"></textarea>
        </div>
        <div class="small-muted" id="kbEditorMeta"></div>
      </div>
    </details>

    <details id="blkTest" class="card" style="padding:18px;" open>
      <summary style="cursor:pointer; font-weight:900;">Тест бота</summary>
      <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px;">
        <div class="small-muted">Тестирует агентный режим и показывает trace вызовов.</div>
        <button class="btn" type="button" id="btnAgentAsk">Спросить</button>
      </div>
      <div class="small-muted" style="margin-top:6px;">
        Показывает ответ и trace: какие источники были вызваны и что вернули.
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label for="agentQuestion">Вопрос</label>
        <div class="small-muted">Любой вопрос, как в Telegram.</div>
        <input id="agentQuestion" type="text" placeholder="Например: дай анонс на сегодня">
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label for="agentChatId">Chat ID (необязательно, чтобы подтянуть историю)</label>
        <div class="small-muted">Если указать chat id, подмешается история сообщений из DB.</div>
        <input id="agentChatId" type="text" placeholder="169510539">
      </div>
      <div class="small-muted" id="agentMeta" style="margin-top:8px;"></div>

      <details style="margin-top:12px;">
        <summary style="cursor:pointer; font-weight:800;">Отладка: показать контекст</summary>
        <div class="small-muted" style="margin-top:6px;">Показывает system/payload до вызова агента (для дебага).</div>
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
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
          <button class="btn" type="button" id="btnCtxPreview">Показать контекст</button>
        </div>
        <div class="small-muted" id="ctxMeta" style="margin-top:8px;"></div>
      </details>
    </details>

    <details id="blkOutput" class="card" style="padding:18px;" open>
      <summary style="cursor:pointer; font-weight:900;">Вывод</summary>
      <div class="small-muted" id="outMeta" style="margin-top:6px;"></div>
      <div class="card" style="padding:14px; margin-top:10px; overflow:auto;" id="outBox"></div>
    </details>
  </div>
</div>
