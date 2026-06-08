<?php

declare(strict_types=1);

namespace App\Home\I18n;

/**
 * Центральный словарь переводов главной страницы (en/ru/vi).
 * Один файл — удобно выверять, особенно VI. Ключи плоские, точечные.
 * t() — строка, list() — массив (теги, пункты бегущей строки, имена дней).
 * Фолбэк значения — английский (en).
 */
final class Lang
{
    public function __construct(public readonly string $locale)
    {
    }

    public function t(string $key): string
    {
        $v = self::DICT[$this->locale][$key] ?? self::DICT['en'][$key] ?? $key;

        return is_string($v) ? $v : $key;
    }

    /** @return string[] */
    public function list(string $key): array
    {
        $v = self::DICT[$this->locale][$key] ?? self::DICT['en'][$key] ?? [];

        return is_array($v) ? $v : [];
    }

    private const DICT = [
        // ─────────────────────────── ENGLISH (default) ───────────────────────────
        'en' => [
            'seo.title' => 'Veranda — restaurant in the Nha Trang mountains, banya & games',
            'seo.description' => 'Veranda Restaurant & Bar — a hillside restaurant 10 minutes from the centre of Nha Trang. Home cooking, a wood-fired banya, games for the whole family, live music and open-air cinema. Free entry to events.',
            'seo.ogTitle' => 'Veranda — a whole evening of experiences in the Nha Trang mountains',
            'seo.ogDescription' => 'Restaurant, wood-fired banya, games for the whole family, live music and open-air cinema. 10 minutes from the centre.',

            'nav.complex' => 'The complex',
            'nav.schedule' => 'Schedule',
            'nav.location' => 'Location',
            'nav.table' => 'Table',
            'nav.switcher' => 'Language',

            'hero.eyebrow' => 'Nha Trang · Vietnam',
            'hero.title' => 'A whole evening<br><em>in the mountains</em><br>of Nha Trang',
            'hero.lead' => 'A restaurant with home cooking, a wood-fired banya, games for the whole family, live music and open-air cinema — all in one place, 10 minutes from the city centre.',
            'hero.cta1' => 'Book a table',
            'hero.cta2' => "What's inside",
            'hero.side' => 'Hon Chong hill',

            'marquee' => ['Restaurant', 'Wood-fired banya', 'Archery Tag · Laser tag', 'Kids club', 'Live Music', 'Cinema under the stars'],

            'tonight.eyebrow' => "What's on",
            'tonight.title' => "What's on <em>tonight</em>",
            'tonight.lead' => 'Every day of the week has its own mood. Entry to events is free — just book a table ahead.',
            'tonight.badgeToday' => 'Tonight',
            'tonight.badgeWeek' => 'This week',
            'tonight.book' => 'Book',
            'tonight.films' => 'Film schedule',
            'tonight.free' => 'Free entry to all events',

            'days.full' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'days.short' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],

            'ev.d1.title' => 'Board games', 'ev.d1.time' => 'all evening', 'ev.d1.note' => 'Bunker, Secret Hitler, Mafia, Uno — free. Bring your own crew.',
            'ev.d2.title' => 'Cinema under the stars', 'ev.d2.time' => '18:00 · 20:00', 'ev.d2.note' => "18:00 — kids' screening · 20:00 — adults' screening",
            'ev.d3.title' => 'Live Music', 'ev.d3.time' => '19:00', 'ev.d3.note' => 'English-language hit covers',
            'ev.d4.title' => 'Cinema under the stars', 'ev.d4.time' => '18:00 · 20:00', 'ev.d4.note' => "18:00 — kids' screening · 20:00 — adults' screening",
            'ev.d5.title' => 'Live Music', 'ev.d5.time' => '19:00', 'ev.d5.note' => 'Bands rotate: The Pennywort, Ulik, Ryadnovy, BiBi Duo',
            'ev.d6.title' => 'Live music', 'ev.d6.time' => '19:00', 'ev.d6.note' => 'Bands rotate: The Pennywort, Ulik, Ryadnovy, BiBi Duo',
            'ev.d0.title' => 'Live music evening', 'ev.d0.time' => '19:00', 'ev.d0.note' => 'Bands rotate: The Pennywort, Ulik, Ryadnovy, BiBi Duo',

            'worlds.eyebrow' => 'One complex',
            'worlds.title' => 'Four worlds on one <em>hillside</em>',
            'worlds.lead' => 'Breakfast in the shade of mountain trees → play during the day while the kids are busy at the club → steam in the banya at sunset → dinner with live music or open-air cinema in the evening.',

            'world.restaurant.label' => 'Restaurant',
            'world.restaurant.title' => 'Home cooking <em>with a European</em> touch',
            'world.restaurant.lead' => 'Breakfasts in the shade of the trees, borscht and solyanka, signature burgers, pâtés and salads, cocktails and fresh draught beer. We cook what you miss from home — and what you tasted on your travels.',
            'world.restaurant.tags' => ['Breakfasts', 'Home cooking', 'European', 'Cocktails', 'Draught beer'],
            'world.restaurant.alt' => "Dishes from Veranda's kitchen",
            'world.restaurant.link' => 'View menu',
            'world.restaurant.book' => 'Book a table',

            'world.banya.label' => 'Sila Dukha Banya',
            'world.banya.title' => 'A real Russian banya <em>on wood fire</em>',
            'world.banya.lead' => 'A hot wood-fired steam room, a cold plunge, experienced banya masters, venik whisks, honey tea and kvass. Afterwards — dinner on the veranda without leaving your seat.',
            'world.banya.tags' => ['Wood-fired steam', 'Cold plunge', 'Banya masters', 'Honey tea'],
            'world.banya.alt' => 'Russian wood-fired banya — steam room with a venik',
            'world.banya.link' => 'Visit the banya site',

            'world.gamezone.label' => 'GameZone',
            'world.gamezone.title' => 'Laser tag and <em>Archery Tag</em>',
            'world.gamezone.lead' => 'Archery Tag — combat archery like paintball but safe: soft-tipped bows and a briefing before the game. Plus laser tag and quests. Team games for friends and corporate events — the only ones in Nha Trang.',
            'world.gamezone.tags' => ['Archery Tag', 'Laser tag', 'Quests', 'BBQ gazebos', 'Corporate'],
            'world.gamezone.alt' => 'Archery Tag — combat archery at GameZone',
            'world.gamezone.link' => 'Visit the GameZone site',

            'world.kids.label' => "Kids' zone",
            'world.kids.title' => "Play, crafts and <em>kids' parties</em>",
            'world.kids.lead' => 'A kids\' club with an animator: games and workshops, painting, light-show discos and fairy-tale therapy. While the kids are busy, parents relax in the restaurant or banya. Birthdays and parties done turnkey: a custom script, a bubble show and quests.',
            'world.kids.tags' => ['Animator', 'Workshops', 'Birthdays', 'Story therapy', 'Disco'],
            'world.kids.alt' => "Kids' play space — toys, balloons, photo zone",
            'world.kids.link' => 'View on Instagram',

            'bento.eyebrow' => 'Atmosphere',
            'bento.title' => 'A warm mountain <em>evening</em>',
            'bento.quote' => '“You climb up from noisy Nha Trang along the winding road — and find yourself in another world: wind, views, quiet, warmth.”',
            'bento.cite' => '— guest, January 2026',
            'gallery.alt' => 'Atmosphere at Veranda — mountain views, lanterns, garden',

            'gazebos.eyebrow' => 'Private',
            'gazebos.title' => 'Gazebos for your group',
            'gazebos.lead' => 'Cosy little gazebos with fabric veils and a low table. Perfect for a family evening, a birthday or simply a long dinner under the stars. Can be booked ahead.',
            'gazebos.button' => 'Book a gazebo',
            'gazebos.alt' => 'Gazebo with fabric curtains inside',

            'location.eyebrow' => 'Getting here',
            'location.title' => '10 minutes <em>from the centre</em>',
            'location.lead' => "Veranda sits on a hillside: a short winding road and you're in a garden overlooking Nha Trang.",
            'location.directions' => '~10 minutes by taxi or bike from the city centre. Parking on site.',
            'location.hours' => 'Mon–Thu 10:00–22:00 · Fri–Sun 10:00–23:00',
            'location.route' => 'Get directions',
            'location.mapAlt' => 'Map: where Veranda is in Nha Trang',

            'footer.tagline' => 'A restaurant, banya and games on one hillside in the mountains of Nha Trang.',
            'footer.colVeranda' => 'Veranda',
            'footer.colPartners' => 'Partners',
            'footer.colContacts' => 'Contacts',
            'footer.book' => 'Book a table',
            'footer.menu' => 'Menu',
            'footer.schedule' => 'Weekly schedule',
            'footer.alllinks' => 'All links',
            'footer.director' => 'Message the manager',
            'footer.address' => 'Nha Trang, Vietnam',
            'footer.partnerBanya' => 'Sila Dukha Banya',
        ],

