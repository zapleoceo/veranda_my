<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Один «мир» комплекса (Ресторан / Баня / GameZone) для секции «Три мира».
 *
 * titleHtml — авторская разметка с <em> (доверенный контент из кода, не ввод
 * пользователя), рендерится как есть. Остальные строки экранируются в шаблоне.
 *
 * Вторичная ссылка опциональна: у проектов-партнёров на той же локации
 * (баня, GameZone) — ровно ОДНА ссылка (требование заказчика). Вторая кнопка
 * есть только у самого ресторана (меню + бронь — это его собственные конверсии,
 * а не «ссылка на проект»).
 */
final class Venue
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly string $index,
        public readonly string $label,
        public readonly string $titleHtml,
        public readonly string $lead,
        public readonly array $tags,
        /** @var string[] базовые имена файлов (1 шт. = фото; >1 = слайдер-галерея) */
        public readonly array $images,
        public readonly string $imageAlt,
        public readonly string $linkLabel,
        public readonly string $linkUrl,
        public readonly bool $external = false,
        public readonly bool $reverse = false,
        public readonly ?string $secondaryLabel = null,
        public readonly ?string $secondaryUrl = null,
        /** базовое имя .webm в /assets/video/ — на десктопе показывается вместо фото */
        public readonly ?string $video = null,
    ) {
    }

    public function hasSecondary(): bool
    {
        return $this->secondaryLabel !== null && $this->secondaryUrl !== null;
    }

    public function isGallery(): bool
    {
        return count($this->images) > 1;
    }

    public function hasVideo(): bool
    {
        return $this->video !== null;
    }
}
