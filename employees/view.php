<?php
// View
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ЗП сотрудников</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css?v=1" />
    <script src="/assets/app.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260413_0200">
  <link rel="stylesheet" href="/assets/css/employees.css?v=20260420_0001">
  <link rel="stylesheet" href="/employees/style.css">
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left"><div class="nav-title">ЗП сотрудников</div></div>
        <div class="nav-mid"></div>
        <?php require dirname(__DIR__) . '/partials/user_menu.php'; ?>
    </div>

    <div class="card">
        <div class="filters">
            <label>
                Дата начала
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
            </label>
            <div class="emp-style-8">
                <button type="button" id="loadBtn">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
                <button type="button" class="secondary emp-style-1" id="cancelBtn" >Отменить</button>
                <div class="progress" id="prog">
                    <div class="bar"><span id="progBar"></span></div>
                    <div class="label" id="progLabel">0%</div>
                    <div class="desc" id="progDesc"></div>
                </div>
                <button type="button" class="secondary" id="payExtraBtn">PayExtra</button>
            </div>
            <button type="button" class="help-btn" id="helpBtn" title="Инструкция">?</button>
        </div>
        <div class="error emp-style-1" id="err" ></div>
        <div class="muted emp-style-3" id="ltpRangeNote" ></div>
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
            <label class="muted emp-style-14" >
                <input type="checkbox" id="hideZero">
                Пустые
            </label>
        </div>
        <div class="table-wrap emp-style-9" >
            <div class="emp-style-13">
                <table id="empTable">
                    <thead>
                <tr>
                    <th id="thUid" class="col-id emp-style-12" data-sort="user_id" >ID</th>
                    <th id="thName" class="col-name emp-style-12" data-sort="name" >name</th>
                    <th id="thRate" class="col-rate emp-style-2" data-sort="rate" >Rate</th>
                    <th id="thRole" class="col-role emp-style-12" data-sort="role_name" >role_name</th>
                    <th id="thChecks" class="col-checks emp-style-2" data-sort="checks" >Чеков</th>
                    <th id="thHours" class="col-hours emp-style-2" data-sort="worked_hours" >ЧасыРаботы</th>
                    <th id="thTips" class="col-tips emp-style-2" data-sort="tips_minor" >Tips</th>
                    <th id="thTipsPaid" class="col-paid emp-style-2" data-sort="tips_paid_minor" >TipsPaid</th>
                    <th id="thTtp" class="col-ttp emp-style-2" data-sort="tips_to_pay_minor" >TipsToPay</th>
                    <th id="thSalary" class="col-salary emp-style-2" data-sort="salary_minor" >Salary</th>
                    <th id="thSlrPaid" class="col-slr emp-style-2" data-sort="slr_paid_minor" >SlrPaid</th>
                    <th id="thSalaryToPay" class="col-salarytopay emp-style-2" data-sort="salary_to_pay_vnd" >SalaryToPay</th>
                </tr>
                </thead>
                <tbody id="tbody"></tbody>
                <tfoot>
                <tr id="totalsRow">
                    <td class="col-id"></td>
                    <td class="col-name">ИТОГО</td>
                    <td class="col-rate"></td>
                    <td class="col-role"></td>
                    <td class="col-checks emp-style-11" ></td>
                    <td class="col-hours emp-style-11" ></td>
                    <td class="col-tips emp-style-11" ><span id="totTips">0</span></td>
                    <td class="col-paid emp-style-11" ><span id="totTipsPaid">0</span></td>
                    <td class="col-ttp emp-style-11" ><span id="totTtp">0</span></td>
                    <td class="col-salary emp-style-11" ><span id="totSalary">0</span></td>
                    <td class="col-slr emp-style-11" ><span id="totSlrPaid">0</span></td>
                    <td class="col-salarytopay emp-style-11" ><span id="totSalaryToPay">0</span></td>
                </tr>
                </tfoot>
            </table>
            <div class="muted emp-style-10" id="tipsBalanceTotals" >
                Tips (на счету BIDV): <span id="tipsAccBalance">—</span> &middot; TTP в таблице: <span id="tipsTableSum">—</span> &middot; Остаток: <span id="tipsBalanceDiff">—</span>
            </div>
        </div>
        </div>
    </div>
</div>