        // ─────────────────────────── РУССКИЙ ───────────────────────────
        'ru' => [
            'seo.title' => 'Veranda — ресторан в горах Нячанга, баня и игры',
            'seo.description' => 'Veranda Restaurant & Bar — ресторан на склоне в 10 минутах от центра Нячанга. Домашняя кухня, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами. Вход на события свободный.',
            'seo.ogTitle' => 'Veranda — целый вечер впечатлений в горах Нячанга',
            'seo.ogDescription' => 'Ресторан, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами. 10 минут от центра.',

            'nav.complex' => 'Комплекс',
            'nav.schedule' => 'Афиша',
            'nav.location' => 'Локация',
            'nav.table' => 'Столик',
            'nav.switcher' => 'Язык',

            'hero.eyebrow' => 'Nha Trang · Vietnam',
            'hero.title' => 'Целый вечер<br><em>в горах</em><br>Нячанга',
            'hero.lead' => 'Ресторан с домашней кухней, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами — на одной локации, в 10 минутах от центра города.',
            'hero.cta1' => 'Забронировать столик',
            'hero.cta2' => 'Что внутри',
            'hero.side' => 'гора Хон Чонг',

            'marquee' => ['Ресторан', 'Баня на дровах', 'Archery Tag · Лазертаг', 'Детский клуб', 'Live Music', 'Кино под звёздами'],

            'tonight.eyebrow' => 'Живая афиша',
            'tonight.title' => 'Что сегодня <em>вечером</em>',
            'tonight.lead' => 'Каждый день недели — своё настроение. Вход на события свободный, столик стоит забронировать заранее.',
            'tonight.badgeToday' => 'Сегодня вечером',
            'tonight.badgeWeek' => 'В афише',
            'tonight.book' => 'Забронировать',
            'tonight.films' => 'Афиша фильмов',
            'tonight.free' => 'Вход на все события — свободный',

            'days.full' => ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'],
            'days.short' => ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],

