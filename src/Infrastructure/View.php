<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Psr\Http\Message\ResponseInterface;

class View
{
    private static string $_base = '';

    public static function init(string $basePath): void
    {
        self::$_base = rtrim($basePath, '/');
    }

    public static function render(
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200
    ): ResponseInterface {
        $file = self::$_base . '/' . ltrim($template, '/') . '.php';

        ob_start();
        extract($data, EXTR_SKIP);
        require $file;
        $html = ob_get_clean();

        $response->getBody()->write((string) $html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }
}
