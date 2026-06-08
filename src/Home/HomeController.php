<?php

declare(strict_types=1);

namespace App\Home;

use App\Home\Content\Contacts;
use App\Home\Content\PageContent;
use App\Home\Content\Seo;
use App\Home\Content\VenueDirectory;
use App\Home\Content\WeeklyProgram;
use App\Home\I18n\Lang;
use App\Home\View\View;
use App\Infrastructure\Config;

/**
 * Оркестрация публичной главной /home (RU/EN/VI).
 *
 * Тонкий контроллер: по локали собирает словарь Lang + доменные объекты
 * (контакты, афиша, «миры», копирайт, SEO) и отдаёт готовый HTML строкой.
 */
final class HomeController
{
    public function render(string $locale): string
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $base = Config::baseUrl();
        $lang = new Lang($locale);

        $contacts = new Contacts();
        $content = new PageContent($lang);
        $program = new WeeklyProgram($lang, (int) date('w'), $contacts->reserve); // 0=Вс..6=Сб
        $directory = new VenueDirectory($lang, $contacts);
        $seo = new Seo($lang, $locale, $base, $contacts);

        $view = new View(__DIR__ . '/../Views/home');

        return $view->render('page', [
            'lang' => $lang,
            'locale' => $locale,
            'seo' => $seo,
            'content' => $content,
            'contacts' => $contacts,
            'program' => $program,
            'worlds' => $directory->worlds(),
        ]);
    }
}