            'ev.d1.title' => 'Настольные игры', 'ev.d1.time' => 'весь вечер', 'ev.d1.note' => 'Бункер, Тайный Гитлер, Мафия, Uno — бесплатно. Приходите своей компанией.',
            'ev.d2.title' => 'Кино под звёздами', 'ev.d2.time' => '18:00 · 20:00', 'ev.d2.note' => '18:00 — детский сеанс · 20:00 — взрослый сеанс',
            'ev.d3.title' => 'Live Music', 'ev.d3.time' => '19:00', 'ev.d3.note' => 'Каверы англоязычных хитов',
            'ev.d4.title' => 'Кино под звёздами', 'ev.d4.time' => '18:00 · 20:00', 'ev.d4.note' => '18:00 — детский сеанс · 20:00 — взрослый сеанс',
            'ev.d5.title' => 'Live Music', 'ev.d5.time' => '19:00', 'ev.d5.note' => 'Группы чередуются: The Pennywort, Улик, Рядновы, BiBi Duo',
            'ev.d6.title' => 'Живая музыка', 'ev.d6.time' => '19:00', 'ev.d6.note' => 'Группы чередуются: The Pennywort, Улик, Рядновы, BiBi Duo',
            'ev.d0.title' => 'Вечер живой музыки', 'ev.d0.time' => '19:00', 'ev.d0.note' => 'Группы чередуются: The Pennywort, Улик, Рядновы, BiBi Duo',

