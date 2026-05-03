<?php

$supportedLangs = ['ru', 'en', 'vi', 'ko'];

$i18n = [
    'ru' => [
        'subtitle' => '',
        'hours' => [
            'title' => 'Время работы',
            'line1' => 'Пн–Чт 10:00–22:00',
            'line2' => 'Пт–Вс 10:00–23:00',
        ],
        'sections' => [
            'primary' => 'Основное',
            'contacts' => 'Связь и соцсети',
            'visit' => 'Как добраться',
        ],
        'items' => [
            'menu' => ['title' => 'Меню', 'subtitle' => 'Открыть'],
            'reserve' => ['title' => 'Бронирование', 'subtitle' => 'Забронировать стол'],
            'whatsapp' => ['title' => 'WhatsApp', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Telegram', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Telegram группа', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'director' => ['title' => 'Директор', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => 'Карта', 'subtitle' => 'Google Maps'],
        ],
    ],
    'en' => [
        'subtitle' => '',
        'hours' => [
            'title' => 'Working hours',
            'line1' => 'Mon–Thu 10:00–22:00',
            'line2' => 'Fri–Sun 10:00–23:00',
        ],
        'sections' => [
            'primary' => 'Primary',
            'contacts' => 'Contacts & social',
            'visit' => 'Directions',
        ],
        'items' => [
            'menu' => ['title' => 'Menu', 'subtitle' => 'Open'],
            'reserve' => ['title' => 'Reservation', 'subtitle' => 'Reserve a table'],
            'whatsapp' => ['title' => 'WhatsApp', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Telegram', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Telegram group', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'director' => ['title' => 'Director', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => 'Map', 'subtitle' => 'Google Maps'],
        ],
    ],
    'vi' => [
        'subtitle' => '',
        'hours' => [
            'title' => 'Giờ mở cửa',
            'line1' => 'Th 2–Th 5 10:00–22:00',
            'line2' => 'Th 6–CN 10:00–23:00',
        ],
        'sections' => [
            'primary' => 'Chính',
            'contacts' => 'Liên hệ & mạng xã hội',
            'visit' => 'Chỉ đường',
        ],
        'items' => [
            'menu' => ['title' => 'Thực đơn', 'subtitle' => 'Mở'],
            'reserve' => ['title' => 'Đặt bàn', 'subtitle' => 'Đặt bàn ngay'],
            'whatsapp' => ['title' => 'WhatsApp', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Telegram', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Nhóm Telegram', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'director' => ['title' => 'Giám đốc', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => 'Bản đồ', 'subtitle' => 'Google Maps'],
        ],
    ],
    'ko' => [
        'subtitle' => '',
        'hours' => [
            'title' => '영업시간',
            'line1' => '월–목 10:00–22:00',
            'line2' => '금–일 10:00–23:00',
        ],
        'sections' => [
            'primary' => '주요',
            'contacts' => '연락처 & SNS',
            'visit' => '오시는 길',
        ],
        'items' => [
            'menu' => ['title' => '메뉴', 'subtitle' => '열기'],
            'reserve' => ['title' => '예약', 'subtitle' => '테이블 예약'],
            'whatsapp' => ['title' => 'WhatsApp', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Telegram', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Telegram 그룹', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'director' => ['title' => '대표', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => '지도', 'subtitle' => 'Google Maps'],
        ],
    ],
];

$sections = [
    'primary' => ['menu', 'reserve'],
    'contacts' => ['whatsapp', 'tg_veranda', 'tg_group', 'facebook', 'director'],
    'visit' => ['map'],
];

$linkDefs = [
    'tg_group' => ['href' => 'https://t.me/gamezone_vietnam', 'icon' => 'telegram'],
    'tg_veranda' => ['href' => 'https://t.me/veranda.my', 'icon' => 'telegram'],
    'whatsapp' => ['href' => 'https://wa.me/84396314266', 'icon' => 'whatsapp'],
    'menu' => ['href' => '/links/menu.php', 'icon' => 'menu'],
    'reserve' => ['href' => '/tr3', 'icon' => 'reserve'],
    'director' => ['href' => 'https://t.me/zapleo_ceo', 'icon' => 'director'],
    'facebook' => ['href' => 'https://www.facebook.com/share/1LSPvAR8X7/', 'icon' => 'facebook'],
    'map' => ['href' => 'https://maps.app.goo.gl/wM9MMAGJjxUppDgR9', 'icon' => 'map'],
];
