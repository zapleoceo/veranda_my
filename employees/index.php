<?php

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';
require_once __DIR__ . '/http.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

// Page-level permission gate. Strict via the shared Permissions helper:
// denies if user_permissions isn't loaded OR `employees` is falsy. Same
// rule as the sidebar link visibility and EmployeesController::index,
// so a revoked user gets a consistent 403 everywhere.
//
// For AJAX requests we return JSON 403 so the front-end can show a
// proper toast instead of trying to parse the legacy "Forbidden" text.
$ajax = (string)($_GET['ajax'] ?? '');
if (!\App\Infrastructure\Permissions::can('employees')) {
    if ($ajax !== '') {
        \App\Infrastructure\Permissions::denyJsonExit('Нет прав на страницу «ЗП сотрудников»');
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$posterToken = (string)($_ENV['POSTER_API_TOKEN'] ?? '');
if ($posterToken === '') {
    http_response_code(500);
    echo 'POSTER_API_TOKEN не задан';
    exit;
}

require_once __DIR__ . '/Model.php';
$model = new \App\Models\EmployeesModel($db, $posterToken);

$employeesCsrf = employees_csrf_ensure();

if ($ajax !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        employees_csrf_require();
    }
    switch ($ajax) {
        case 'save_rate': $model->saveRate(); break;
        case 'load': $model->load(); break;
        case 'hours_by_day': $model->hoursByDay(); break;
        case 'tips_prepare': $model->tipsPrepare(); break;
        case 'tips_run': $model->tipsRun(); break;
        case 'tips_cancel': $model->tipsCancel(); break;
        case 'pay_salary': $model->paySalary(); break;
        case 'pay_extra': $model->payExtra(); break;
        case 'ltp_load': $model->ltpLoad(); break;
        case 'pay_meta': $model->payMeta(); break;
        case 'pay_meta_salary': $model->payMetaSalary(); break;
        case 'pay_meta_extra': $model->payMetaExtra(); break;
        case 'tips_balance': $model->tipsBalance(); break;
        case 'employee_lookup': $model->employeeLookup(); break;
        case 'pay_tips': $model->payTips(); break;
        case 'fix_salary_tx': $model->fixSalaryTx(); break;
        case 'fix_salary_update_comment': $model->fixSalaryUpdateComment(); break;
        default: employees_json_exit(['ok' => false, 'error' => 'Unknown action'], 400);
    }
    exit;
}


// HTML rendering removed: EmployeesController renders src/Views/employees_content.php
// inside src/Views/layout.php; this file only handles AJAX (early-exit above).
