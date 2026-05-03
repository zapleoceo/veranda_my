<?php

$supportedLangs = ['ru', 'en', 'vi', 'ko'];

$i18n = [
    'ru' => [
        'subtitle' => 'Быстрые ссылки',
        'hours' => [
            'title' => 'Время работы',
            'line1' => 'Пн–Чт 10:00–22:00',
            'line2' => 'Пт–Вс 10:00–23:00',
        ],
        'sections' => [
            'actions' => 'Сервис',
            'social' => 'Контакты и соцсети',
        ],
        'items' => [
            'tg_group' => ['title' => 'Telegram группа', 'subtitle' => '@gamezone_vietnam'],
            'tg_veranda' => ['title' => 'Мы в Telegram', 'subtitle' => '@veranda.my'],
            'whatsapp' => ['title' => 'Мы в WhatsApp', 'subtitle' => '+84 396 314 266'],
            'menu' => ['title' => 'Онлайн меню', 'subtitle' => 'Сайт'],
            'reserve' => ['title' => 'Бронирование столика', 'subtitle' => 'Сайт'],
            'director' => ['title' => 'Связаться с директором', 'subtitle' => '@zapleo_ceo'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'map' => ['title' => 'Google Карта', 'subtitle' => 'Как добраться'],
        ],
    ],
    'en' => [
        'subtitle' => 'Quick links',
        'hours' => [
            'title' => 'Working hours',
            'line1' => 'Mon–Thu 10:00–22:00',
            'line2' => 'Fri–Sun 10:00–23:00',
        ],
        'sections' => [
            'actions' => 'Service',
            'social' => 'Contacts & social',
        ],
        'items' => [
            'tg_group' => ['title' => 'Telegram group', 'subtitle' => '@gamezone_vietnam'],
            'tg_veranda' => ['title' => 'We are on Telegram', 'subtitle' => '@veranda.my'],
            'whatsapp' => ['title' => 'We are on WhatsApp', 'subtitle' => '+84 396 314 266'],
            'menu' => ['title' => 'Online menu', 'subtitle' => 'Website'],
            'reserve' => ['title' => 'Table reservation', 'subtitle' => 'Website'],
            'director' => ['title' => 'Contact director', 'subtitle' => '@zapleo_ceo'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'map' => ['title' => 'Google Maps', 'subtitle' => 'Directions'],
        ],
    ],
    'vi' => [
        'subtitle' => 'Liên kết nhanh',
        'hours' => [
            'title' => 'Giờ mở cửa',
            'line1' => 'Th 2–Th 5 10:00–22:00',
            'line2' => 'Th 6–CN 10:00–23:00',
        ],
        'sections' => [
            'actions' => 'Dịch vụ',
            'social' => 'Liên hệ & mạng xã hội',
        ],
        'items' => [
            'tg_group' => ['title' => 'Nhóm Telegram', 'subtitle' => '@gamezone_vietnam'],
            'tg_veranda' => ['title' => 'Veranda trên Telegram', 'subtitle' => '@veranda.my'],
            'whatsapp' => ['title' => 'Veranda trên WhatsApp', 'subtitle' => '+84 396 314 266'],
            'menu' => ['title' => 'Online menu', 'subtitle' => 'Website'],
            'reserve' => ['title' => 'Đặt bàn', 'subtitle' => 'Website'],
            'director' => ['title' => 'Liên hệ giám đốc', 'subtitle' => '@zapleo_ceo'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'map' => ['title' => 'Google Maps', 'subtitle' => 'Chỉ đường'],
        ],
    ],
    'ko' => [
        'subtitle' => '빠른 링크',
        'hours' => [
            'title' => '영업시간',
            'line1' => '월–목 10:00–22:00',
            'line2' => '금–일 10:00–23:00',
        ],
        'sections' => [
            'actions' => '서비스',
            'social' => '연락처 & SNS',
        ],
        'items' => [
            'tg_group' => ['title' => '텔레그램 그룹', 'subtitle' => '@gamezone_vietnam'],
            'tg_veranda' => ['title' => '텔레그램 Veranda', 'subtitle' => '@veranda.my'],
            'whatsapp' => ['title' => 'WhatsApp', 'subtitle' => '+84 396 314 266'],
            'menu' => ['title' => '온라인 메뉴', 'subtitle' => '웹사이트'],
            'reserve' => ['title' => '테이블 예약', 'subtitle' => '웹사이트'],
            'director' => ['title' => '대표에게 문의', 'subtitle' => '@zapleo_ceo'],
            'facebook' => ['title' => 'Facebook', 'subtitle' => 'Veranda'],
            'map' => ['title' => '구글 지도', 'subtitle' => '길찾기'],
        ],
    ],
];

$sections = [
    'actions' => ['menu', 'reserve', 'map'],
    'social' => ['tg_group', 'tg_veranda', 'whatsapp', 'director', 'facebook'],
];

$linkDefs = [
    'tg_group' => ['href' => 'https://t.me/gamezone_vietnam', 'icon' => 'telegram'],
    'tg_veranda' => ['href' => 'https://t.me/veranda.my', 'icon' => 'telegram'],
    'whatsapp' => ['href' => 'https://wa.me/84396314266', 'icon' => 'whatsapp'],
    'menu' => ['href' => '/links/menu-beta.php', 'icon' => 'menu'],
    'reserve' => ['href' => '/tr3', 'icon' => 'reserve'],
    'director' => ['href' => 'https://t.me/zapleo_ceo', 'icon' => 'director'],
    'facebook' => ['href' => 'https://www.facebook.com/share/1LSPvAR8X7/', 'icon' => 'facebook'],
    'map' => ['href' => 'https://maps.app.goo.gl/wM9MMAGJjxUppDgR9', 'icon' => 'map'],
];