            'worlds.eyebrow' => 'Один комплекс',
            'worlds.title' => 'Четыре мира на одной <em>поляне</em>',
            'worlds.lead' => 'Позавтракали в тени горных деревьев → днём поиграли, дети заняты в детском клубе → попарились в бане на закате → вечером ужин с живой музыкой или кино под звёздами.',

            'world.restaurant.label' => 'Ресторан',
            'world.restaurant.title' => 'Домашняя кухня <em>с европейским</em> акцентом',
            'world.restaurant.lead' => 'Завтраки в тени деревьев, борщ и солянка, авторские бургеры, паштеты и салаты, коктейли и свежее разливное пиво. Готовим то, по чему скучаешь дома, — и то, что попробовал в путешествии.',
            'world.restaurant.tags' => ['Завтраки', 'Домашняя кухня', 'Европейское', 'Коктейли', 'Свежее пиво'],
            'world.restaurant.alt' => 'Блюда кухни Veranda',
            'world.restaurant.link' => 'Открыть меню',
            'world.restaurant.book' => 'Забронировать',

            'world.banya.label' => 'Баня «Сила Духа»',
            'world.banya.title' => 'Настоящая русская баня <em>на дровах</em>',
            'world.banya.lead' => 'Горячая парная на дровах, холодная купель, опытные пармастера, веники, чай с мёдом и квас. После — ужин на веранде, не вставая со стула.',
            'world.banya.tags' => ['Парная на дровах', 'Холодная купель', 'Пармастера', 'Чай с мёдом'],
            'world.banya.alt' => 'Русская баня на дровах — парная с веником',
            'world.banya.link' => 'Перейти на сайт бани',

            'world.gamezone.label' => 'GameZone',
            'world.gamezone.title' => 'Лазертаг и <em>Archery Tag</em>',
            'world.gamezone.lead' => 'Archery Tag — лучный бой, как пейнтбол, но безопасный: луки с мягкими наконечниками и инструктаж перед игрой. Плюс лазертаг и квесты. Командные игры для компании и корпоратива — единственные такие в Нячанге.',
            'world.gamezone.tags' => ['Archery Tag', 'Лазертаг', 'Квесты', 'BBQ-беседки', 'Корпоративы'],
            'world.gamezone.alt' => 'Archery Tag — лучный бой в GameZone',
            'world.gamezone.link' => 'Перейти на сайт GameZone',

            'world.kids.label' => 'Детская локация',
            'world.kids.title' => 'Игры, творчество и <em>детские праздники</em>',
            'world.kids.lead' => 'Детский клуб с аниматором: игры и мастер-классы, рисование, дискотеки со светомузыкой и сказкотерапия. Пока дети заняты делом — родители отдыхают в ресторане или бане. Дни рождения и праздники под ключ: свой сценарий, шоу мыльных пузырей и квесты.',
            'world.kids.tags' => ['Аниматор', 'Мастер-классы', 'Дни рождения', 'Сказкотерапия', 'Дискотека'],
            'world.kids.alt' => 'Детское игровое пространство — игрушки, шары, фотозона',
            'world.kids.link' => 'Смотреть в Instagram',

            'bento.eyebrow' => 'Атмосфера',
            'bento.title' => 'Тёплый горный <em>вечер</em>',
            'bento.quote' => '«Поднимаешься от шумного Нячанга по серпантину — и оказываешься в другом мире: ветер, виды, тишина, тепло.»',
            'bento.cite' => '— гость, январь 2026',
            'gallery.alt' => 'Атмосфера Veranda — горные виды, фонари, сад',

