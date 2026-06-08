<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Единый источник правды по контактам и ссылкам комплекса.
 *
 * Раньше телефоны и URL были разбросаны по home/index.php и links_data.php
 * и рассинхронизированы (на /home стоял один номер ресторана, на /links — другой;
 * maps-ссылка на /home была вообще битой плейсхолдер-заглушкой). Теперь всё в одном
 * месте — поменять номер/ссылку = поменять одну строку.
 */
final class Contacts
{
    public function __construct(
        // Ресторан Veranda (канонический публичный номер — см. /links, Instagram)
        public readonly string $phone = '+84396314266',
        public readonly string $phoneDisplay = '+84 396 314 266',
        // Баня «Сила Духа»
        public readonly string $banyaPhone = '+84395959140',
        public readonly string $banyaPhoneDisplay = '+84 39 5959 140',
        // Каналы и соцсети
        public readonly string $telegram = 'https://t.me/gamezone_vietnam',
        public readonly string $telegramVeranda = 'https://t.me/Veranda_my',
        public readonly string $instagram = 'https://www.instagram.com/veranda.my/',
        public readonly string $kidsInstagram = 'https://www.instagram.com/prazdniki_hnatrang/',
        public readonly string $director = 'https://t.me/zapleo_ceo',
        public readonly string $facebook = 'https://www.facebook.com/vngamezone/',
        // Локация — рабочая короткая ссылка (взята из /links, не плейсхолдер)
        public readonly string $maps = 'https://maps.app.goo.gl/wM9MMAGJjxUppDgR9',
        public readonly string $coords = '12.30° N · 109.21° E',
        // Конверсии
        public readonly string $reserve = '/tr3/',
        public readonly string $menu = '/links/menu',
        // Партнёрские проекты на той же локации
        public readonly string $banyaSite = 'https://sila-duha.com/',
        public readonly string $gamezoneSite = 'https://ru.vn-gamezone.com/',
    ) {
    }

    /** WhatsApp-ссылка собирается из канонического номера ресторана. */
    public function whatsApp(): string
    {
        return 'https://wa.me/' . preg_replace('/\D+/', '', $this->phone);
    }

    public function tel(): string
    {
        return 'tel:' . $this->phone;
    }

    public function banyaTel(): string
    {
        return 'tel:' . $this->banyaPhone;
    }
}
