<?php

require_once __DIR__ . '/../sections/menu/section.php';

if (($_GET['ajax'] ?? '') === 'menu_publish') {
    admin_menu_ajax_publish($db);
}

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax === 'menu_edit_form') {
    $posterId = (int)($_GET['poster_id'] ?? 0);
    if ($posterId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        exit;
    }

    $_GET['view'] = 'edit';
    $_GET['poster_id'] = $posterId;
    $menuView = 'edit';
    $state = admin_menu_section_state($db, $posterToken, 'menu', $message, $error);
    $menuEdit = $state['menuEdit'];
    $menuWorkshops = $state['menuWorkshops'];
    $menuCategories = $state['menuCategories'];
    $stripNumberPrefix = $state['stripNumberPrefix'];

    header('Content-Type: text/html; charset=utf-8');
    if (!$menuEdit) {
        http_response_code(404);
        echo '<div class="error">Не найдено</div>';
        exit;
    }
    ob_start();
    require __DIR__ . '/../views/menu/edit.php';
    $html = (string)ob_get_clean();
    echo $html;
    exit;
}

if ($ajax === 'menu_edit_save') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $posterId = (int)($_POST['poster_id'] ?? 0);
    if ($posterId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_GET['view'] = 'edit';
    $_GET['poster_id'] = $posterId;
    $state = admin_menu_section_state($db, $posterToken, 'menu', $message, $error);
    if ($error !== '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = admin_menu_get_list_row_by_poster_id($db, $posterId);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'row' => $row, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$state = admin_menu_section_state($db, $posterToken, $tab, $message, $error);
$menuItems = $state['menuItems'];
$menuTotal = $state['menuTotal'];
$menuPerPage = $state['menuPerPage'];
$menuPage = $state['menuPage'];
$menuEdit = $state['menuEdit'];
$menuWorkshops = $state['menuWorkshops'];
$menuCategories = $state['menuCategories'];
$menuSyncMeta = $state['menuSyncMeta'];
$menuSyncAtIso = $state['menuSyncAtIso'];
$menuView = $state['menuView'];
$mainItemCounts = $state['mainItemCounts'];
$stripNumberPrefix = $state['stripNumberPrefix'];