            'gazebos.eyebrow' => 'Приватно',
            'gazebos.title' => 'Беседки на компанию',
            'gazebos.lead' => 'Уютные мини-беседки с тканевыми вуалями и низким столом. Хорошо для семейного вечера, дня рождения или просто долгого ужина под звёздами. Можно забронировать заранее.',
            'gazebos.button' => 'Забронировать беседку',
            'gazebos.alt' => 'Беседка с тканевыми занавесками внутри',

            'location.eyebrow' => 'Как добраться',
            'location.title' => '10 минут <em>от центра</em>',
            'location.lead' => 'Veranda стоит на склоне горы: короткий серпантин — и ты в саду с видом на Нячанг.',
            'location.directions' => '~10 минут на такси или байке от центра города. Парковка на месте.',
            'location.hours' => 'Пн–Чт 10:00–22:00 · Пт–Вс 10:00–23:00',
            'location.route' => 'Построить маршрут',
            'location.mapAlt' => 'Карта: где находится Veranda в Нячанге',

            'footer.tagline' => 'Ресторан, баня и игры на одной поляне в горах Нячанга.',
            'footer.colVeranda' => 'Veranda',
            'footer.colPartners' => 'Партнёры',
            'footer.colContacts' => 'Контакты',
            'footer.book' => 'Бронь столика',
            'footer.menu' => 'Меню',
            'footer.schedule' => 'Афиша недели',
            'footer.alllinks' => 'Все ссылки',
            'footer.director' => 'Написать управляющему',
            'footer.address' => 'Nha Trang, Việt Nam',
            'footer.partnerBanya' => 'Баня «Сила Духа»',
        ],

        // ─────────────────────────── TIẾNG VIỆT ───────────────────────────
        'vi' => [
            'seo.title' => 'Veranda — nhà hàng trên núi Nha Trang, banya & trò chơi',
            'seo.description' => 'Veranda Restaurant & Bar — nhà hàng trên sườn đồi, cách trung tâm Nha Trang 10 phút. Món ăn nhà làm, banya đốt củi, trò chơi cho cả gia đình, nhạc sống và phim ngoài trời. Vào cửa sự kiện miễn phí.',
            'seo.ogTitle' => 'Veranda — cả một buổi tối trải nghiệm trên núi Nha Trang',
            'seo.ogDescription' => 'Nhà hàng, banya đốt củi, trò chơi cho cả gia đình, nhạc sống và phim ngoài trời. 10 phút từ trung tâm.',

            'nav.complex' => 'Khu phức hợp',
            'nav.schedule' => 'Lịch sự kiện',
            'nav.location' => 'Vị trí',
            'nav.table' => 'Đặt bàn',
            'nav.switcher' => 'Ngôn ngữ',

            'hero.eyebrow' => 'Nha Trang · Việt Nam',
            'hero.title' => 'Cả một buổi tối<br><em>trên núi</em><br>Nha Trang',
            'hero.lead' => 'Nhà hàng với món ăn nhà làm, banya (tắm hơi kiểu Nga) đốt củi, trò chơi cho cả gia đình, nhạc sống và chiếu phim ngoài trời — tất cả ở một nơi, cách trung tâm 10 phút.',
            'hero.cta1' => 'Đặt bàn',
            'hero.cta2' => 'Bên trong có gì',
            'hero.side' => 'núi Hòn Chồng',

            'marquee' => ['Nhà hàng', 'Banya đốt củi', 'Archery Tag · Bắn laser', 'Khu trẻ em', 'Nhạc sống', 'Chiếu phim dưới sao'],

            'tonight.eyebrow' => 'Lịch sự kiện',
            'tonight.title' => 'Tối nay <em>có gì</em>',
            'tonight.lead' => 'Mỗi ngày trong tuần một không khí riêng. Vào cửa các sự kiện miễn phí — chỉ cần đặt bàn trước.',
            'tonight.badgeToday' => 'Tối nay',
            'tonight.badgeWeek' => 'Trong tuần',
            'tonight.book' => 'Đặt bàn',
            'tonight.films' => 'Lịch phim',
            'tonight.free' => 'Vào cửa tất cả sự kiện miễn phí',

            'days.full' => ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'],
            'days.short' => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'],

            'ev.d1.title' => 'Trò chơi board game', 'ev.d1.time' => 'cả buổi tối', 'ev.d1.note' => 'Bunker, Secret Hitler, Mafia, Uno — miễn phí. Hãy đến cùng nhóm bạn.',
            'ev.d2.title' => 'Chiếu phim dưới sao', 'ev.d2.time' => '18:00 · 20:00', 'ev.d2.note' => '18:00 — suất trẻ em · 20:00 — suất người lớn',
            'ev.d3.title' => 'Nhạc sống', 'ev.d3.time' => '19:00', 'ev.d3.note' => 'Cover các bản hit tiếng Anh',
            'ev.d4.title' => 'Chiếu phim dưới sao', 'ev.d4.time' => '18:00 · 20:00', 'ev.d4.note' => '18:00 — suất trẻ em · 20:00 — suất người lớn',
            'ev.d5.title' => 'Nhạc sống', 'ev.d5.time' => '19:00', 'ev.d5.note' => 'Các nhóm luân phiên: The Pennywort, Ulik, Ryadnovy, BiBi Duo',
            'ev.d6.title' => 'Nhạc sống', 'ev.d6.time' => '19:00', 'ev.d6.note' => 'Các nhóm luân phiên: The Pennywort, Ulik, Ryadnovy, BiBi Duo',
            'ev.d0.title' => 'Đêm nhạc sống', 'ev.d0.time' => '19:00', 'ev.d0.note' => 'Các nhóm luân phiên: The Pennywort, Ulik, Ryadnovy, BiBi Duo',

            'worlds.eyebrow' => 'Một khu phức hợp',
            'worlds.title' => 'Bốn thế giới trên một <em>sườn đồi</em>',
            'worlds.lead' => 'Ăn sáng dưới bóng cây trên núi → ban ngày vui chơi trong khi trẻ bận ở câu lạc bộ → xông banya lúc hoàng hôn → buổi tối ăn tối cùng nhạc sống hoặc phim ngoài trời.',

            'world.restaurant.label' => 'Nhà hàng',
            'world.restaurant.title' => 'Món ăn nhà làm <em>phong vị châu Âu</em>',
            'world.restaurant.lead' => 'Bữa sáng dưới bóng cây, borscht và solyanka, burger đặc trưng, pate và salad, cocktail và bia tươi. Chúng tôi nấu những món bạn nhớ ở nhà — và những món bạn từng thử khi đi du lịch.',
            'world.restaurant.tags' => ['Bữa sáng', 'Món nhà làm', 'Châu Âu', 'Cocktail', 'Bia tươi'],
            'world.restaurant.alt' => 'Các món ăn của bếp Veranda',
            'world.restaurant.link' => 'Xem thực đơn',
            'world.restaurant.book' => 'Đặt bàn',

            'world.banya.label' => 'Banya «Sila Dukha»',
            'world.banya.title' => 'Banya Nga đích thực <em>đốt củi</em>',
            'world.banya.lead' => 'Phòng xông hơi đốt củi nóng, hồ ngâm lạnh, các thợ banya giàu kinh nghiệm, chổi venik, trà mật ong và kvass. Sau đó — ăn tối ngay trên hiên mà không cần rời ghế.',
            'world.banya.tags' => ['Xông hơi đốt củi', 'Hồ ngâm lạnh', 'Thợ banya', 'Trà mật ong'],
            'world.banya.alt' => 'Banya Nga đốt củi — phòng xông với chổi venik',
            'world.banya.link' => 'Vào trang web banya',

            'world.gamezone.label' => 'GameZone',
            'world.gamezone.title' => 'Bắn laser và <em>Archery Tag</em>',
            'world.gamezone.lead' => 'Archery Tag — bắn cung đối kháng như paintball nhưng an toàn: cung tên đầu mềm và hướng dẫn trước trận. Cùng bắn laser và các trò giải đố. Trò chơi đồng đội cho nhóm bạn và team building — duy nhất tại Nha Trang.',
            'world.gamezone.tags' => ['Archery Tag', 'Bắn laser', 'Giải đố', 'Chòi BBQ', 'Team building'],
            'world.gamezone.alt' => 'Archery Tag — bắn cung đối kháng tại GameZone',
            'world.gamezone.link' => 'Vào trang web GameZone',

            'world.kids.label' => 'Khu trẻ em',
            'world.kids.title' => 'Chơi, sáng tạo và <em>tiệc cho bé</em>',
            'world.kids.lead' => 'Câu lạc bộ trẻ em có hoạt náo viên: trò chơi và workshop, vẽ tranh, disco ánh sáng và trị liệu qua truyện cổ tích. Khi các bé bận rộn, bố mẹ thư giãn ở nhà hàng hoặc banya. Sinh nhật và tiệc trọn gói: kịch bản riêng, show bong bóng xà phòng và giải đố.',
            'world.kids.tags' => ['Hoạt náo viên', 'Workshop', 'Sinh nhật', 'Trị liệu truyện', 'Disco'],
            'world.kids.alt' => 'Không gian chơi cho trẻ — đồ chơi, bóng bay, góc chụp ảnh',
            'world.kids.link' => 'Xem trên Instagram',

            'bento.eyebrow' => 'Không gian',
            'bento.title' => 'Một buổi tối ấm áp <em>trên núi</em>',
            'bento.quote' => '«Bạn leo lên từ Nha Trang ồn ào theo con đường quanh co — và lạc vào một thế giới khác: gió, tầm nhìn, sự tĩnh lặng, hơi ấm.»',
            'bento.cite' => '— khách, tháng 1 năm 2026',
            'gallery.alt' => 'Không gian Veranda — tầm nhìn núi, đèn lồng, khu vườn',

            'gazebos.eyebrow' => 'Riêng tư',
            'gazebos.title' => 'Chòi cho nhóm của bạn',
            'gazebos.lead' => 'Những chòi nhỏ ấm cúng với rèm vải và bàn thấp. Lý tưởng cho buổi tối gia đình, sinh nhật hay đơn giản một bữa tối dài dưới những vì sao. Có thể đặt trước.',
            'gazebos.button' => 'Đặt chòi',
            'gazebos.alt' => 'Chòi với rèm vải bên trong',

            'location.eyebrow' => 'Đường đến',
            'location.title' => '10 phút <em>từ trung tâm</em>',
            'location.lead' => 'Veranda nằm trên sườn đồi: một con đường quanh co ngắn và bạn đã ở trong khu vườn nhìn ra Nha Trang.',
            'location.directions' => '~10 phút đi taxi hoặc xe máy từ trung tâm thành phố. Có chỗ đậu xe tại chỗ.',
            'location.hours' => 'T2–T5 10:00–22:00 · T6–CN 10:00–23:00',
            'location.route' => 'Chỉ đường',
            'location.mapAlt' => 'Bản đồ: vị trí Veranda ở Nha Trang',

            'footer.tagline' => 'Nhà hàng, banya và trò chơi trên một sườn đồi giữa núi Nha Trang.',
            'footer.colVeranda' => 'Veranda',
            'footer.colPartners' => 'Đối tác',
            'footer.colContacts' => 'Liên hệ',
            'footer.book' => 'Đặt bàn',
            'footer.menu' => 'Thực đơn',
            'footer.schedule' => 'Lịch trong tuần',
            'footer.alllinks' => 'Tất cả liên kết',
            'footer.director' => 'Nhắn cho quản lý',
            'footer.address' => 'Nha Trang, Việt Nam',
            'footer.partnerBanya' => 'Banya «Sila Dukha»',
        ],
    ];
}
