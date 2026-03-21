<?php

namespace App\Classes;

class PosterMenuSync {
    private PosterAPI $api;
    private Database $db;

    public function __construct(PosterAPI $api, Database $db) {
        $this->api = $api;
        $this->db = $db;
    }

    public function sync(bool $forceCategoryIds = false): array {
        $startedAt = microtime(true);
        $this->db->createMenuTables();
        $pmi = $this->db->t('poster_menu_items');
        $mw = $this->db->t('menu_workshops');
        $mc = $this->db->t('menu_categories');
        $mi = $this->db->t('menu_items');
        $dbName = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        $isNullable = function (string $table, string $column) use ($dbName): bool {
            $row = $this->db->query(
                "SELECT IS_NULLABLE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1",
                [$dbName, $table, $column]
            )->fetch();
            return (string)($row['IS_NULLABLE'] ?? '') === 'YES';
        };
        $canInsertUnlinkedCategories = $isNullable($mc, 'workshop_id');
        $canInsertUnlinkedItems = $isNullable($mi, 'category_id');

        $workshops = $this->fetchWorkshops();
        $products = $this->fetchProducts();
        $categories = $this->fetchCategories();
        $usedFallbackCategories = false;
        if (empty($categories['main']) || empty($categories['sub'])) {
            $categories = $this->buildCategoriesFromProducts($products, $categories);
            $usedFallbackCategories = true;
        }
        $workshopMap = $this->upsertWorkshops($categories['main']);
        $categoryMap = $this->upsertCategories($categories['sub'], $workshopMap, $canInsertUnlinkedCategories);

        $workshopPosterIds = [];
        foreach ($categories['main'] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $workshopPosterIds[$id] = true;
        }
        $categoryPosterIds = [];
        foreach ($categories['sub'] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $categoryPosterIds[$id] = true;
        }

        $seenPosterIds = [];
        foreach ($products as $p) {
            $posterId = (int)($p['product_id'] ?? $p['id'] ?? 0);
            if ($posterId <= 0) {
                continue;
            }
            if (!$this->isProductVisible($p)) {
                continue;
            }
            $seenPosterIds[$posterId] = true;

            $nameRaw = (string)($p['product_name'] ?? $p['name'] ?? '');
            $priceRaw = $this->normalizePrice($this->extractPriceValue($p));
            $costRaw = $this->normalizePrice($p['cost'] ?? null);

            $workshopId = (int)($p['workshop'] ?? $p['workshop_id'] ?? 0);
            $workshopName = $workshopId > 0 ? ($workshops[$workshopId] ?? null) : null;

            $mainPosterCatId = (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0);
            $subPosterCatId = (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0);

            if ($subPosterCatId <= 0 && $mainPosterCatId > 0 && isset($categories['sub_parent'][$mainPosterCatId])) {
                $subPosterCatId = $mainPosterCatId;
                $mainPosterCatId = (int)$categories['sub_parent'][$subPosterCatId];
            }

            $mainCatName = $categories['main_names'][$mainPosterCatId] ?? (string)($p['category_name'] ?? $p['main_category_name'] ?? '');
            $subCatName = $categories['sub_names'][$subPosterCatId] ?? (string)($p['sub_category_name'] ?? $p['category2_name'] ?? '');

            $rawJson = json_encode($p, JSON_UNESCAPED_UNICODE);

            $this->db->query(
                "INSERT INTO {$pmi}
                    (poster_id, name_raw, price_raw, cost_raw, station_id, station_name, main_category_id, main_category_name, sub_category_id, sub_category_name, raw_json, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    name_raw=VALUES(name_raw),
                    price_raw=VALUES(price_raw),
                    cost_raw=VALUES(cost_raw),
                    station_id=VALUES(station_id),
                    station_name=VALUES(station_name),
                    main_category_id=VALUES(main_category_id),
                    main_category_name=VALUES(main_category_name),
                    sub_category_id=VALUES(sub_category_id),
                    sub_category_name=VALUES(sub_category_name),
                    raw_json=VALUES(raw_json),
                    is_active=1",
                [
                    $posterId,
                    $nameRaw === '' ? '—' : $nameRaw,
                    $priceRaw,
                    $costRaw,
                    $workshopId > 0 ? $workshopId : null,
                    $workshopName,
                    $mainPosterCatId ?: null,
                    $mainCatName !== '' ? $mainCatName : null,
                    $subPosterCatId ?: null,
                    $subCatName !== '' ? $subCatName : null,
                    $rawJson !== false ? $rawJson : null,
                ]
            );

            $posterRow = $this->db->query("SELECT id FROM {$pmi} WHERE poster_id = ? LIMIT 1", [$posterId])->fetch();
            $posterItemId = (int)($posterRow['id'] ?? 0);
            if ($posterItemId <= 0) {
                continue;
            }

            if ($canInsertUnlinkedItems) {
                try {
                    $this->db->query(
                        "INSERT INTO {$mi} (poster_item_id, category_id, image_url, is_published, sort_order)
                         VALUES (?, NULL, NULL, 0, 0)
                         ON DUPLICATE KEY UPDATE poster_item_id = {$mi}.poster_item_id",
                        [$posterItemId]
                    );
                } catch (\Throwable $e) {
                }
            }
        }

        $this->markMissingItemsInactive(array_keys($seenPosterIds));
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        return [
            'ok' => true,
            'duration_ms' => $durationMs,
            'items_seen' => count($seenPosterIds),
            'workshops' => (int)$this->db->query("SELECT COUNT(*) FROM {$mw}")->fetchColumn(),
            'categories' => (int)$this->db->query("SELECT COUNT(*) FROM {$mc}")->fetchColumn(),
            'used_categories_fallback' => $usedFallbackCategories,
        ];
    }

    private function getFixedWorkshopTranslations(): array {
        return [
            4 => ['ru' => 'НОВЫЙ ГОД', 'en' => 'NEW YEAR', 'vn' => 'NEW YEAR', 'ko' => '새해 메뉴'],
            1 => ['ru' => 'КУХНЯ', 'en' => 'KITCHEN', 'vn' => 'KITCHEN', 'ko' => '키친 메뉴'],
            2 => ['ru' => 'БАР', 'en' => 'BAR', 'vn' => 'VERANDA', 'ko' => '바 메뉴'],
            3 => ['ru' => 'Кальян', 'en' => 'Shisha', 'vn' => 'Shisha', 'ko' => '시샤'],
            5 => ['ru' => 'Блины', 'en' => 'Блины', 'vn' => 'Блины', 'ko' => '블리니'],
            6 => ['ru' => 'ХОР', 'en' => 'ХОР', 'vn' => 'ХОР', 'ko' => '공연 / 라이브'],
            7 => ['ru' => 'Завтраки (до 15:00)', 'en' => 'Breakfasts (before 15:00)', 'vn' => 'Bữa sáng (trước 15:00)', 'ko' => '모닝 메뉴 (15시 이전)'],
        ];
    }

    private function getFixedCategoryTranslations(): array {
        return [
            1 => ['ru' => 'Холодные закуски', 'en' => 'Сold appetizers', 'vn' => 'Khai vị lạnh', 'ko' => '차가운 에피타이저'],
            2 => ['ru' => 'Горячие закуски', 'en' => 'Hot appetizers', 'vn' => 'Khai vị nóng', 'ko' => '따뜻한 에피타이저'],
            3 => ['ru' => 'Салаты', 'en' => 'Salads', 'vn' => 'Salad', 'ko' => '샐러드'],
            4 => ['ru' => 'Основные блюда', 'en' => 'Main menu', 'vn' => 'Món chính', 'ko' => '메인 요리'],
            5 => ['ru' => 'Бургеры', 'en' => 'Burgers', 'vn' => 'Burger', 'ko' => '버거'],
            6 => ['ru' => 'Шаурма', 'en' => 'Shawarma', 'vn' => 'Shawarma', 'ko' => '샤와르마'],
            8 => ['ru' => 'Боулы', 'en' => 'Bows', 'vn' => 'Bows', 'ko' => '볼 요리'],
            9 => ['ru' => 'Супы', 'en' => 'Soups', 'vn' => 'Súp', 'ko' => '수프'],
            10 => ['ru' => 'Десерты', 'en' => 'Desserts', 'vn' => 'Tráng miệng', 'ko' => '디저트'],
            11 => ['ru' => 'Увкуснитель', 'en' => 'Extras', 'vn' => 'Extras', 'ko' => '추가 토핑'],
            12 => ['ru' => 'Детское меню', 'en' => 'Детское меню', 'vn' => 'Thực đơn cho trẻ em', 'ko' => '키즈 메뉴'],
            13 => ['ru' => 'Банкет', 'en' => 'Банкет', 'vn' => 'Thực đơn tiệc', 'ko' => '연회 메뉴'],
            14 => ['ru' => 'Кофе', 'en' => 'Coffee', 'vn' => 'Cà phê', 'ko' => '커피'],
            15 => ['ru' => 'Чай', 'en' => 'Tea', 'vn' => 'Trà', 'ko' => '차'],
            16 => ['ru' => 'Свежевыжатые соки', 'en' => 'Fresh juices', 'vn' => 'Nước ép tươi', 'ko' => '생과일 주스'],
            17 => ['ru' => 'Смузи', 'en' => 'Smoothies', 'vn' => 'Sinh tố', 'ko' => '스무디'],
            18 => ['ru' => 'Милкшейки', 'en' => 'Milkshakes', 'vn' => 'Sữa lắc', 'ko' => '밀크셰이크'],
            19 => ['ru' => 'Моктейли', 'en' => 'Mocktails', 'vn' => 'Mocktail', 'ko' => '논알코올 칵테일'],
            20 => ['ru' => 'Коктейли', 'en' => 'Cocktails', 'vn' => 'Cocktail', 'ko' => '칵테일'],
            21 => ['ru' => 'Крепкий алкоголь', 'en' => 'Strong Alcohol', 'vn' => 'Rượu mạnh', 'ko' => '도수 높은 주류'],
            22 => ['ru' => 'Пиво', 'en' => 'Beer', 'vn' => 'Bia', 'ko' => '맥주'],
            23 => ['ru' => 'Вино', 'en' => 'Wine', 'vn' => 'Rượu vang', 'ko' => '와인'],
            24 => ['ru' => 'Ликеры', 'en' => 'Liquers', 'vn' => 'Rượu mùi', 'ko' => '리큐르'],
            25 => ['ru' => 'Настойки', 'en' => 'Infusions', 'vn' => 'Rượu ngâm', 'ko' => '인퓨전 주류'],
            26 => ['ru' => 'Безалкогольные напитки', 'en' => 'Soft Drinks', 'vn' => 'Nước ngọt', 'ko' => '탄산/청량 음료'],
            27 => ['ru' => 'New coctails', 'en' => 'New coctails', 'vn' => 'Cocktail mới', 'ko' => '신상 칵테일'],
        ];
    }

    private function getFixedItemTranslations(): array {
        return [
            15 => ['ru_title' => 'Джерки', 'en_title' => 'Jerky', 'vn_title' => 'Thịt khô (jerky)', 'ko_title' => '제키 육포', 'ru_desc' => 'Мясные джерки к пиву', 'en_desc' => 'Jerky meat snack', 'vn_desc' => 'Đồ nhắm thịt khô ăn kèm bia', 'ko_desc' => '맥주와 잘 어울리는 짭짤한 육포 안주'],
            24 => ['ru_title' => 'Свиной шашлык', 'en_title' => 'Pork Shashlik', 'vn_title' => 'Thịt heo nướng xiên', 'ko_title' => '돼지고기 꼬치구이', 'ru_desc' => 'Свиной шашлык на шампурах', 'en_desc' => 'Pork shashlik skewers', 'vn_desc' => 'Thịt heo xiên nướng', 'ko_desc' => '숯불에 구운 촉촉한 돼지고기 꼬치'],
            25 => ['ru_title' => 'Куриный шашлык', 'en_title' => 'Chicken Shashlik', 'vn_title' => 'Thịt gà nướng xiên', 'ko_title' => '닭꼬치구и', 'ru_desc' => 'Куриный шашлык на шампурах', 'en_desc' => 'Chicken shashlik skewers', 'vn_desc' => 'Thịt gà xiên nướng', 'ko_desc' => '향긋한 양념에 재운 닭고기를 구운 꼬치'],
            36 => ['ru_title' => 'Шаурма', 'en_title' => 'Shawarma', 'vn_title' => 'Shawarma', 'ko_title' => '샤와르마', 'ru_desc' => 'Классическая шаурма в лаваше', 'en_desc' => 'Classic shawarma wrap', 'vn_desc' => 'Bánh mì cuộn shawarma', 'ko_desc' => '부드러운 고기와 채소를 넣은 랩 샌드위치'],
            37 => ['ru_title' => 'Разливное пиво', 'en_title' => 'Draft Beer', 'vn_title' => 'Bia tươi', 'ko_title' => '생맥주', 'ru_desc' => 'Разливное пиво', 'en_desc' => 'Draft beer', 'vn_desc' => 'Bia hơi tươi', 'ko_desc' => '시원하게 따른 생맥주 한 잔'],
            53 => ['ru_title' => 'Абсент', 'en_title' => 'Absinthe', 'vn_title' => 'Absinthe', 'ko_title' => '압생트', 'ru_desc' => 'Абсент', 'en_desc' => 'Absinthe', 'vn_desc' => 'Rượu absinthe', 'ko_desc' => '허브 향이 강한 고도수 리큐르'],
            54 => ['ru_title' => 'Лонг‑Айленд', 'en_title' => 'Long Island Iced Tea', 'vn_title' => 'Long Island Iced Tea', 'ko_title' => '롱아일랜드 아이스티', 'ru_desc' => 'Коктейль Лонг‑Айленд', 'en_desc' => 'Long Island Iced Tea', 'vn_desc' => 'Cocktail Long Island', 'ko_desc' => '여러 술이 섞인 클래식 칵테일'],
            55 => ['ru_title' => 'Лимончелло', 'en_title' => 'Limoncello', 'vn_title' => 'Limoncello', 'ko_title' => '리몬첼로', 'ru_desc' => 'Ликер лимончелло', 'en_desc' => 'Limoncello liqueur', 'vn_desc' => 'Rượu Limoncello', 'ko_desc' => '레몬 향이 진한 달콤한 리큐르'],
            57 => ['ru_title' => 'Лимончелло (пиво)', 'en_title' => 'Limoncello beer', 'vn_title' => 'Limoncello', 'ko_title' => 'Bia Limoncello', 'ru_desc' => 'Пиво с лимончелло', 'en_desc' => 'Beer with limoncello', 'vn_desc' => 'Bia pha Limoncello', 'ko_desc' => '리몬첼로 향을 더한 상큼한 맥주'],
            58 => ['ru_title' => 'Ром‑кола', 'en_title' => 'Rum Cola', 'vn_title' => 'Rum Cola', 'ko_title' => '럼 콜라', 'ru_desc' => 'Ром с колой', 'en_desc' => 'Rum & cola', 'vn_desc' => 'Rum với nước ngọt cola', 'ko_desc' => '럼과 콜라로 만든 상큼한 하이볼'],
            59 => ['ru_title' => 'Ром', 'en_title' => 'Rum', 'vn_title' => 'Rum', 'ko_title' => '럼', 'ru_desc' => 'Ром', 'en_desc' => 'Rum', 'vn_desc' => 'Rượu rum', 'ko_desc' => '부드러운 향과 단맛이 있는 럼'],
            60 => ['ru_title' => 'Самбука', 'en_title' => 'Sambuca', 'vn_title' => 'Sambuca', 'ko_title' => '삼부카', 'ru_desc' => 'Самбука', 'en_desc' => 'Sambuca', 'vn_desc' => 'Rượu Sambuca', 'ko_desc' => '아니스 향이 나는 이탈리안 리큐르'],
            61 => ['ru_title' => 'Джин', 'en_title' => 'Gin', 'vn_title' => 'Gin', 'ko_title' => '진', 'ru_desc' => 'Джин', 'en_desc' => 'Rượu gin', 'vn_desc' => 'Rượu gin', 'ko_desc' => '상쾌한 보태니컬 향의 진'],
            62 => ['ru_title' => 'Водка', 'en_title' => 'Vodka', 'vn_title' => 'Vodka', 'ko_title' => '보드카', 'ru_desc' => 'Водка', 'en_desc' => 'Vodka', 'vn_desc' => 'Rượu vodka', 'ko_desc' => '깔끔한 목넘김의 보드카'],
            68 => ['ru_title' => 'Бейлиз', 'en_title' => 'Baileys', 'vn_title' => 'Baileys', 'ko_title' => '베일리스', 'ru_desc' => 'Ликёр Бейлиз', 'en_desc' => 'Baileys Irish cream', 'vn_desc' => 'Rượu Baileys', 'ko_desc' => '크리미한 아일리시 크림 리큐르'],
            71 => ['ru_title' => 'Джин‑тоник', 'en_title' => 'Gin Tonic', 'vn_title' => 'Gin Tonic', 'ko_title' => '진 토닉', 'ru_desc' => 'Коктейль джин‑тоник', 'en_desc' => 'Gin & tonic', 'vn_desc' => 'Gin tonic', 'ko_desc' => '진과 토닉워터로 만든 상쾌한 칵테일'],
            72 => ['ru_title' => 'Виски‑кола', 'en_title' => 'Whisky Cola', 'vn_title' => 'Whisky Cola', 'ko_title' => '위스키 콜라', 'ru_desc' => 'Виски с колой', 'en_desc' => 'Whisky & cola', 'vn_desc' => 'Whisky cola', 'ko_desc' => '위스키와 콜라로 만든 기본 하이볼'],
            81 => ['ru_title' => 'Банановый ликёр', 'en_title' => 'Banana Liqueur', 'vn_title' => 'Rượu mùi chuối', 'ko_title' => '바나나 리큐르', 'ru_desc' => 'Банановый ликёр', 'en_desc' => 'Banana liqueur', 'vn_desc' => 'Rượu mùi chuối', 'ko_desc' => '달콤한 바나나 향의 리큐르'],
            84 => ['ru_title' => 'Чёрный чай', 'en_title' => 'Black Tea', 'vn_title' => 'Trà đen', 'ko_title' => '블랙 티', 'ru_desc' => 'Чёрный чай', 'en_desc' => 'Black tea', 'vn_desc' => 'Trà đen', 'ko_desc' => '진하게 우린 홍차'],
            87 => ['ru_title' => 'Шаурма XL', 'en_title' => 'Shawarma XL', 'vn_title' => 'Shawarma XL', 'ko_title' => '샤와르마 XL', 'ru_desc' => 'Большая шаурма', 'en_desc' => 'Large shawarma', 'vn_desc' => 'Shawarma cỡ lớn', 'ko_desc' => '크게 즐기는 푸짐한 샤와르마'],
            109 => ['ru_title' => 'Картошка фри', 'en_title' => 'French Fries', 'vn_title' => 'Khoai tây chiên', 'ko_title' => '프렌치프라이', 'ru_desc' => 'Картофель фри', 'en_desc' => 'French fries', 'vn_desc' => 'Khoai tây chiên', 'ko_desc' => '바삭하게 튀긴 감자튀김'],
            110 => ['ru_title' => 'Картошка Хангритос', 'en_title' => 'Hungry Fries', 'vn_title' => 'Khoai tây chiên HANGRITOS', 'ko_title' => '행그리토 감자', 'ru_desc' => 'Фирменная картошка Хангритос', 'en_desc' => 'House fries Hangritos', 'vn_desc' => 'Khoai tây chiên sốt đặc biệt', 'ko_desc' => '매콤 소스를 곁들인 프라이드 포테이토'],
            111 => ['ru_title' => 'Куриные наггетсы', 'en_title' => 'Chicken Nuggets', 'vn_title' => 'Gà nugget', 'ko_title' => '치킨 너겟', 'ru_desc' => 'Куриные наггетсы', 'en_desc' => 'Chicken nuggets', 'vn_desc' => 'Gà nugget', 'ko_desc' => '한 입 크기의 바삭한 치킨 스낵'],
            112 => ['ru_title' => 'Луковые кольца', 'en_title' => 'Onion Rings', 'vn_title' => 'Hành tây chiên vòng', 'ko_title' => '어니언 링', 'ru_desc' => 'Луковые кольца', 'en_desc' => 'Onion rings', 'vn_desc' => 'Hành tây chiên vòng', 'ko_desc' => '바삭하게 튀긴 양파 링'],
            121 => ['ru_title' => 'Паштет страусиный', 'en_title' => 'Ostrich Pâté', 'vn_title' => 'Pate đà điểu', 'ko_title' => '타조 파테', 'ru_desc' => 'Паштет из страуса', 'en_desc' => 'Ostrich pâté', 'vn_desc' => 'Pate thịt đà điểu', 'ko_desc' => '타조 고기로 만든 부드러운 파테'],
            122 => ['ru_title' => 'Брускетта Наполитано', 'en_title' => 'Bruschetta Napolitano', 'vn_title' => 'Bruschetta Napolitano', 'ko_title' => '브루스케타 나폴리', 'ru_desc' => 'Брускетта с томатами', 'en_desc' => 'Tomato bruschetta', 'vn_desc' => 'Bruschetta cà chua kiểu Ý', 'ko_desc' => '토마토와 허브를 올린 바삭한 브루스케타'],
            125 => ['ru_title' => 'Спринг-роллы', 'en_title' => 'Spring Rolls', 'vn_title' => 'Gỏi cuốn', 'ko_title' => '스프링롤', 'ru_desc' => 'Спринг‑роллы', 'en_desc' => 'Spring rolls', 'vn_desc' => 'Gỏi cuốn Việt Nam', 'ko_desc' => '신선한 채소를 넣은 라이스페이퍼 롤'],
            127 => ['ru_title' => 'Пивное плато', 'en_title' => 'Beer Platter', 'vn_title' => 'Tháp đồ nhắm bia', 'ko_title' => '비어 플래터', 'ru_desc' => 'Ассорти закусок к пиву', 'en_desc' => 'Beer snack platter', 'vn_desc' => 'Tháp đồ nhắm bia', 'ko_desc' => '여러 가지 맥주 안주 모둠 한 판'],
            128 => ['ru_title' => 'Салат с курицей', 'en_title' => 'Chicken Salad', 'vn_title' => 'Salad gà', 'ko_title' => '치킨 샐러드', 'ru_desc' => 'Салат с курицей', 'en_desc' => 'Chicken salad', 'vn_desc' => 'Salad gà', 'ko_desc' => '구운 닭가슴살과 신선한 채소 샐러드'],
            129 => ['ru_title' => 'Креветка в ананасе', 'en_title' => 'Pineapple Shrimp', 'vn_title' => 'Tôm sốt dứa', 'ko_title' => '파인애플 새우', 'ru_desc' => 'Креветки в ананасовом соусе', 'en_desc' => 'Shrimp in pineapple sauce', 'vn_desc' => 'Tôm sốt dứa', 'ko_desc' => '파인애플 소스로 볶은 새우 요리'],
            131 => ['ru_title' => 'Греческий салат', 'en_title' => 'Greek Salad', 'vn_title' => 'Salad Hy Lạp', 'ko_title' => '그리스 샐러드', 'ru_desc' => 'Греческий салат', 'en_desc' => 'Greek salad', 'vn_desc' => 'Salad kiểu Hy Lạp', 'ko_desc' => '페타 치즈와 올리브가 들어간 샐러드'],
            132 => ['ru_title' => 'Мидии', 'en_title' => 'Mussels', 'vn_title' => 'Chem chép sốt', 'ko_title' => '홍합 요리', 'ru_desc' => 'Мидии в соусе', 'en_desc' => 'Mussels in sauce', 'vn_desc' => 'Chem chép sốt', 'ko_desc' => '소스와 함께 제공되는 홍합 요리'],
            133 => ['ru_title' => 'Стейк лосося', 'en_title' => 'Salmon Steak', 'vn_title' => 'Cá hồi steak', 'ko_title' => '연어 스테이크', 'ru_desc' => 'Стейк из лосося', 'en_desc' => 'Salmon steak', 'vn_desc' => 'Cá hồi nướng kiểu steak', 'ko_desc' => '겉은 바삭 속은 촉촉한 연어 스테이크'],
            134 => ['ru_title' => 'Стейк тунца', 'en_title' => 'Tuna Steak', 'vn_title' => 'Cá ngừ steak', 'ko_title' => '참치 스테이크', 'ru_desc' => 'Стейк из тунца', 'en_desc' => 'Tuna steak', 'vn_desc' => 'Cá ngừ nướng kiểu steak', 'ko_desc' => '적당히 구워낸 참치 스테이크'],
            135 => ['ru_title' => 'Медальоны говяжьи', 'en_title' => 'Beef Medallions', 'vn_title' => 'Thịt bò medallion', 'ko_title' => '비프 메달리온', 'ru_desc' => 'Говяжьи медальоны', 'en_desc' => 'Beef medallions', 'vn_desc' => 'Thịt bò miếng nhỏ áp chảo', 'ko_desc' => '한입 크기로 구운 연한 소고기 스테이크'],
            139 => ['ru_title' => 'Бургер классик', 'en_title' => 'Classic Burger', 'vn_title' => 'Burger cổ điển', 'ko_title' => '클래식 버거', 'ru_desc' => 'Классический бургер', 'en_desc' => 'Classic beef burger', 'vn_desc' => 'Burger bò cổ điển', 'ko_desc' => '소고기 패티와 채소가 들어간 기본 버거'],
            140 => ['ru_title' => 'Бургер грибной', 'en_title' => 'Mushroom Burger', 'vn_title' => 'Burger nấm', 'ko_title' => '머시룸 버거', 'ru_desc' => 'Бургер с грибами', 'en_desc' => 'Mushroom burger', 'vn_desc' => 'Burger kèm nấm xào', 'ko_desc' => '볶은 버섯을 올린 풍미 깊은 버거'],
            141 => ['ru_title' => 'Бургер континенталь', 'en_title' => 'Continental Burger', 'vn_title' => 'Burger Continental', 'ko_title' => '콘티넨털 버거', 'ru_desc' => 'Континентальный бургер', 'en_desc' => 'Continental burger', 'vn_desc' => 'Burger phong cách Âu', 'ko_desc' => '유럽식 스타일로 구성한 프리미엄 버거'],
        ];
    }

    private function isProductVisible(array $p): bool {
        if (isset($p['spots']) && is_array($p['spots']) && !empty($p['spots'])) {
            foreach ($p['spots'] as $spot) {
                if (!is_array($spot)) continue;
                if (array_key_exists('visible', $spot)) {
                    if ((int)$spot['visible'] === 1) {
                        return true;
                    }
                }
            }
            return false;
        }
        if (array_key_exists('visible', $p)) {
            return (int)$p['visible'] === 1;
        }
        if (array_key_exists('hidden', $p)) {
            return (int)$p['hidden'] !== 1;
        }
        return true;
    }

    private function fetchProducts(): array {
        try {
            $res = $this->api->request('menu.getProducts');
            return is_array($res) ? $res : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function fetchWorkshops(): array {
        $map = [];
        $res = null;
        try {
            $res = $this->api->request('menu.getWorkshops');
        } catch (\Exception $e) {
        }
        if ($res === null) {
            try {
                $res = $this->api->request('menu.getWorkshop');
            } catch (\Exception $e) {
                $res = null;
            }
        }
        if (is_array($res)) {
            foreach ($res as $w) {
                $id = (int)($w['workshop_id'] ?? $w['id'] ?? 0);
                $name = trim((string)($w['workshop_name'] ?? $w['name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $map[$id] = $name;
                }
            }
        }
        return $map;
    }

    private function fetchCategories(): array {
        $main = [];
        $sub = [];
        $mainNames = [];
        $subNames = [];
        $subParent = [];

        try {
            $res = $this->api->request('menu.getCategories');
            if (is_array($res)) {
                foreach ($res as $c) {
                    $id = (int)($c['category_id'] ?? $c['id'] ?? 0);
                    $name = (string)($c['category_name'] ?? $c['name'] ?? '');
                    $parent = (int)($c['parent_category'] ?? $c['parent_id'] ?? $c['parentCategoryId'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    if ($parent <= 0) {
                        $main[] = ['id' => $id, 'name' => $name];
                        $mainNames[$id] = $name;
                    } else {
                        $sub[] = ['id' => $id, 'name' => $name, 'parent' => $parent];
                        $subNames[$id] = $name;
                        $subParent[$id] = $parent;
                    }
                }
            }
        } catch (\Exception $e) {
        }

        return [
            'main' => $main,
            'sub' => $sub,
            'main_names' => $mainNames,
            'sub_names' => $subNames,
            'sub_parent' => $subParent
        ];
    }

    private function buildCategoriesFromProducts(array $products, array $base): array {
        $main = [];
        $sub = [];
        $mainNames = $base['main_names'] ?? [];
        $subNames = $base['sub_names'] ?? [];
        $subParent = $base['sub_parent'] ?? [];
        $mainSeen = [];
        $subSeen = [];

        foreach ($products as $p) {
            if (!is_array($p)) continue;
            if (!$this->isProductVisible($p)) continue;

            $mainPosterCatId = (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0);
            $subPosterCatId = (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0);

            $mainName = trim((string)($p['category_name'] ?? $p['main_category_name'] ?? ''));
            $subName = trim((string)($p['sub_category_name'] ?? $p['category2_name'] ?? ''));

            if ($mainPosterCatId > 0 && !isset($mainSeen[$mainPosterCatId])) {
                $mainSeen[$mainPosterCatId] = true;
                $main[] = ['id' => $mainPosterCatId, 'name' => $mainName];
            }
            if ($mainPosterCatId > 0 && $mainName !== '' && !isset($mainNames[$mainPosterCatId])) {
                $mainNames[$mainPosterCatId] = $mainName;
            }

            if ($subPosterCatId > 0 && !isset($subSeen[$subPosterCatId])) {
                $subSeen[$subPosterCatId] = true;
                $sub[] = ['id' => $subPosterCatId, 'name' => $subName, 'parent' => $mainPosterCatId];
            }
            if ($subPosterCatId > 0 && $subName !== '' && !isset($subNames[$subPosterCatId])) {
                $subNames[$subPosterCatId] = $subName;
            }
            if ($subPosterCatId > 0 && $mainPosterCatId > 0 && !isset($subParent[$subPosterCatId])) {
                $subParent[$subPosterCatId] = $mainPosterCatId;
            }
        }

        return [
            'main' => !empty($main) ? $main : ($base['main'] ?? []),
            'sub' => !empty($sub) ? $sub : ($base['sub'] ?? []),
            'main_names' => $mainNames,
            'sub_names' => $subNames,
            'sub_parent' => $subParent,
        ];
    }

    private function upsertWorkshops(array $items): array {
        $mw = $this->db->t('menu_workshops');
        $map = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $name = (string)($item['name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }
            $sort = $this->extractLeadingSortNumber($name);
            $this->db->query(
                "INSERT INTO {$mw} (poster_id, name_raw, sort_order, show_on_site)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    name_raw=VALUES(name_raw),
                    sort_order=IF({$mw}.sort_order=0, VALUES(sort_order), {$mw}.sort_order)",
                [$id, $name, $sort]
            );
        }
        $rows = $this->db->query("SELECT id, poster_id FROM {$mw}")->fetchAll();
        foreach ($rows as $r) {
            $map[(int)$r['poster_id']] = (int)$r['id'];
        }
        return $map;
    }

    private function upsertCategories(array $items, array $workshopMap, bool $allowNullWorkshopId): array {
        $mc = $this->db->t('menu_categories');
        $map = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $name = (string)($item['name'] ?? '');
            $parent = (int)($item['parent'] ?? 0);
            if ($id <= 0 || $name === '' || $parent <= 0) {
                continue;
            }
            $sort = $this->extractLeadingSortNumber($name);
            $workshopId = (int)($workshopMap[$parent] ?? 0);
            if (!$allowNullWorkshopId && $workshopId <= 0) {
                continue;
            }
            $this->db->query(
                "INSERT INTO {$mc} (poster_id, workshop_id, name_raw, sort_order, show_on_site)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    name_raw=VALUES(name_raw),
                    sort_order=IF({$mc}.sort_order=0, VALUES(sort_order), {$mc}.sort_order)",
                [$id, $workshopId > 0 ? $workshopId : null, $name, $sort]
            );
        }
        $rows = $this->db->query("SELECT id, poster_id FROM {$mc}")->fetchAll();
        foreach ($rows as $r) {
            $map[(int)$r['poster_id']] = (int)$r['id'];
        }
        return $map;
    }

    private function extractLeadingSortNumber(string $name): int {
        $s = trim($name);
        if ($s === '') return 0;
        if (preg_match('/^\s*(\d{1,3})/u', $s, $m)) {
            return (int)$m[1];
        }
        return 999;
    }

    private function markMissingItemsInactive(array $activePosterIds): void {
        $pmi = $this->db->t('poster_menu_items');
        if (empty($activePosterIds)) {
            $this->db->query("UPDATE {$pmi} SET is_active = 0 WHERE is_active = 1");
            return;
        }

        $placeholders = implode(',', array_fill(0, count($activePosterIds), '?'));
        $this->db->query("UPDATE {$pmi} SET is_active = 0 WHERE is_active = 1 AND poster_id NOT IN ($placeholders)", $activePosterIds);
    }

    private function normalizePrice($price): ?float {
        if ($price === null || $price === '') {
            return null;
        }
        if (is_string($price)) {
            $price = str_replace(',', '.', $price);
        }
        if (is_numeric($price)) {
            $v = (float)$price;
            if ($v > 10000) {
                $v = $v / 100;
            }
            return round($v, 2);
        }
        return null;
    }

    private function extractPriceValue(array $product) {
        if (array_key_exists('price', $product)) {
            $price = $product['price'];
            if (is_array($price)) {
                foreach ($price as $v) {
                    if ($v !== null && $v !== '') {
                        return $v;
                    }
                }
            } else {
                return $price;
            }
        }
        if (array_key_exists('price_raw', $product)) {
            return $product['price_raw'];
        }
        if (array_key_exists('cost', $product)) {
            return $product['cost'];
        }
        return null;
    }
}
