<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MenuPublicService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MenuPublicController
{
    public function __construct(private readonly MenuPublicService $service) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query    = $request->getQueryParams();
        $cookies  = $request->getCookieParams();
        $accept   = $request->getHeaderLine('Accept-Language');

        $requested   = isset($query['lang']) ? (string)$query['lang'] : null;
        $cookieLang  = isset($cookies['links_lang']) ? (string)$cookies['links_lang'] : null;
        $lang        = $this->service->resolveLanguage($requested, $cookieLang, $accept);
        $explicitLang = $requested !== null && $lang === strtolower($requested);

        if ($requested !== null && $lang === strtolower($requested)) {
            setcookie('links_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        }

        $groups       = $this->service->getMenuData($lang);
        $lastSyncAt   = $this->service->getLastSyncAt();
        $seo          = $this->service->buildSeoMeta($lang, $explicitLang);

        $pageTitle   = match ($lang) { 'en' => 'Online menu', 'vi' => 'Thực đơn online', 'ko' => '온라인 메뉴', default => 'Online меню' };
        $menuLabel   = match ($lang) { 'en' => 'MENU', 'vi' => 'THỰC ĐƠN', 'ko' => '메뉴', default => 'МЕНЮ' };
        $lastMenuSyncAt = $lastSyncAt;

        ob_start();
        require __DIR__ . '/../Views/menu_public.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
