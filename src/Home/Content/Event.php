<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Одно событие афиши (привязано к дню недели в WeeklyProgram).
 * image — базовое имя фото для фона featured-карточки; url — ссылка кнопки
 * (по клику на день меняются и текст, и фон, и ссылка).
 */
final class Event
{
    public function __construct(
        public readonly string $title,
        public readonly string $time,
        public readonly string $note,
        public readonly string $image,
        public readonly string $url,
        public readonly string $ctaLabel = '', // подпись кнопки; '' = глобальная «Забронировать»
    ) {
    }
}
