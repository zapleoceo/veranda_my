<?php

declare(strict_types=1);

namespace App\Home\View;

/**
 * Реестр inline-SVG иконок. Утилитарные — тонкие штриховые линии (премиальнее
 * «жирных» дефолтов); бренд-глифы соцсетей оставлены заливкой для узнаваемости.
 * Inline (а не спрайт/шрифт) — чтобы наследовали currentColor без лишних запросов.
 */
final class Icons
{
    private const STROKE = 'fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';

    private const MAP = [
        // ── Утилитарные (тонкая линия) ──
        'arrow'    => '<svg viewBox="0 0 24 24" %s aria-hidden="true"><path d="M5 12h13M12 5l7 7-7 7"/></svg>',
        'arrow-ne' => '<svg viewBox="0 0 24 24" %s aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>',
        'chevron-down' => '<svg viewBox="0 0 24 24" %s aria-hidden="true"><path d="m5 9 7 7 7-7"/></svg>',
        'pin'      => '<svg viewBox="0 0 24 24" %s aria-hidden="true"><path d="M12 21s7-6.6 7-12a7 7 0 1 0-14 0c0 5.4 7 12 7 12Z"/><circle cx="12" cy="9" r="2.6"/></svg>',
        'clock'    => '<svg viewBox="0 0 24 24" %s aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 1.8"/></svg>',
        'phone'    => '<svg viewBox="0 0 24 24" %s aria-hidden="true"><path d="M5 4h3.2l1.6 4.4-2 1.3a12 12 0 0 0 5.5 5.5l1.3-2 4.4 1.6V19a2 2 0 0 1-2.2 2A16 16 0 0 1 3 6.2 2 2 0 0 1 5 4Z"/></svg>',

        // ── Бренд-глифы (заливка) ──
        'wa' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.1 3.9A11.8 11.8 0 0 0 12 0 12 12 0 0 0 1.6 17.6L0 24l6.6-1.6A12 12 0 0 0 24 12a11.8 11.8 0 0 0-3.9-8.1Zm-2.3 10.6c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2-.8 1-.9 1.2-.3.2-.6.1a8.2 8.2 0 0 1-2.4-1.5 9 9 0 0 1-1.7-2.1c-.2-.3 0-.5.1-.6l.5-.6.3-.5a.6.6 0 0 0 0-.5c0-.2-.7-1.7-1-2.3s-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.8 3.8 0 0 0-1.2 2.8 6.5 6.5 0 0 0 1.4 3.4 14.8 14.8 0 0 0 5.7 5 6.8 6.8 0 0 0 3.3.9 3.2 3.2 0 0 0 2.1-.9 2.6 2.6 0 0 0 .6-1.9c0-.2-.2-.3-.5-.4z"/></svg>',
        'tg' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.6 15.6 9.2 19c.6 0 .9-.2 1.3-.6l3.1-3 6.4 4.6c1.2.7 2 .3 2.3-1.1l4.1-19.1c.4-1.7-.7-2.4-1.9-1.9L1.2 9.2c-1.6.6-1.6 1.5-.3 1.9l6 1.9L20.2 4c.7-.4 1.3-.2.8.3z"/></svg>',
        'ig' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7.8 2h8.4A5.8 5.8 0 0 1 22 7.8v8.4A5.8 5.8 0 0 1 16.2 22H7.8A5.8 5.8 0 0 1 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2zm0 2A3.8 3.8 0 0 0 4 7.8v8.4A3.8 3.8 0 0 0 7.8 20h8.4A3.8 3.8 0 0 0 20 16.2V7.8A3.8 3.8 0 0 0 16.2 4zm4.2 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5zM17.6 6.6a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/></svg>',
    ];

    public static function get(string $name): string
    {
        $svg = self::MAP[$name] ?? '';

        return str_contains($svg, '%s') ? sprintf($svg, self::STROKE) : $svg;
    }
}
