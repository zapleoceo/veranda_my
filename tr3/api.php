<?php
declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';

$ctx = require __DIR__ . '/api_context.php';
$ajax = (string)($_GET['ajax'] ?? '');

switch ($ajax) {
  case 'bootstrap':
    require_once __DIR__ . '/api_misc.php';
    tr3_api_bootstrap($ctx);

  case 'log_js':
    require_once __DIR__ . '/api_misc.php';
    tr3_api_log_js($ctx);

  case 'free_tables':
    require_once __DIR__ . '/api_poster.php';
    tr3_api_free_tables($ctx);

  case 'reservations':
    require_once __DIR__ . '/api_poster.php';
    tr3_api_reservations($ctx);

  case 'cap_check':
    require_once __DIR__ . '/api_poster.php';
    tr3_api_cap_check($ctx);

  case 'submit_booking':
    require_once __DIR__ . '/api_booking.php';
    tr3_api_submit_booking($ctx);

  case 'tg_state_create':
    require_once __DIR__ . '/api_states.php';
    tr3_api_tg_state_create($ctx);

  case 'wa_state_create':
    require_once __DIR__ . '/api_states.php';
    tr3_api_wa_state_create($ctx);

  case 'tg_state_get':
    require_once __DIR__ . '/api_states.php';
    tr3_api_tg_state_get($ctx);

  case 'wa_state_get':
    require_once __DIR__ . '/api_states.php';
    tr3_api_wa_state_get($ctx);

  case 'menu_preorder':
    require_once __DIR__ . '/api_menu.php';
    tr3_api_menu_preorder($ctx);
}

api_json_headers(false);
api_send_json(['ok' => false, 'error' => 'Not found'], 404);
