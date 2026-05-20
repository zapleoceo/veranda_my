<div class="container">
    <div class="card">
        <div class="filters">
            <fieldset class="period-group" data-period="work">
                <legend>1. Период работы (расчёт ЗП)</legend>
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>" title="Начало рабочего периода">
                <span class="period-sep">→</span>
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>" title="Конец рабочего периода">
            </fieldset>
            <fieldset class="period-group" data-period="paid">
                <legend>2. Период выплат (поиск транзакций)
                    <button type="button" id="paidResyncBtn" class="period-resync" title="Сбросить = период работы + 3 дня">↻</button>
                </legend>
                <input type="date" id="paidFrom" value="" title="Начало периода поиска выплат">
                <span class="period-sep">→</span>
                <input type="date" id="paidTo" value="" title="Конец периода поиска выплат">
            </fieldset>
            <div class="emp-style-8">
                <button type="button" id="loadBtn">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
                <button type="button" class="secondary emp-style-1" id="cancelBtn">Отменить</button>
                <div class="progress" id="prog">
                    <div class="bar"><span id="progBar"></span></div>
                    <div class="label" id="progLabel">0%</div>
                    <div class="desc" id="progDesc"></div>
                </div>
                <button type="button" class="secondary" id="payExtraBtn">PayExtra</button>
                <button type="button" class="secondary" id="fixBtn">FIX</button>
            </div>
            <button type="button" class="help-btn" id="helpBtn" title="Инструкция">?</button>
        </div>
        <div class="error emp-style-1" id="err"></div>
        <div class="muted emp-style-3" id="ltpRangeNote"></div>
        <div class="emp-style-7">
            <div class="cols-dd">
                <button type="button" class="secondary" id="colsBtn">
                    <svg class="cols-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 5h16M7 12h10M10 19h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Колонки
                </button>
                <div class="cols-menu" id="colsMenu" hidden></div>
            </div>
            <div class="cols-dd">
                <button type="button" class="secondary" id="rolesBtn">
                    <svg class="cols-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 5h16M7 12h10M10 19h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Роли
                </button>
                <div class="cols-menu" id="rolesMenu" hidden></div>
            </div>
            <label class="muted emp-style-14">
                <input type="checkbox" id="hideZero">
                Пустые
            </label>
        </div>
        <div class="table-wrap emp-style-9">
            <div class="emp-style-13">
                <table id="empTable">
                    <thead>
                    <tr>
                        <th id="thUid" class="col-id emp-style-12" data-sort="user_id" title="ID сотрудника в Poster">ID</th>
                        <th id="thName" class="col-name emp-style-12" data-sort="name" title="Имя">Имя</th>
                        <th id="thRate" class="col-rate emp-style-2" data-sort="rate" title="Ставка ₫/час">Ставка</th>
                        <th id="thRole" class="col-role emp-style-12" data-sort="role_name" title="Должность">Роль</th>
                        <th id="thChecks" class="col-checks emp-style-2" data-sort="checks" title="Количество чеков">Чеки</th>
                        <th id="thHours" class="col-hours emp-style-2" data-sort="worked_hours" title="Отработанные часы">Часы</th>
                        <th id="thTips" class="col-tips emp-style-2" data-sort="tips_minor" title="Чаевые по данным Poster">Tips</th>
                        <th id="thTipsPaid" class="col-paid emp-style-2" data-sort="tips_paid_minor" title="Уже выплаченные чаевые">Tips ✓</th>
                        <th id="thTtp" class="col-ttp emp-style-2" data-sort="tips_to_pay_minor" title="Осталось выплатить чаевых">Tips →</th>
                        <th id="thSalary" class="col-salary emp-style-2" data-sort="salary_minor" title="Зарплата = Ставка × Часы">ЗП</th>
                        <th id="thSlrPaid" class="col-slr emp-style-2" data-sort="slr_paid_minor" title="Уже выплаченная зарплата">ЗП ✓</th>
                        <th id="thSalaryToPay" class="col-salarytopay emp-style-2" data-sort="salary_to_pay_vnd" title="Осталось выплатить зарплаты">ЗП →</th>
                    </tr>
                    </thead>
                    <tbody id="tbody"></tbody>
                    <tfoot>
                    <tr id="totalsRow">
                        <td class="col-id"></td>
                        <td class="col-name">ИТОГО</td>
                        <td class="col-rate"></td>
                        <td class="col-role"></td>
                        <td class="col-checks emp-style-11"></td>
                        <td class="col-hours emp-style-11"></td>
                        <td class="col-tips emp-style-11"><span id="totTips">0</span></td>
                        <td class="col-paid emp-style-11"><span id="totTipsPaid">0</span></td>
                        <td class="col-ttp emp-style-11"><span id="totTtp">0</span></td>
                        <td class="col-salary emp-style-11"><span id="totSalary">0</span></td>
                        <td class="col-slr emp-style-11"><span id="totSlrPaid">0</span></td>
                        <td class="col-salarytopay emp-style-11"><span id="totSalaryToPay">0</span></td>
                    </tr>
                    </tfoot>
                </table>
                <div class="muted emp-style-10" id="tipsBalanceTotals">
                    Tips (на счету BIDV): <span id="tipsAccBalance">—</span> &middot; TTP в таблице: <span id="tipsTableSum">—</span> &middot; Остаток: <span id="tipsBalanceDiff">—</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-backdrop emp-style-1" id="payExtraModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="payExtraTitle">
        <h3 id="payExtraTitle">PayExtra</h3>
        <div class="body payextra-fields">
            <label>
                Сотрудник
                <select id="payExtraEmp"></select>
            </label>
            <div class="payextra-row2">
                <label>
                    Тип
                    <select id="payExtraKind">
                        <option value="tips">Tips</option>
                        <option value="salary">Salary</option>
                    </select>
                </label>
                <label>
                    Сумма (VND)
                    <input type="text" id="payExtraAmount" inputmode="numeric" autocomplete="off">
                </label>
            </div>
            <label>
                Счет списания
                <select id="payExtraAccount"></select>
            </label>
            <label>
                Комментарий
                <input type="text" id="payExtraComment" readonly>
            </label>
        </div>
        <div class="sub">
            <label class="emp-style-14">
                <input type="checkbox" id="payExtraChecked">
                да проверил
            </label>
        </div>
        <div class="actions">
            <button type="button" class="btn2" id="payExtraCancel">Отмена</button>
            <button type="button" class="btn2 primary" id="payExtraPay" disabled>Оплатить</button>
        </div>
        <div class="payextra-overlay" aria-hidden="true">
            <span class="spinner emp-style-0"></span>
            <div>Загрузка…</div>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="fixModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="fixTitle">
        <button type="button" id="fixClose" style="position:absolute; top:10px; right:10px; background:transparent; border:0; font-size:20px; line-height:20px; cursor:pointer;">✕</button>
        <h3 id="fixTitle">FIX <button type="button" id="fixEyeBtn" style="background:transparent; border:0; cursor:pointer; padding:0 6px; font-size:18px; line-height:18px; vertical-align:middle;" title="Скрыть/показать SLR">👁</button></h3>
        <div class="body" id="fixBody"></div>
    </div>
