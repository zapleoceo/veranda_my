<?php

declare(strict_types=1);

namespace App\Home\View;

/**
 * Минимальный рендерер PHP-шаблонов с буферизацией вывода.
 *
 * Шаблоны лежат в одной директории; внутри шаблона доступны:
 *   $view  — этот рендерер (для композиции: $view->partial('partials/hero', [...]))
 *   ...    — переданные в render()/partial() данные (через extract)
 *
 * Никакого движка-зависимости (как Twig) — проект и так на нативном PHP-выводе
 * (ср. BanyaController: ob_start + require), остаёмся в той же парадигме.
 */
final class View
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->dir . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View template not found: {$template}");
        }

        $view = $this; // доступен внутри шаблона для вложенной композиции
        extract($data, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /**
     * Псевдоним render() для вызова из шаблонов — читается понятнее.
     *
     * @param array<string,mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }
}
