<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

veranda_require('employees');

$posterToken = (string)($_ENV['POSTER_API_TOKEN'] ?? '');
if ($posterToken === '') {
    http_response_code(500);
    echo 'POSTER_API_TOKEN не задан';
    exit;
}

require_once __DIR__ . '/Model.php';
$model = new \App\Models\EmployeesModel($db, $posterToken);

$ajax = $_GET['ajax'] ?? '';

if ($ajax === 'save_rate') {
    $model->saveRate();
    exit;
}

if ($ajax === 'load') {
    $model->load();
    exit;
}

if ($ajax === 'hours_by_day') {
    $model->hoursByDay();
    exit;
}

if ($ajax === 'tips_prepare') {
    $model->tipsPrepare();
    exit;
}

if ($ajax === 'tips_run') {
    $model->tipsRun();
    exit;
}

if ($ajax === 'tips_cancel') {
    $model->tipsCancel();
    exit;
}

if ($ajax === 'pay_salary') {
    $model->paySalary();
    exit;
}

if ($ajax === 'pay_extra') {
    $model->payExtra();
    exit;
}

if ($ajax === 'ltp_load') {
    $model->ltpLoad();
    exit;
}

if ($ajax === 'pay_meta') {
    $model->payMeta();
    exit;
}

if ($ajax === 'pay_meta_salary') {
    $model->payMetaSalary();
    exit;
}

if ($ajax === 'pay_meta_extra') {
    $model->payMetaExtra();
    exit;
}

if ($ajax === 'tips_balance') {
    $model->tipsBalance();
    exit;
}

if ($ajax === 'employee_lookup') {
    $model->employeeLookup();
    exit;
}

if ($ajax === 'pay_tips') {
    $model->payTips();
    exit;
}

if ($ajax === 'fix_salary_tx') {
    $model->fixSalaryTx();
    exit;
}

if ($ajax === 'fix_salary_update_comment') {
    $model->fixSalaryUpdateComment();
    exit;
}


$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

require_once __DIR__ . '/view.php';