<div class="modal-backdrop emp-style-1" id="payExtraModal" >
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
                    <input type="number" id="payExtraAmount" min="1" step="1" inputmode="numeric">
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
            <span class="spinner emp-style-0" ></span>
            <div>Загрузка…</div>
        </div>
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
            <div class="emp-style-4">
                <b>ЗАГРУЗИТЬ</b> — загружает данные по сотрудникам за выбранный период и считает все суммы в таблице.
            </div>
            <div class="emp-style-4">
                <b>Отменить</b> — останавливает текущую загрузку, если долго ждём.
            </div>
            <div class="emp-style-4">
                <b>Колонки</b> — выбор видимых колонок. Настройка сохраняется в браузере.
            </div>
            <div class="emp-style-4">
                <b>Роли</b> — фильтр по должностям. Доступен после загрузки данных, сохраняется в браузере.
            </div>
            <div class="emp-style-4">
                <b>Пустые</b> — при включении показывает пустые строки, при выключении скрывает.
            </div>
            <div class="emp-style-4">
                <b>Сортировка</b> — клик по заголовку колонки сортирует таблицу.
            </div>
            <div class="emp-style-4">
                <b>PAY</b> — создаёт финансовую транзакцию выплаты (Tips или Salary) на сумму “к выплате” по выбранному сотруднику.
                Перед созданием нужно подтвердить чекбоксом “да проверил”.
            </div>
            <div class="emp-style-4">
                <b>TipsPaid / SlrPaid</b> — список прошлых выплат: слева дата/время, справа тип выплаты/сумма выплаты.
            </div>
            <div class="emp-style-4">
                <b>Учет выплат</b> — для расчёта TipsPaid/SlrPaid выплаты берутся по смещённому периоду (например: 2026-04-09 — 2026-04-15). Смещение сделано, чтобы не захватывать выплаты прошлой недели и полностью захватить выплаты текущей недели.
            </div>
            <div class="emp-style-4">
                <b>PayExtra</b> — ручная выплата (Tips/Salary) с выбором сотрудника, счета и комментарием.
            </div>
            <div class="emp-style-4">
                <b>ИТОГО</b> — сумма по колонкам внизу таблицы.
            </div>
            <div class="emp-style-5">Пояснения по колонкам</div>
            <div class="emp-style-6">
                <b>ID</b> — ID сотрудника в Poster.
            </div>
            <div class="emp-style-6">
                <b>name</b> — имя сотрудника.
            </div>
            <div class="emp-style-6">
                <b>Rate</b> — ставка. Можно редактировать, сохраняется автоматически при выходе из поля или по Enter.
            </div>
            <div class="emp-style-6">
                <b>role_name</b> — должность (роль).
            </div>
            <div class="emp-style-6">
                <b>Чеков</b> — количество чеков за период.
            </div>
            <div class="emp-style-6">
                <b>ЧасыРаботы</b> — часы работы за период.
            </div>
            <div class="emp-style-6">
                <b>Tips</b> — сумма чаевых за период (по данным Poster).
            </div>
            <div class="emp-style-6">
                <b>TipsPaid</b> — сколько чаевых уже выплачено сотруднику за период (по финансовым транзакциям).
            </div>
            <div class="emp-style-6">
                <b>TipsToPay</b> — сколько осталось выплатить чаевых: Tips − TipsPaid (если меньше 0, то 0).
            </div>
            <div class="emp-style-6">
                <b>Salary</b> — зарплата по ставке: Rate × ЧасыРаботы.
            </div>
            <div class="emp-style-6">
                <b>SlrPaid</b> — сколько зарплаты уже выплачено (по финансовым транзакциям).
            </div>
            <div class="emp-style-6">
                <b>SalaryToPay</b> — сколько осталось выплатить зарплаты: Salary − SlrPaid (если меньше 0, то 0).
            </div>
            <div>
                <b>Tips (на счету…)</b> — сверка суммы Tips по счёту с “TTP в таблице” и расчёт остатка.
            </div>
        </div>
        <div class="actions">
            <button type="button" class="btn2 primary" id="helpClose">OK</button>
        </div>
    </div>
</div>

<script>
window.__USER_EMAIL__ = <?= json_encode((string)($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/employees/script.js" defer></script>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
