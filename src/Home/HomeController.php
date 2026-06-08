<?php

declare(strict_types=1);

namespace App\Home;

use App\Home\Content\Contacts;
use App\Home\Content\PageContent;
use App\Home\Content\Seo;
use App\Home\Content\VenueDirectory;
use App\Home\Content\WeeklyProgram;
use App\Home\View\View;
use App\Infrastructure\Config;

/**
 * Оркестрация публичной главной /home.
 *
 * Тонкий контроллер: собирает доменные объекты (контакты, афиша, «миры»,
 * копирайт, SEO), отдаёт их во view-слой и возвращает готовый HTML строкой.
 * Никакой бизнес-логики и разметки здесь нет.
 */
final class HomeController
{
    public function render(): string
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $base = Config::baseUrl();

        $contacts = new Contacts();
        $content = new PageContent();
        $program = new WeeklyProgram((int) date('w')); // 0=Вс..6=Сб (время заведения)
        $directory = new VenueDirectory($contacts);

        $seo = new Seo(
            canonical: $base . '/home',
            ogImage: $base . '/assets/img/home/hero-terrace-1400.webp',
            phone: $contacts->phone,
            menuUrl: $base . '/links/menu',
            reserveUrl: $base . '/tr3/',
        );

        $view = new View(__DIR__ . '/../Views/home');

        return $view->render('page', [
            'seo' => $seo,
            'content' => $content,
            'contacts' => $contacts,
            'program' => $program,
            'worlds' => $directory->worlds(),
        ]);
    }
}
