<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Payday2Controller
{
    public function dispatch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $GLOBALS['_PAYDAY2_SLIM_MODE']    = true;
        $GLOBALS['_PAYDAY2_USE_LAYOUT']  = true;
        $GLOBALS['_PAYDAY2_HEAD_EXTRA']  = '';
        $GLOBALS['_PAYDAY2_HTTP_CODE']   = 200;
        $GLOBALS['_PAYDAY2_SLIM_JSON']   = '';
        $GLOBALS['_PAYDAY2_REDIRECT_URL'] = '';

        global $db, $token;
        if (!isset($db)) {
            $db = new \App\Classes\Database(
                $_ENV['DB_HOST']         ?? 'localhost',
                $_ENV['DB_NAME']         ?? '',
                $_ENV['DB_USER']         ?? '',
                $_ENV['DB_PASS']         ?? '',
                $_ENV['DB_TABLE_SUFFIX'] ?? ''
            );
        }
        $token = $_ENV['POSTER_API_TOKEN'] ?? '';

        foreach ($request->getQueryParams() as $k => $v) {
            $_GET[$k] = $v;
        }
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                foreach ($body as $k => $v) {
                    $_POST[$k] = $v;
                }
            }
        }

        $obLevel = ob_get_level();
        ob_start();
        $threw = false;
        try {
            require __DIR__ . '/../../payday2/index.php';
        } catch (\RuntimeException $e) {
            $threw = true;
            if ($e->getMessage() !== '_payday2_done') {
                while (ob_get_level() > $obLevel) ob_end_clean();
                throw $e;
            }
            // payday2_do_exit() / payday2_redirect() already cleaned their ob level
            while (ob_get_level() > $obLevel) ob_end_clean();
        }

        if ($threw) {
            if ($GLOBALS['_PAYDAY2_REDIRECT_URL'] !== '') {
                return $response
                    ->withHeader('Location', $GLOBALS['_PAYDAY2_REDIRECT_URL'])
                    ->withStatus(302);
            }

            $json = $GLOBALS['_PAYDAY2_SLIM_JSON'] ?: '{}';
            $code = (int)($GLOBALS['_PAYDAY2_HTTP_CODE'] ?? 200);
            if ($code < 100 || $code > 599) $code = 200;

            $response->getBody()->write($json);
            return $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withStatus($code);
        }

        // Page view: no exception thrown, ob still has the rendered HTML
        $content = ob_get_clean() ?: '';

        $pageTitle   = 'PayDay2';
        $currentPath = '/payday2';
        $pd2HeadExtra = (string)($GLOBALS['_PAYDAY2_HEAD_EXTRA'] ?? '');
        $headExtra   = $pd2HeadExtra . "\n"
                     . '<style>.container{max-width:none;padding:0;margin:0}</style>';

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
