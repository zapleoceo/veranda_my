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
            'primary' => '',
            'contacts' => '',
            'visit' => '',
        ],
        'items' => [
            'menu' => ['title' => 'Открыть меню', 'subtitle' => ''],
            'reserve' => ['title' => 'Забронировать столик', 'subtitle' => ''],
            'whatsapp' => ['title' => 'Связаться с менеджером', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Связаться с менеджером', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Написать менеджеру', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Мы в Facebook', 'subtitle' => ''],
            'director' => ['title' => 'Написать директору', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => 'Как добраться', 'subtitle' => ''],
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
            'primary' => '',
            'contacts' => '',
            'visit' => '',
        ],
        'items' => [
            'menu' => ['title' => 'Open menu', 'subtitle' => ''],
            'reserve' => ['title' => 'Reserve a table', 'subtitle' => ''],
            'whatsapp' => ['title' => 'Contact manager', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Contact manager', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Message manager', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'We are on Facebook', 'subtitle' => ''],
            'director' => ['title' => 'Message director', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => 'Get directions', 'subtitle' => ''],
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
            'primary' => '',
            'contacts' => '',
            'visit' => '',
        ],
        'items' => [
            'menu' => ['title' => 'Mở thực đơn', 'subtitle' => ''],
            'reserve' => ['title' => 'Đặt bàn', 'subtitle' => ''],
            'whatsapp' => ['title' => 'Liên hệ quản lý', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => 'Liên hệ quản lý', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => 'Nhắn quản lý', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => ''],
            'director' => ['title' => 'Nhắn giám đốc', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => 'Chỉ đường', 'subtitle' => ''],
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
            'primary' => '',
            'contacts' => '',
            'visit' => '',
        ],
        'items' => [
            'menu' => ['title' => '메뉴 열기', 'subtitle' => ''],
            'reserve' => ['title' => '테이블 예약', 'subtitle' => ''],
            'whatsapp' => ['title' => '매니저 문의', 'subtitle' => '+84 396 314 266'],
            'tg_veranda' => ['title' => '매니저 문의', 'subtitle' => '@veranda.my'],
            'tg_group' => ['title' => '매니저에게 메시지', 'subtitle' => '@gamezone_vietnam'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => ''],
            'director' => ['title' => '대표에게 메시지', 'subtitle' => '@zapleo_ceo'],
            'map' => ['title' => '오시는 길', 'subtitle' => ''],
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