</div>

<div class="modal-backdrop" id="paidModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="paidTitle">
        <h3 id="paidTitle">Подтверждение</h3>
        <div class="body" id="paidText"></div>
        <div class="sub">
            <label class="emp-style-14">
                <input type="checkbox" id="paidChecked">
                да проверил
            </label>
        </div>
        <div class="actions">
            <button type="button" class="btn2" id="paidCancel">Отмена</button>
            <button type="button" class="btn2 primary" id="paidOk" disabled>OK</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="helpModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
        <h3 id="helpTitle">Инструкция</h3>
        <div class="body help-body">
            <div class="emp-style-4"><b>ЗАГРУЗИТЬ</b> — загружает данные по сотрудникам за выбранный период и считает все суммы в таблице.</div>
            <div class="emp-style-4"><b>Отменить</b> — останавливает текущую загрузку, если долго ждём.</div>
            <div class="emp-style-4"><b>Колонки</b> — выбор видимых колонок. Настройка сохраняется в браузере.</div>
            <div class="emp-style-4"><b>Роли</b> — фильтр по должностям. Доступен после загрузки данных, сохраняется в браузере.</div>
            <div class="emp-style-4"><b>Пустые</b> — при включении показывает пустые строки, при выключении скрывает.</div>
            <div class="emp-style-4"><b>Сортировка</b> — клик по заголовку колонки сортирует таблицу.</div>
            <div class="emp-style-4"><b>PAY</b> — создаёт финансовую транзакцию выплаты (Tips или Salary) на сумму "к выплате" по выбранному сотруднику. Перед созданием нужно подтвердить чекбоксом "да проверил".</div>
            <div class="emp-style-4"><b>TipsPaid / SlrPaid</b> — список прошлых выплат: слева дата/время, справа тип выплаты/сумма выплаты.</div>
            <div class="emp-style-4"><b>Два периода</b> — на странице два независимых диапазона дат:
                <br>• <b>Период работы</b> — за какой период считаем Tips, Salary, Чеки, Часы (т.е. за какие смены платим).
                <br>• <b>Период выплат</b> — где искать уже сделанные выплаты (TipsPaid/SlrPaid). По умолчанию = период работы + 3 дня (так как платим с задержкой). Кнопка «↻» сбрасывает к этому значению. Можно вручную задать любой диапазон.</div>
            <div class="emp-style-4"><b>PayExtra</b> — ручная выплата (Tips/Salary) с выбором сотрудника, счета и комментарием.</div>
            <div class="emp-style-4"><b>ИТОГО</b> — сумма по колонкам внизу таблицы.</div>
            <div class="emp-style-5">Пояснения по колонкам</div>
            <div class="emp-style-6"><b>ID</b> — ID сотрудника в Poster.</div>
            <div class="emp-style-6"><b>name</b> — имя сотрудника.</div>
            <div class="emp-style-6"><b>Rate</b> — ставка. Можно редактировать, сохраняется автоматически при выходе из поля или по Enter.</div>
            <div class="emp-style-6"><b>role_name</b> — должность (роль).</div>
            <div class="emp-style-6"><b>Чеков</b> — количество чеков за период.</div>
            <div class="emp-style-6"><b>ЧасыРаботы</b> — часы работы за период.</div>
            <div class="emp-style-6"><b>Tips</b> — сумма чаевых за период (по данным Poster).</div>
            <div class="emp-style-6"><b>TipsPaid</b> — сколько чаевых уже выплачено сотруднику за период (по финансовым транзакциям).</div>
            <div class="emp-style-6"><b>TipsToPay</b> — сколько осталось выплатить чаевых: Tips − TipsPaid (если меньше 0, то 0).</div>
            <div class="emp-style-6"><b>Salary</b> — зарплата по ставке: Rate × ЧасыРаботы.</div>
            <div class="emp-style-6"><b>SlrPaid</b> — сколько зарплаты уже выплачено (по финансовым транзакциям).</div>
            <div class="emp-style-6"><b>SalaryToPay</b> — сколько осталось выплатить зарплаты: Salary − SlrPaid (если меньше 0, то 0).</div>
            <div><b>Tips (на счету…)</b> — сверка суммы Tips по счёту с "TTP в таблице" и расчёт остатка.</div>
        </div>
        <div class="actions">
            <button type="button" class="btn2 primary" id="helpClose">OK</button>
        </div>
    </div>
</div>

<script>
window.__USER_EMAIL__ = <?= json_encode((string) ($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.__EMPLOYEES_CSRF__ = <?= json_encode($employeesCsrf, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/employees_view.js?v=20260520_2periods" defer></script>
