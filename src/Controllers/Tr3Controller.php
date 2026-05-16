<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Tr3Controller
{
    private const API_DIR = __DIR__ . '/../../tr3';

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        require self::API_DIR . '/index.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function api(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $GLOBALS['_TR3_SLIM_MODE']     = true;
        $GLOBALS['_TR3_SLIM_RESPONSE'] = null;

        ob_start();
        try {
            require_once self::API_DIR . '/api_helpers.php';
            $ctx  = require self::API_DIR . '/api_context.php';
            $ajax = (string)($request->getQueryParams()['ajax'] ?? '');

            $this->_dispatch($ajax, $ctx);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '_tr3_api_done') throw $e;
        } finally {
            ob_end_clean();
            unset($GLOBALS['_TR3_SLIM_MODE']);
        }

        $result = $GLOBALS['_TR3_SLIM_RESPONSE'] ?? ['data' => ['ok' => false, 'error' => 'not found'], 'code' => 404];

        $response->getBody()->write((string)json_encode($result['data'], JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($result['code']);
    }

    private function _dispatch(string $ajax, array $ctx): void
    {
        switch ($ajax) {
            case 'bootstrap':
            case 'log_js':
                require_once self::API_DIR . '/api_misc.php';
                $ajax === 'bootstrap' ? tr3_api_bootstrap($ctx) : tr3_api_log_js($ctx);
                break;

            case 'free_tables':
            case 'reservations':
            case 'cap_check':
            case 'hall_tables':
                require_once self::API_DIR . '/api_poster.php';
                match ($ajax) {
                    'free_tables' => tr3_api_free_tables($ctx),
                    'reservations' => tr3_api_reservations($ctx),
                    'cap_check'   => tr3_api_cap_check($ctx),
                    'hall_tables' => tr3_api_hall_tables($ctx),
                };
                break;

            case 'submit_booking':
                require_once self::API_DIR . '/api_booking.php';
                tr3_api_submit_booking($ctx);
                break;

            case 'tg_state_create':
            case 'tg_state_get':
            case 'wa_state_create':
            case 'wa_state_get':
                require_once self::API_DIR . '/api_states.php';
                match ($ajax) {
                    'tg_state_create' => tr3_api_tg_state_create($ctx),
                    'tg_state_get'    => tr3_api_tg_state_get($ctx),
                    'wa_state_create' => tr3_api_wa_state_create($ctx),
                    'wa_state_get'    => tr3_api_wa_state_get($ctx),
                };
                break;

            case 'menu_preorder':
                require_once self::API_DIR . '/api_menu.php';
                tr3_api_menu_preorder($ctx);
                break;

            case 'events_for_day':
                require_once self::API_DIR . '/api_events.php';
                tr3_api_events_for_day($ctx);
                break;

            default:
                api_json_headers(false);
                api_send_json(['ok' => false, 'error' => 'not found'], 404);
        }
    }
}
