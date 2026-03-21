<?php

namespace App\Classes;

class MenuAutoFill {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function run(): array {
        $this->db->createMenuTables();
        $pmi = $this->db->t('poster_menu_items');
        $miRu = $this->db->t('menu_items_ru');
        $miEn = $this->db->t('menu_items_en');
        $miVn = $this->db->t('menu_items_vn');

        $barMap = $this->getBarTranslations();
        $kitchenMap = $this->getKitchenTranslations();
        $barIndex = [];
        foreach ($barMap as $r) {
            foreach ([$r['ru_title'], $r['en_title'], $r['vn_title']] as $key) {
                $k = $this->norm($key);
                if ($k !== '') {
                    $barIndex[$k] = $r;
                }
            }
        }

        $rows = $this->db->query(
            "SELECT
                p.id poster_item_id,
                p.poster_id,
                p.name_raw,
                p.main_category_name,
                p.sub_category_name,
                p.station_name,
                ru.title ru_title,
                ru.sub_category ru_sub_category,
                ru.description ru_description,
                en.title en_title,
                en.sub_category en_sub_category,
                en.description en_description,
                vn.title vn_title,
                vn.sub_category vn_sub_category,
                vn.description vn_description
             FROM {$pmi} p
             LEFT JOIN {$miRu} ru ON ru.poster_item_id = p.id
             LEFT JOIN {$miEn} en ON en.poster_item_id = p.id
             LEFT JOIN {$miVn} vn ON vn.poster_item_id = p.id",
            []
        )->fetchAll();

        $updated = 0;
        $titlesFilled = 0;
        $descriptionsFilled = 0;
        $subCategoriesFilled = 0;

        foreach ($rows as $row) {
            $posterItemId = (int)($row['poster_item_id'] ?? 0);
            if ($posterItemId <= 0) {
                continue;
            }

            $nameRaw = (string)($row['name_raw'] ?? '');
            $stationName = strtolower(trim((string)($row['station_name'] ?? '')));
            $posterCategory = $this->stripCategoryNumbering((string)($row['main_category_name'] ?? ''));
            $posterId = (int)($row['poster_id'] ?? 0);

            $ruTitle = trim((string)($row['ru_title'] ?? ''));
            $enTitle = trim((string)($row['en_title'] ?? ''));
            $vnTitle = trim((string)($row['vn_title'] ?? ''));

            $ruDesc = trim((string)($row['ru_description'] ?? ''));
            $enDesc = trim((string)($row['en_description'] ?? ''));
            $vnDesc = trim((string)($row['vn_description'] ?? ''));

            $ruSub = trim((string)($row['ru_sub_category'] ?? ''));
            $enSub = trim((string)($row['en_sub_category'] ?? ''));
            $vnSub = trim((string)($row['vn_sub_category'] ?? ''));

            $mapped = null;
            if ($stationName !== '' && str_contains($stationName, 'bar')) {
                $k = $this->norm($nameRaw);
                if (isset($barIndex[$k])) {
                    $mapped = $barIndex[$k];
                } else {
                    foreach ($barIndex as $bk => $br) {
                        if ($bk !== '' && str_contains($k, $bk)) {
                            $mapped = $br;
                            break;
                        }
                    }
                }
            }

            $ruNew = $ruTitle;
            $enNew = $enTitle;
            $vnNew = $vnTitle;
            $ruSubNew = $ruSub;
            $enSubNew = $enSub;
            $vnSubNew = $vnSub;
            $ruDescNew = $ruDesc;
            $enDescNew = $enDesc;
            $vnDescNew = $vnDesc;

            $split = $this->splitRuEn($nameRaw);
            $ruCandidate = (string)($split['ru'] ?? '');
            $enCandidate = (string)($split['en'] ?? '');
            $vnCandidate = $enCandidate;

            $hasSep = function (string $s): bool {
                return str_contains($s, '/') || str_contains($s, '|');
            };
            $hasRu = function (string $s): bool {
                return (bool)preg_match('/\p{Cyrillic}/u', $s);
            };
            $hasEn = function (string $s): bool {
                return (bool)preg_match('/[A-Za-z]/', $s);
            };
            $isMixed = function (string $s) use ($hasRu, $hasEn): bool {
                return $hasRu($s) && $hasEn($s);
            };

            if ($ruNew === '' || $hasSep($ruNew) || $isMixed($ruNew) || $ruNew === $nameRaw) {
                $ruNew = $ruCandidate;
            }
            if ($enNew === '' || $hasSep($enNew) || $hasRu($enNew) || $enNew === $nameRaw) {
                $enNew = $enCandidate;
            }
            if ($vnNew === '' || $hasSep($vnNew) || $hasRu($vnNew) || $vnNew === $nameRaw) {
                $vnNew = $vnCandidate;
            }

            if ($stationName === 'kitchen' && $posterId > 0 && array_key_exists($posterId, $kitchenMap)) {
                $ruFromMap = trim((string)($kitchenMap[$posterId]['ru'] ?? ''));
                $enFromMap = trim((string)($kitchenMap[$posterId]['en'] ?? ''));
                $vnFromMap = trim((string)($kitchenMap[$posterId]['vn'] ?? ''));
                if ($ruFromMap !== '') $ruNew = $ruFromMap;
                if ($enFromMap !== '') $enNew = $enFromMap;
                if ($vnFromMap !== '') $vnNew = $vnFromMap;
            }

            if ($mapped) {
                $ruFromMap = trim((string)($mapped['ru_title'] ?? ''));
                $enFromMap = trim((string)($mapped['en_title'] ?? ''));
                $vnFromMap = trim((string)($mapped['vn_title'] ?? ''));
                if ($ruFromMap !== '') $ruNew = $ruFromMap;
                if ($enFromMap !== '') $enNew = $enFromMap;
                if ($vnFromMap !== '') $vnNew = $vnFromMap;
                if ($ruDescNew === '' && $mapped['ru_description'] !== '') $ruDescNew = $mapped['ru_description'];
                if ($enDescNew === '' && $mapped['en_description'] !== '') $enDescNew = $mapped['en_description'];
                if ($vnDescNew === '' && $mapped['vn_description'] !== '') $vnDescNew = $mapped['vn_description'];
            }

            $ruNew = $this->stripLeadingNumberingExcept7up($ruNew);
            $enNew = $this->stripLeadingNumberingExcept7up($enNew);
            $vnNew = $this->stripLeadingNumberingExcept7up($vnNew);

            if ($ruDescNew === '') $ruDescNew = $this->generateDescription('ru', $stationName, $ruNew, $posterCategory);
            if ($enDescNew === '') $enDescNew = $this->generateDescription('en', $stationName, $enNew, $posterCategory);
            if ($vnDescNew === '') $vnDescNew = $this->generateDescription('vn', $stationName, $vnNew, $posterCategory);

            $needRu = ($ruTitle !== $ruNew) || ($ruDesc !== $ruDescNew) || ($ruSub !== '');
            $needEn = ($enTitle !== $enNew) || ($enDesc !== $enDescNew) || ($enSub !== '');
            $needVn = ($vnTitle !== $vnNew) || ($vnDesc !== $vnDescNew) || ($vnSub !== '');

            if ($needRu) {
                $this->db->query(
                    "UPDATE {$miRu} SET title=?, sub_category=NULL, description=? WHERE poster_item_id=?",
                    [$ruNew !== '' ? $ruNew : null, $ruDescNew !== '' ? $ruDescNew : null, $posterItemId]
                );
            }
            if ($needEn) {
                $this->db->query(
                    "UPDATE {$miEn} SET title=?, sub_category=NULL, description=? WHERE poster_item_id=?",
                    [$enNew !== '' ? $enNew : null, $enDescNew !== '' ? $enDescNew : null, $posterItemId]
                );
            }
            if ($needVn) {
                $this->db->query(
                    "UPDATE {$miVn} SET title=?, sub_category=NULL, description=? WHERE poster_item_id=?",
                    [$vnNew !== '' ? $vnNew : null, $vnDescNew !== '' ? $vnDescNew : null, $posterItemId]
                );
            }

            if ($needRu || $needEn || $needVn) {
                $updated++;
            }
            if ($ruTitle === '' && $ruNew !== '' || $enTitle === '' && $enNew !== '' || $vnTitle === '' && $vnNew !== '') {
                $titlesFilled++;
            }
            if ($ruDesc === '' && $ruDescNew !== '' || $enDesc === '' && $enDescNew !== '' || $vnDesc === '' && $vnDescNew !== '') {
                $descriptionsFilled++;
            }
            if ($ruSub !== '' || $enSub !== '' || $vnSub !== '') {
                $subCategoriesFilled++;
            }
        }

        return [
            'ok' => true,
            'rows' => count($rows),
            'updated' => $updated,
            'titles_filled' => $titlesFilled,
            'subcategories_filled' => $subCategoriesFilled,
            'descriptions_filled' => $descriptionsFilled
        ];
    }

    private function splitRuEn(string $raw): array {
        $s = trim($this->stripLeadingNumberingExcept7up($raw));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        $split2 = function (string $left, string $right): array {
            $left = trim(preg_replace('/^\s*[\|\/]+\s*/u', '', $left) ?? $left);
            $right = trim(preg_replace('/^\s*[\|\/]+\s*/u', '', $right) ?? $right);
            $left = trim(preg_replace('/\s*[\|\/]+\s*$/u', '', $left) ?? $left);
            $right = trim(preg_replace('/\s*[\|\/]+\s*$/u', '', $right) ?? $right);
            $left = trim(preg_replace('/^\s*\.\s*/u', '', $left) ?? $left);
            $right = trim(preg_replace('/^\s*\.\s*/u', '', $right) ?? $right);
            return ['ru' => $left !== '' ? $left : $right, 'en' => $right !== '' ? $right : $left];
        };

        if (strpos($s, '|') !== false) {
            $parts = explode('|', $s, 2);
            $left = trim((string)($parts[0] ?? ''));
            $right = trim((string)($parts[1] ?? ''));
            if ($left !== '' && $right !== '') {
                return $split2($left, $right);
            }
        }

        if (strpos($s, '/') !== false) {
            $parts = explode('/', $s, 2);
            $left = trim((string)($parts[0] ?? ''));
            $right = trim((string)($parts[1] ?? ''));
            $leftHasRu = (bool)preg_match('/\p{Cyrillic}/u', $left);
            $rightHasEn = (bool)preg_match('/[A-Za-z]/', $right);
            if ($left !== '' && $right !== '' && $leftHasRu && $rightHasEn) {
                return $split2($left, $right);
            }
        }

        $hasRu = (bool)preg_match('/\p{Cyrillic}/u', $s);
        $hasEn = (bool)preg_match('/[A-Za-z]/', $s);
        if ($hasRu && $hasEn) {
            $parts = preg_split('/\s+/u', $s) ?: [];
            $ruParts = [];
            $enParts = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $isRu = (bool)preg_match('/\p{Cyrillic}/u', $p);
                $isEn = (bool)preg_match('/[A-Za-z]/', $p);
                if ($isRu && !$isEn) {
                    $ruParts[] = $p;
                } elseif ($isEn && !$isRu) {
                    $enParts[] = $p;
                } else {
                    $ruParts[] = $p;
                    $enParts[] = $p;
                }
            }
            $ru = trim(implode(' ', $ruParts));
            $en = trim(implode(' ', $enParts));
            if ($ru !== '' && $en !== '') {
                return ['ru' => $ru, 'en' => $en];
            }
        }

        return ['ru' => $s, 'en' => $s];
    }

    private function stripLeadingNumberingExcept7up(string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        if (preg_match('/^\s*7\s*up\b/i', $s)) {
            return preg_replace('/^\s*7\s*up\b/i', '7UP', $s) ?? $s;
        }
        $s2 = preg_replace('/^\s*[\d\.,]+(?:\s+)?/u', '', $s);
        $out = trim($s2 ?? $s);
        $out2 = preg_replace('/^\s*\.\s*/u', '', $out);
        return trim($out2 ?? $out);
    }

    private function stripCategoryNumbering(string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        $s2 = preg_replace('/^\s*\d+\s*[\.\)]\s*/u', '', $s);
        if ($s2 === null) $s2 = $s;
        return trim($s2);
    }

    private function norm(string $s): string {
        $s = $this->stripLeadingNumberingExcept7up($s);
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    private function getBarTranslations(): array {
        return [
            ['category' => 'Coffee', 'ru_title' => 'Эспрессо', 'en_title' => 'Espresso', 'vn_title' => 'Cà phê Espresso', 'ru_description' => 'насыщенный чёрный кофе, крепкий шот.', 'en_description' => 'Rich, intense black coffee shot.', 'vn_description' => 'Cà phê đen đậm, vị mạnh và thơm.'],
            ['category' => 'Coffee', 'ru_title' => 'Матча‑латте', 'en_title' => 'Matcha Latte', 'vn_title' => 'Matcha Latte', 'ru_description' => 'японский зелёный чай матча с молоком.', 'en_description' => 'Japanese matcha green tea with milk.', 'vn_description' => 'Trà xanh matcha Nhật Bản pha với sữa.'],
            ['category' => 'Coffee', 'ru_title' => 'Эспрессо‑тоник', 'en_title' => 'Espresso Tonic', 'vn_title' => 'Espresso Tonic', 'ru_description' => 'охлаждённый эспрессо с тоником и льдом.', 'en_description' => 'Chilled espresso with tonic and ice.', 'vn_description' => 'Espresso ướp lạnh, pha cùng tonic và đá.'],
            ['category' => 'Coffee', 'ru_title' => 'Бак сю', 'en_title' => 'Bac Xiu', 'vn_title' => 'Bạc Xỉu', 'ru_description' => 'вьетнамский кофе с молоком и льдом.', 'en_description' => 'Vietnamese coffee with lots of milk on ice.', 'vn_description' => 'Cà phê sữa đá béo, nhiều sữa, uống với đá.'],
            ['category' => 'Coffee', 'ru_title' => 'Кафе суа да', 'en_title' => 'Ca Phe Sua Da', 'vn_title' => 'Cà phê sữa đá', 'ru_description' => 'крепкий вьетнамский кофе со сгущёнкой и льдом.', 'en_description' => 'Strong Vietnamese coffee with condensed milk.', 'vn_description' => 'Cà phê Việt Nam đậm, pha sữa đặc và đá.'],
            ['category' => 'Coffee', 'ru_title' => 'Какао', 'en_title' => 'Cocoa', 'vn_title' => 'Ca cao', 'ru_description' => 'горячий шоколадный напиток на молоке.', 'en_description' => 'Hot chocolate drink with milk.', 'vn_description' => 'Đồ uống ca cao nóng, pha với sữa.'],
            ['category' => 'Coffee', 'ru_title' => 'Американо', 'en_title' => 'Americano', 'vn_title' => 'Americano', 'ru_description' => 'мягкий чёрный кофе, эспрессо с водой.', 'en_description' => 'Smooth black coffee made with espresso and hot water.', 'vn_description' => 'Cà phê đen dịu, pha loãng từ espresso.'],
            ['category' => 'Coffee', 'ru_title' => 'Капучино', 'en_title' => 'Cappuccino', 'vn_title' => 'Cappuccino', 'ru_description' => 'эспрессо с молоком и густой пеной.', 'en_description' => 'Espresso with steamed milk and thick foam.', 'vn_description' => 'Espresso với sữa nóng và lớp bọt dày.'],
            ['category' => 'Coffee', 'ru_title' => 'Солёный кофе', 'en_title' => 'Salted Coffee', 'vn_title' => 'Cà phê muối', 'ru_description' => 'кофе с нежной солёной молочной пенкой.', 'en_description' => 'Coffee topped with a light salted milk foam.', 'vn_description' => 'Cà phê với lớp kem sữa mặn nhẹ bên trên.'],
            ['category' => 'Coffee', 'ru_title' => 'Капучино XL', 'en_title' => 'Cappuccino XL', 'vn_title' => 'Cappuccino XL', 'ru_description' => 'увеличенный капучино для любителей побольше.', 'en_description' => 'Large cappuccino for serious coffee lovers.', 'vn_description' => 'Ly cappuccino cỡ lớn dành cho người mê cà phê.'],
            ['category' => 'Coffee', 'ru_title' => 'Флэт уайт', 'en_title' => 'Flat White', 'vn_title' => 'Flat White', 'ru_description' => 'крепкий кофе с молоком, меньше пены.', 'en_description' => 'Strong coffee with velvety steamed milk, little foam.', 'vn_description' => 'Cà phê đậm với sữa nóng, ít bọt.'],
            ['category' => 'Coffee', 'ru_title' => 'Латте', 'en_title' => 'Latte', 'vn_title' => 'Latte', 'ru_description' => 'мягкий молочный кофе, много молока.', 'en_description' => 'Mild coffee with plenty of hot milk.', 'vn_description' => 'Cà phê sữa nóng, vị nhẹ, nhiều sữa.'],
            ['category' => 'Coffee', 'ru_title' => 'Бамбл‑кофе', 'en_title' => 'Bumble Coffee', 'vn_title' => 'Bumble Coffee', 'ru_description' => 'слоистый кофе с соком и льдом.', 'en_description' => 'Layered iced coffee with juice and espresso.', 'vn_description' => 'Cà phê nhiều tầng với nước trái cây và đá.'],
            ['category' => 'Coffee', 'ru_title' => 'Свит эспрессо крем', 'en_title' => 'Sweet Espresso Cream', 'vn_title' => 'Sweet Espresso Cream', 'ru_description' => 'десертный кофейный напиток с кремом.', 'en_description' => 'Dessert-style espresso drink with creamy foam.', 'vn_description' => 'Đồ uống espresso kiểu tráng miệng với lớp kem mịn.'],
            ['category' => 'Tea', 'ru_title' => 'Чёрный чай', 'en_title' => 'Black Tea', 'vn_title' => 'Trà đen', 'ru_description' => 'классический листовой чай без добавок.', 'en_description' => 'Classic black tea, clean and aromatic.', 'vn_description' => 'Trà đen cổ điển, hương thơm rõ.'],
            ['category' => 'Tea', 'ru_title' => 'Жасминовый чай', 'en_title' => 'Jasmine Tea', 'vn_title' => 'Trà hoa nhài', 'ru_description' => 'зелёный чай с ароматом жасмина.', 'en_description' => 'Green tea scented with jasmine flowers.', 'vn_description' => 'Trà xanh ướp hương hoa nhài.'],
            ['category' => 'Tea', 'ru_title' => 'Чай улун', 'en_title' => 'Oolong Tea', 'vn_title' => 'Trà Ô Long', 'ru_description' => 'лёгкий цветочный улун средней крепости.', 'en_description' => 'Light, floral semi‑fermented oolong tea.', 'vn_description' => 'Trà Ô Long nhẹ, hương hoa dìu dịu.'],
            ['category' => 'Tea', 'ru_title' => 'Каркаде‑мята', 'en_title' => 'Hibiscus Mint Tea', 'vn_title' => 'Trà Hibiscus bạc hà', 'ru_description' => 'кислый гибискус с освежающей мятой.', 'en_description' => 'Tangy hibiscus tea with refreshing mint.', 'vn_description' => 'Trà hibiscus chua nhẹ, thêm bạc hà mát.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Апельсиновый фреш', 'en_title' => 'Orange Fresh Juice', 'vn_title' => 'Nước cam ép', 'ru_description' => 'свежевыжатый апельсиновый сок.', 'en_description' => 'Freshly squeezed orange juice.', 'vn_description' => 'Nước cam vắt tươi, mát lạnh.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Манговый фреш', 'en_title' => 'Mango Fresh Juice', 'vn_title' => 'Nước xoài ép', 'ru_description' => 'свежевыжатый сок спелого манго.', 'en_description' => 'Freshly pressed ripe mango juice.', 'vn_description' => 'Nước xoài ép từ xoài chín.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Арбузный фреш', 'en_title' => 'Watermelon Fresh Juice', 'vn_title' => 'Nước ép dưa hấu', 'ru_description' => 'освежающий сок сочного арбуза.', 'en_description' => 'Refreshing juice from sweet watermelon.', 'vn_description' => 'Nước ép dưa hấu tươi, vị ngọt mát.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Фреш‑микс', 'en_title' => 'Mixed Fresh Juice', 'vn_title' => 'Nước ép trái cây mix', 'ru_description' => 'ассорти свежевыжатых фруктовых соков.', 'en_description' => 'Blend of assorted fresh fruit juices.', 'vn_description' => 'Nước ép tổng hợp nhiều loại trái cây.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Манго‑смузи', 'en_title' => 'Mango Smoothie', 'vn_title' => 'Sinh tố xoài', 'ru_description' => 'густой тропический смузи из манго.', 'en_description' => 'Thick tropical smoothie made with mango.', 'vn_description' => 'Sinh tố xoài sánh mịn, đậm vị nhiệt đới.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Банановый смузи', 'en_title' => 'Banana Smoothie', 'vn_title' => 'Sinh tố chuối', 'ru_description' => 'банановый смузи на молочной основе.', 'en_description' => 'Creamy banana smoothie on a milk base.', 'vn_description' => 'Sinh tố chuối béo, pha nền sữa.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Ананасовый смузи', 'en_title' => 'Pineapple Smoothie', 'vn_title' => 'Sinh tố dứa', 'ru_description' => 'освежающий ананасовый смузи со льдом.', 'en_description' => 'Refreshing pineapple smoothie with ice.', 'vn_description' => 'Sinh tố dứa tươi, chua ngọt và mát.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Смузи‑микс', 'en_title' => 'Mixed Smoothie', 'vn_title' => 'Sinh tố trái cây mix', 'ru_description' => 'фруктовый смузи из нескольких фруктов.', 'en_description' => 'Mixed fruit smoothie with seasonal fruits.', 'vn_description' => 'Sinh tố tổng hợp từ nhiều loại trái cây.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Милкшейк Орео', 'en_title' => 'Oreo Milkshake', 'vn_title' => 'Sinh tố Oreo', 'ru_description' => 'молочный коктейль с печеньем Oreo.', 'en_description' => 'Milkshake blended with Oreo cookies.', 'vn_description' => 'Sữa lắc với bánh Oreo xay nhuyễn.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Клубничный милкшейк', 'en_title' => 'Strawberry Milkshake', 'vn_title' => 'Sinh tố dâu', 'ru_description' => 'молочный коктейль со свежей клубникой.', 'en_description' => 'Creamy milkshake with fresh strawberries.', 'vn_description' => 'Sữa lắc dâu tây tươi, vị chua ngọt.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Банановый милкшейк', 'en_title' => 'Banana Milkshake', 'vn_title' => 'Sinh tố chuối sữa', 'ru_description' => 'сливочный коктейль с бананом.', 'en_description' => 'Smooth milkshake with ripe banana.', 'vn_description' => 'Sữa lắc chuối thơm, béo nhẹ.'],
            ['category' => 'Fruits&Milks', 'ru_title' => 'Манговый милкшейк', 'en_title' => 'Mango Milkshake', 'vn_title' => 'Sinh tố xoài sữa', 'ru_description' => 'молочный коктейль с манго и льдом.', 'en_description' => 'Milkshake with mango and crushed ice.', 'vn_description' => 'Sữa lắc xoài mát, xay cùng đá.'],
            ['category' => 'Mocktails', 'ru_title' => 'Манго‑маракуйя', 'en_title' => 'Mango Passion Mocktail', 'vn_title' => 'Mocktail Xoài Chanh leo', 'ru_description' => 'безалкогольный коктейль манго–маракуйя.', 'en_description' => 'Non‑alcoholic mango and passion fruit cocktail.', 'vn_description' => 'Mocktail xoài – chanh leo, không cồn.'],
            ['category' => 'Mocktails', 'ru_title' => 'Лайм‑сода', 'en_title' => 'Lime Soda', 'vn_title' => 'Soda chanh', 'ru_description' => 'газированный лаймовый напиток со льдом.', 'en_description' => 'Sparkling lime drink served over ice.', 'vn_description' => 'Đồ uống soda chanh tươi với đá.'],
            ['category' => 'Mocktails', 'ru_title' => 'Персик‑мята', 'en_title' => 'Peach Mint', 'vn_title' => 'Đào bạc hà', 'ru_description' => 'прохладительный напиток с персиком и мятой.', 'en_description' => 'Refreshing peach drink with fresh mint.', 'vn_description' => 'Đồ uống đào kết hợp lá bạc hà mát.'],
            ['category' => 'Mocktails', 'ru_title' => 'Мохито безалкогольный', 'en_title' => 'Virgin Mojito', 'vn_title' => 'Mojito không cồn', 'ru_description' => 'классический мятно‑лаймовый мохито без алкоголя.', 'en_description' => 'Classic mint and lime mojito without alcohol.', 'vn_description' => 'Mojito không cồn với chanh và bạc hà tươi.'],
            ['category' => 'Mocktails', 'ru_title' => 'Лимонад «Маракуйя‑базилик»', 'en_title' => 'Passion Basil Lemonade', 'vn_title' => 'Passion Basil Lemonade', 'ru_description' => 'лимонад с маракуйей и базиликом.', 'en_description' => 'House lemonade with passion fruit and basil.', 'vn_description' => 'Nước chanh tươi với chanh leo và húng quế.'],
            ['category' => 'Mocktails', 'ru_title' => 'Манго‑чили', 'en_title' => 'Mango Chili', 'vn_title' => 'Mango Chili', 'ru_description' => 'тропический напиток манго с лёгкой остротой.', 'en_description' => 'Tropical mango drink with a gentle chili kick.', 'vn_description' => 'Đồ uống xoài nhiệt đới, cay nhẹ vị ớt.'],
            ['category' => 'Mocktails', 'ru_title' => 'Кокос‑ваниль фриз', 'en_title' => 'Coconut Vanilla Fizz', 'vn_title' => 'Coconut Vanilla Fizz', 'ru_description' => 'кокосово‑ванильный газированный напиток.', 'en_description' => 'Sparkling coconut and vanilla refresher.', 'vn_description' => 'Đồ uống có gas vị dừa và vani.'],
            ['category' => 'Mocktails', 'ru_title' => 'Арбуз‑соль', 'en_title' => 'Watermelon Salt', 'vn_title' => 'Watermelon Salt', 'ru_description' => 'арбузный лимонад с щепоткой соли.', 'en_description' => 'Watermelon lemonade with a touch of salt.', 'vn_description' => 'Nước chanh dưa hấu với chút muối biển.'],
            ['category' => 'Mocktails', 'ru_title' => 'Питахайя‑роза', 'en_title' => 'Dragonfruit Rose', 'vn_title' => 'Dragonfruit Rose', 'ru_description' => 'напиток из драгонфрута с лёгким ароматом розы.', 'en_description' => 'Dragonfruit drink with a hint of rose.', 'vn_description' => 'Đồ uống thanh long với hương hoa hồng nhẹ.'],
            ['category' => 'Mocktails', 'ru_title' => 'Тамаринд‑цитрус', 'en_title' => 'Tamarind Citrus', 'vn_title' => 'Tamarind Citrus', 'ru_description' => 'кисло‑сладкий тамариндовый цитрусовый напиток.', 'en_description' => 'Sweet‑sour tamarind and citrus refresher.', 'vn_description' => 'Thức uống me chua ngọt kết hợp trái cây họ cam.'],
            ['category' => 'Mocktails', 'ru_title' => 'Холодный чай юдзу', 'en_title' => 'Iced Yuzu Tea', 'vn_title' => 'Trà Yuzu đá', 'ru_description' => 'чай с цитрусом юдзу и льдом.', 'en_description' => 'Iced tea flavored with Japanese yuzu.', 'vn_description' => 'Trà đá hương yuzu Nhật Bản.'],
            ['category' => 'Cocktails', 'ru_title' => 'Мохито', 'en_title' => 'Mojito', 'vn_title' => 'Mojito', 'ru_description' => 'ром, лайм, мята и газированная вода.', 'en_description' => 'Rum cocktail with lime, mint and soda.', 'vn_description' => 'Cocktail rum với chanh, bạc hà và soda.'],
            ['category' => 'Cocktails', 'ru_title' => 'Дайкири', 'en_title' => 'Daiquiri', 'vn_title' => 'Daiquiri', 'ru_description' => 'ромовый коктейль с лаймом, подаётся охлаждённым.', 'en_description' => 'Chilled rum cocktail shaken with lime.', 'vn_description' => 'Cocktail rum lắc cùng nước chanh tươi.'],
            ['category' => 'Cocktails', 'ru_title' => 'Виски‑сауэр', 'en_title' => 'Whisky Sour', 'vn_title' => 'Whisky Sour', 'ru_description' => 'виски, лимон и сахар, классический sour.', 'en_description' => 'Whisky cocktail with lemon and sugar.', 'vn_description' => 'Cocktail whisky với chanh và đường.'],
            ['category' => 'Cocktails', 'ru_title' => 'Палома', 'en_title' => 'Paloma', 'vn_title' => 'Paloma', 'ru_description' => 'текила с грейпфрутовой содой и лаймом.', 'en_description' => 'Tequila highball with grapefruit soda and lime.', 'vn_description' => 'Cocktail tequila với soda bưởi và chanh.'],
            ['category' => 'Cocktails', 'ru_title' => 'Негрони', 'en_title' => 'Negroni', 'vn_title' => 'Negroni', 'ru_description' => 'джин, вермут и биттер, горько‑сладкий коктейль.', 'en_description' => 'Bitter‑sweet mix of gin, vermouth and bitters.', 'vn_description' => 'Cocktail gin, vermouth và bitters, vị đắng ngọt.'],
            ['category' => 'Cocktails', 'ru_title' => 'Эспрессо‑мартини', 'en_title' => 'Espresso Martini', 'vn_title' => 'Espresso Martini', 'ru_description' => 'водка, кофейный ликёр и эспрессо.', 'en_description' => 'Vodka cocktail with coffee liqueur and espresso.', 'vn_description' => 'Cocktail vodka với rượu cà phê và espresso.'],
            ['category' => 'Cocktails', 'ru_title' => 'Маргарита', 'en_title' => 'Margarita', 'vn_title' => 'Margarita', 'ru_description' => 'текила, лайм и цитрусовый ликёр.', 'en_description' => 'Tequila cocktail with lime and orange liqueur.', 'vn_description' => 'Cocktail tequila với chanh và rượu cam.'],
            ['category' => 'Cocktails', 'ru_title' => 'Апероль шприц', 'en_title' => 'Aperol Spritz', 'vn_title' => 'Aperol Spritz', 'ru_description' => 'игристое, апероль и сода, лёгкий аперитив.', 'en_description' => 'Light sparkling cocktail with Aperol and prosecco.', 'vn_description' => 'Cocktail Aperol nhẹ, pha rượu vang sủi và soda.'],
            ['category' => 'Cocktails', 'ru_title' => 'Лонг‑Айленд', 'en_title' => 'Long Island Iced Tea', 'vn_title' => 'Long Island Iced Tea', 'ru_description' => 'крепкий коктейль из нескольких видов алкоголя.', 'en_description' => 'Strong mixed drink with several spirits and cola.', 'vn_description' => 'Cocktail mạnh pha nhiều loại rượu và cola.'],
            ['category' => 'Cocktails', 'ru_title' => 'Маргарита с маракуйей', 'en_title' => 'Passion Fruit Margarita', 'vn_title' => 'Passion Fruit Margarita', 'ru_description' => 'фруктовый вариант маргариты с маракуйей.', 'en_description' => 'Classic margarita with added passion fruit.', 'vn_description' => 'Phiên bản margarita thêm hương chanh leo.'],
            ['category' => 'Cocktails', 'ru_title' => 'Манго‑дайкири', 'en_title' => 'Mango Daiquiri', 'vn_title' => 'Mango Daiquiri', 'ru_description' => 'дайкири с манго и льдом.', 'en_description' => 'Frozen rum cocktail blended with mango.', 'vn_description' => 'Daiquiri xoài xay cùng đá lạnh.'],
            ['category' => 'Cocktails', 'ru_title' => 'Джин‑физз', 'en_title' => 'Gin Fizz', 'vn_title' => 'Gin Fizz', 'ru_description' => 'джин с лимоном и газированной водой.', 'en_description' => 'Gin shaken with lemon and topped with soda.', 'vn_description' => 'Cocktail gin với nước chanh và soda.'],
            ['category' => 'Cocktails', 'ru_title' => 'Солёный пёс', 'en_title' => 'Salted Dog', 'vn_title' => 'Salted Dog', 'ru_description' => 'коктейль с грейпфрутом и солёным краем бокала.', 'en_description' => 'Grapefruit cocktail served with a salted rim.', 'vn_description' => 'Cocktail bưởi với viền muối trên miệng ly.'],
            ['category' => 'Cocktails', 'ru_title' => 'Виски‑кола', 'en_title' => 'Whisky Cola', 'vn_title' => 'Whisky Cola', 'ru_description' => 'классический коктейль виски с колой.', 'en_description' => 'Simple mix of whisky and cola over ice.', 'vn_description' => 'Whisky pha cùng cola và đá.'],
            ['category' => 'Cocktails', 'ru_title' => 'Джин‑тоник', 'en_title' => 'Gin Tonic', 'vn_title' => 'Gin Tonic', 'ru_description' => 'джин с тоником и лаймом.', 'en_description' => 'Gin and tonic water with lime.', 'vn_description' => 'Gin pha với tonic và lát chanh.'],
            ['category' => 'Cocktails', 'ru_title' => 'Ром‑кола', 'en_title' => 'Rum Cola', 'vn_title' => 'Rum Cola', 'ru_description' => 'ром с колой и льдом.', 'en_description' => 'Rum and cola served on ice.', 'vn_description' => 'Rum pha với cola, uống cùng đá.'],
            ['category' => 'Strong alcohol', 'ru_title' => 'Джин', 'en_title' => 'Gin', 'vn_title' => 'Gin', 'ru_description' => 'крепкий алкоголь на можжевельнике.', 'en_description' => 'Juniper‑forward distilled spirit.', 'vn_description' => 'Rượu mạnh chưng cất từ quả bách xù.'],
            ['category' => 'Strong alcohol', 'ru_title' => 'Текила', 'en_title' => 'Tequila', 'vn_title' => 'Tequila', 'ru_description' => 'крепкий напиток из голубой агавы.', 'en_description' => 'Agave‑based Mexican spirit.', 'vn_description' => 'Rượu mạnh Mexico làm từ cây thùa xanh.'],
            ['category' => 'Strong alcohol', 'ru_title' => 'Виски', 'en_title' => 'Whisky', 'vn_title' => 'Whisky', 'ru_description' => 'выдержанный зерновой алкоголь.', 'en_description' => 'Aged grain spirit with oak character.', 'vn_description' => 'Rượu mạnh ủ từ ngũ cốc, hương gỗ sồi.'],
            ['category' => 'Strong alcohol', 'ru_title' => 'Водка', 'en_title' => 'Vodka', 'vn_title' => 'Vodka', 'ru_description' => 'классический прозрачный крепкий дистиллят.', 'en_description' => 'Clear neutral distilled spirit.', 'vn_description' => 'Rượu trong suốt, vị trung tính, nồng độ cao.'],
            ['category' => 'Strong alcohol', 'ru_title' => 'Ром', 'en_title' => 'Rum', 'vn_title' => 'Rum', 'ru_description' => 'тростниковый алкоголь с карамельными нотами.', 'en_description' => 'Sugarcane‑based spirit with caramel notes.', 'vn_description' => 'Rượu làm từ mía đường, hương caramel nhẹ.'],
            ['category' => 'Strong alcohol', 'ru_title' => 'Абсент', 'en_title' => 'Absinthe', 'vn_title' => 'Absinthe', 'ru_description' => 'анисово‑травяной крепкий ликёр.', 'en_description' => 'Herbal anise spirit, very strong.', 'vn_description' => 'Rượu mùi thảo mộc, vị hồi, nồng độ cao.'],
            ['category' => 'Beer', 'ru_title' => 'Разливное пиво', 'en_title' => 'Draft Beer', 'vn_title' => 'Bia tươi', 'ru_description' => 'свежее пиво из крана.', 'en_description' => 'Fresh draft beer from the tap.', 'vn_description' => 'Bia tươi rót trực tiếp từ vòi.'],
            ['category' => 'Beer', 'ru_title' => 'Saigon', 'en_title' => 'Saigon Can', 'vn_title' => 'Bia Saigon lon', 'ru_description' => 'светлое пиво Saigon в банке.', 'en_description' => 'Vietnamese Saigon lager in a can.', 'vn_description' => 'Bia Saigon lon, vị lager nhẹ.'],
            ['category' => 'Beer', 'ru_title' => 'Bivina', 'en_title' => 'Bivina Can', 'vn_title' => 'Bia Bivina lon', 'ru_description' => 'лёгкое пиво Bivina в банке.', 'en_description' => 'Light Bivina lager in a can.', 'vn_description' => 'Bia Bivina lon, lager nhẹ, dễ uống.'],
            ['category' => 'Beer', 'ru_title' => 'Heineken Silver', 'en_title' => 'Heineken Silver', 'vn_title' => 'Heineken Silver', 'ru_description' => 'лёгкое пиво Heineken Silver.', 'en_description' => 'Crisp, easy‑drinking Heineken lager.', 'vn_description' => 'Bia Heineken Silver, mát và dễ uống.'],
            ['category' => 'Beer', 'ru_title' => 'Tiger Crystal', 'en_title' => 'Tiger Crystal', 'vn_title' => 'Tiger Crystal', 'ru_description' => 'освежающий светлый лагер.', 'en_description' => 'Light, refreshing Tiger Crystal lager.', 'vn_description' => 'Bia Tiger Crystal, lager nhẹ, sảng khoái.'],
            ['category' => 'Beer', 'ru_title' => 'IPA', 'en_title' => 'IPA', 'vn_title' => 'Bia IPA', 'ru_description' => 'более хмелевой, ароматный сорт пива.', 'en_description' => 'Hoppy, aromatic India Pale Ale.', 'vn_description' => 'Bia IPA, vị đắng hổi và thơm hoa bia.'],
            ['category' => 'Beer', 'ru_title' => 'Башня пива', 'en_title' => 'Beer Tower', 'vn_title' => 'Tháp bia', 'ru_description' => 'большая башня пива для компании.', 'en_description' => 'Tall sharing tower filled with draft beer.', 'vn_description' => 'Tháp bia lớn dành cho nhiều người.'],
            ['category' => 'Beer', 'ru_title' => 'Heineken безалкогольный', 'en_title' => 'Heineken Non‑Alcoholic', 'vn_title' => 'Heineken Không Cồn', 'ru_description' => 'безалкогольное пиво Heineken.', 'en_description' => 'Non‑alcoholic Heineken lager.', 'vn_description' => 'Bia Heineken không cồn.'],
            ['category' => 'Wine', 'ru_title' => 'Белое вино', 'en_title' => 'White Wine', 'vn_title' => 'Rượu vang trắng', 'ru_description' => 'охлаждённое сухое или полусухое белое вино.', 'en_description' => 'Chilled dry or semi‑dry white wine.', 'vn_description' => 'Rượu vang trắng khô hoặc hơi ngọt, dùng lạnh.'],
            ['category' => 'Wine', 'ru_title' => 'Белое вино (бутылка)', 'en_title' => 'White Wine Bottle', 'vn_title' => 'Rượu vang trắng chai', 'ru_description' => 'бутылка белого вина.', 'en_description' => 'Full bottle of white wine.', 'vn_description' => 'Nguyên chai rượu vang trắng.'],
            ['category' => 'Wine', 'ru_title' => 'Красное вино', 'en_title' => 'Red Wine', 'vn_title' => 'Rượu vang đỏ', 'ru_description' => 'красное сухое или полусухое вино.', 'en_description' => 'Dry or semi‑dry red wine.', 'vn_description' => 'Rượu vang đỏ khô hoặc hơi ngọt.'],
            ['category' => 'Wine', 'ru_title' => 'Красное вино (бутылка)', 'en_title' => 'Red Wine Bottle', 'vn_title' => 'Rượu vang đỏ chai', 'ru_description' => 'бутылка красного вина.', 'en_description' => 'Full bottle of red wine.', 'vn_description' => 'Nguyên chai rượu vang đỏ.'],
            ['category' => 'Wine', 'ru_title' => 'Игристое вино', 'en_title' => 'Sparkling Wine', 'vn_title' => 'Rượu vang sủi bọt', 'ru_description' => 'лёгкое шампанское или другое игристое.', 'en_description' => 'Chilled sparkling wine or champagne.', 'vn_description' => 'Rượu vang sủi, dùng làm khai vị.'],
            ['category' => 'Wine', 'ru_title' => 'Игристое вино (бутылка)', 'en_title' => 'Sparkling Wine Bottle', 'vn_title' => 'Rượu vang sủi bọt chai', 'ru_description' => 'бутылка игристого вина.', 'en_description' => 'Full bottle of sparkling wine.', 'vn_description' => 'Nguyên chai rượu vang sủi.'],
            ['category' => 'Shots', 'ru_title' => 'Самбука', 'en_title' => 'Sambuca', 'vn_title' => 'Sambuca', 'ru_description' => 'сладкий анисовый ликёр.', 'en_description' => 'Sweet anise‑flavored liqueur.', 'vn_description' => 'Rượu mùi ngọt, hương hồi mạnh.'],
            ['category' => 'Shots', 'ru_title' => 'Бейлиз', 'en_title' => 'Baileys', 'vn_title' => 'Baileys', 'ru_description' => 'сливочный ликёр на основе виски.', 'en_description' => 'Creamy Irish liqueur with whisky.', 'vn_description' => 'Rượu kem Baileys, vị sữa và whisky.'],
            ['category' => 'Shots', 'ru_title' => 'Лимончелло', 'en_title' => 'Limoncello', 'vn_title' => 'Limoncello', 'ru_description' => 'сладкий лимонный итальянский ликёр.', 'en_description' => 'Sweet Italian lemon liqueur.', 'vn_description' => 'Rượu mùi chanh vàng kiểu Ý, vị ngọt.'],
            ['category' => 'Shots', 'ru_title' => 'Банановый ликёр', 'en_title' => 'Banana Liqueur', 'vn_title' => 'Rượu mùi chuối', 'ru_description' => 'ликёр со вкусом спелого банана.', 'en_description' => 'Sweet liqueur with ripe banana flavor.', 'vn_description' => 'Rượu mùi vị chuối chín, ngọt thơm.'],
            ['category' => 'Shots', 'ru_title' => 'Калуа', 'en_title' => 'Kahlua', 'vn_title' => 'Kahlua', 'ru_description' => 'кофейный ликёр с мягкой сладостью.', 'en_description' => 'Coffee‑flavored liqueur with gentle sweetness.', 'vn_description' => 'Rượu mùi cà phê, ngọt dịu.'],
            ['category' => 'Shots', 'ru_title' => 'Куантро', 'en_title' => 'Cointreau', 'vn_title' => 'Cointreau', 'ru_description' => 'апельсиновый цитрусовый ликёр.', 'en_description' => 'Orange‑flavored citrus liqueur.', 'vn_description' => 'Rượu mùi cam, hương cam quýt đậm.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Пепси / Кола', 'en_title' => 'Pepsi / Cola Regular / Zero', 'vn_title' => 'Pepsi / Cola Thường / Zero', 'ru_description' => 'классическая газировка, есть вариант Zero.', 'en_description' => 'Cola soft drink, available Regular or Zero.', 'vn_description' => 'Nước ngọt Cola, có bản thường và Zero.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Комбуча', 'en_title' => 'Kombucha', 'vn_title' => 'Kombucha', 'ru_description' => 'ферментированный чайный напиток разных вкусов.', 'en_description' => 'Lightly sparkling fermented tea drink.', 'vn_description' => 'Đồ uống trà lên men nhẹ, có gas.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Кокосовый напиток', 'en_title' => 'Coconut Drink', 'vn_title' => 'Nước dừa', 'ru_description' => 'напиток на основе кокосовой воды.', 'en_description' => 'Refreshing drink based on coconut water.', 'vn_description' => 'Đồ uống mát từ nước dừa.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Red Bull', 'en_title' => 'Red Bull', 'vn_title' => 'Red Bull', 'ru_description' => 'энергетический газированный напиток.', 'en_description' => 'Carbonated energy drink.', 'vn_description' => 'Nước tăng lực có gas.'],
            ['category' => 'Soft Drinks', 'ru_title' => '7UP', 'en_title' => '7UP', 'vn_title' => '7UP', 'ru_description' => 'лимонно‑лаймовый безалкогольный напиток.', 'en_description' => 'Lemon‑lime flavored soft drink.', 'vn_description' => 'Nước ngọt vị chanh – lime.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Миринда', 'en_title' => 'Mirinda', 'vn_title' => 'Mirinda', 'ru_description' => 'сладкая апельсиновая газировка.', 'en_description' => 'Sweet orange‑flavored soda.', 'vn_description' => 'Nước ngọt vị cam ngọt.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Тоник', 'en_title' => 'Tonic', 'vn_title' => 'Tonic', 'ru_description' => 'горьковатый освежающий напиток с хинином.', 'en_description' => 'Bitter, refreshing tonic water with quinine.', 'vn_description' => 'Nước tonic đắng nhẹ, hương quinine.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Айс-ти', 'en_title' => 'Iced Tea', 'vn_title' => 'Trà đá', 'ru_description' => 'холодный чай со льдом.', 'en_description' => 'Chilled black tea served over ice.', 'vn_description' => 'Trà đen ướp lạnh, uống với đá.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Содовая', 'en_title' => 'Soda Water', 'vn_title' => 'Soda', 'ru_description' => 'газированная вода.', 'en_description' => 'Plain sparkling water.', 'vn_description' => 'Nước khoáng có gas.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Вода', 'en_title' => 'Still Water', 'vn_title' => 'Nước suối', 'ru_description' => 'бутилированная негазированная вода.', 'en_description' => 'Still bottled drinking water.', 'vn_description' => 'Nước suối đóng chai, không gas.'],
            ['category' => 'Soft Drinks', 'ru_title' => 'Квас', 'en_title' => 'Kvass', 'vn_title' => 'Kvass', 'ru_description' => 'традиционный хлебный освежающий напиток.', 'en_description' => 'Traditional fermented bread drink, mildly sweet.', 'vn_description' => 'Đồ uống lên men từ bánh mì, vị ngọt nhẹ.'],
        ];
    }

    private function generateDescription(string $lang, string $station, string $title, string $posterCategory): string {
        $t = trim($title);
        if ($t === '') return '';
        $low = mb_strtolower($t, 'UTF-8');

        $isKitchen = str_contains($station, 'kitchen');
        $isBar = str_contains($station, 'bar');

        $has = function (array $needles) use ($low): bool {
            foreach ($needles as $n) {
                if ($n !== '' && str_contains($low, $n)) return true;
            }
            return false;
        };

        $catLow = mb_strtolower(trim($posterCategory), 'UTF-8');

        if ($isBar) {
            if ($has(['coffee', 'кофе'])) {
                return $lang === 'ru' ? 'Кофейный напиток.' : ($lang === 'vn' ? 'Đồ uống cà phê.' : 'Coffee drink.');
            }
            if ($has(['tea', 'чай'])) {
                return $lang === 'ru' ? 'Ароматный чай.' : ($lang === 'vn' ? 'Trà thơm.' : 'Aromatic tea.');
            }
            if ($has(['beer', 'пиво'])) {
                return $lang === 'ru' ? 'Пиво, подаётся охлаждённым.' : ($lang === 'vn' ? 'Bia dùng lạnh.' : 'Beer served chilled.');
            }
            if ($has(['wine', 'вино'])) {
                return $lang === 'ru' ? 'Вино, подаётся охлаждённым.' : ($lang === 'vn' ? 'Rượu vang dùng lạnh.' : 'Wine served chilled.');
            }
            return $lang === 'ru' ? 'Напиток.' : ($lang === 'vn' ? 'Đồ uống.' : 'Drink.');
        }

        if ($has(['burger', 'бургер'])) {
            return $lang === 'ru' ? 'Сочный бургер на свежей булочке.' : ($lang === 'vn' ? 'Burger ngon với bánh mềm.' : 'Juicy burger on a fresh bun.');
        }
        if ($has(['shawarma', 'шаурм'])) {
            return $lang === 'ru' ? 'Шаурма в лаваше с сочной начинкой.' : ($lang === 'vn' ? 'Shawarma cuốn bánh mì dẹt với nhân đậm vị.' : 'Shawarma wrap with a flavorful filling.');
        }
        if ($has(['salad', 'салат'])) {
            return $lang === 'ru' ? 'Свежий салат с лёгкой заправкой.' : ($lang === 'vn' ? 'Salad tươi với sốt nhẹ.' : 'Fresh salad with a light dressing.');
        }
        if ($has(['soup', 'суп', 'борщ', 'солянк', 'уха', 'okrosh', 'окрош'])) {
            return $lang === 'ru' ? 'Горячий суп по домашнему рецепту.' : ($lang === 'vn' ? 'Món súp nóng theo công thức nhà làm.' : 'Hot soup made in a home-style recipe.');
        }
        if ($has(['pasta', 'паста'])) {
            return $lang === 'ru' ? 'Паста с соусом и ароматными добавками.' : ($lang === 'vn' ? 'Mì Ý với sốt và hương vị đậm đà.' : 'Pasta with sauce and flavorful ingredients.');
        }
        if ($has(['steak', 'стейк'])) {
            return $lang === 'ru' ? 'Стейк, приготовленный до идеальной прожарки.' : ($lang === 'vn' ? 'Bít tết nướng chuẩn vị.' : 'Steak cooked to perfection.');
        }
        if ($has(['fries', 'french fries', 'картош', 'фри', 'hangritos', 'hash brown', 'хешбраун'])) {
            return $lang === 'ru' ? 'Хрустящий гарнир, подаётся горячим.' : ($lang === 'vn' ? 'Món ăn kèm giòn, dùng nóng.' : 'Crispy side served hot.');
        }
        if ($has(['dumpling', 'пельмен', 'вареник'])) {
            return $lang === 'ru' ? 'Домашние пельмени/вареники, подаются горячими.' : ($lang === 'vn' ? 'Bánh pelmeni/vareniki dùng nóng.' : 'Homestyle dumplings served hot.');
        }
        if ($has(['cake', 'торт', 'медовик', 'наполеон', 'брауни', 'dessert', 'десерт', 'ice cream', 'морожен'])) {
            return $lang === 'ru' ? 'Десерт к кофе или чаю.' : ($lang === 'vn' ? 'Món tráng miệng hợp với cà phê hoặc trà.' : 'Dessert perfect with coffee or tea.');
        }

        if ($isKitchen) {
            return $lang === 'ru' ? 'Блюдо кухни Veranda.' : ($lang === 'vn' ? 'Món ăn đặc trưng của Veranda.' : 'Signature Veranda kitchen dish.');
        }

        return $lang === 'ru' ? 'Блюдо.' : ($lang === 'vn' ? 'Món ăn.' : 'Dish.');
    }

    private function getKitchenTranslations(): array {
        return [
            15 => ['ru' => 'Джерки', 'en' => 'Jerky', 'vn' => 'Thịt khô (Jerky)'],
            24 => ['ru' => 'Свиной шашлык', 'en' => 'Pork BBQ', 'vn' => 'Thịt heo nướng'],
            25 => ['ru' => 'Куриный шашлык', 'en' => 'Chicken BBQ', 'vn' => 'Thịt gà nướng'],
            36 => ['ru' => 'Шаурма', 'en' => 'Shawarma', 'vn' => 'Shawarma'],
            87 => ['ru' => 'Шаурма XL', 'en' => 'Shawarma XL', 'vn' => 'Shawarma XL'],
            109 => ['ru' => 'Картошка фри', 'en' => 'French Fries', 'vn' => 'Khoai tây chiên'],
            110 => ['ru' => 'Картошка Хангритос', 'en' => 'Hangritos Potatoes', 'vn' => 'Khoai tây Hangritos'],
            111 => ['ru' => 'Куриные наггетсы', 'en' => 'Chicken Nuggets', 'vn' => 'Gà viên chiên'],
            112 => ['ru' => 'Луковые кольца', 'en' => 'Onion Rings', 'vn' => 'Hành tây chiên vòng'],
            121 => ['ru' => 'Паштет страусиный', 'en' => 'Ostrich Pâté', 'vn' => 'Pate đà điểu'],
            122 => ['ru' => 'Брускетта Наполитано', 'en' => 'Bruschetta Napolitana', 'vn' => 'Bruschetta Napoli'],
            125 => ['ru' => 'Спринг-роллы', 'en' => 'Spring Rolls', 'vn' => 'Chả giò'],
            127 => ['ru' => 'Пивное плато', 'en' => 'Beer Platter', 'vn' => 'Đĩa đồ nhắm bia'],
            128 => ['ru' => 'Салат с курицей', 'en' => 'Chicken Salad', 'vn' => 'Salad gà'],
            129 => ['ru' => 'Креветка в ананасе', 'en' => 'Shrimp in Pineapple', 'vn' => 'Tôm trong dứa'],
            130 => ['ru' => 'Арбуз с фетой', 'en' => 'Watermelon & Feta Salad', 'vn' => 'Salad dưa hấu & phô mai feta'],
            131 => ['ru' => 'Греческий салат', 'en' => 'Greek Salad', 'vn' => 'Salad Hy Lạp'],
            132 => ['ru' => 'Мидии', 'en' => 'Mussels', 'vn' => 'Vẹm'],
            133 => ['ru' => 'Стейк лосося', 'en' => 'Salmon Steak', 'vn' => 'Bít tết cá hồi'],
            134 => ['ru' => 'Стейк тунца', 'en' => 'Tuna Steak', 'vn' => 'Bít tết cá ngừ'],
            135 => ['ru' => 'Медальоны говяжьи', 'en' => 'Beef Medallions', 'vn' => 'Thăn bò medallion'],
            139 => ['ru' => 'Бургер классик', 'en' => 'Classic Burger', 'vn' => 'Burger cổ điển'],
            140 => ['ru' => 'Бургер грибной', 'en' => 'Mushroom Burger', 'vn' => 'Burger nấm'],
            141 => ['ru' => 'Бургер континенталь', 'en' => 'Continental Burger', 'vn' => 'Burger Continental'],
            142 => ['ru' => 'Бургер мега', 'en' => 'Mega Burger', 'vn' => 'Burger cỡ lớn'],
            144 => ['ru' => 'Рикотта боул', 'en' => 'Ricotta Bowl', 'vn' => 'Bowl ricotta'],
            145 => ['ru' => 'Шакшука', 'en' => 'Shakshuka', 'vn' => 'Shakshuka'],
            147 => ['ru' => 'Крокеты рикотта', 'en' => 'Cottage Cheese Croquettes', 'vn' => 'Croquette phô mai tươi'],
            148 => ['ru' => 'Авокадо пашот', 'en' => 'Avocado with Poached Egg', 'vn' => 'Bơ trứng chần'],
            149 => ['ru' => 'Будда боул', 'en' => 'Buddha Bowl', 'vn' => 'Buddha bowl'],
            150 => ['ru' => 'Киноа пашот боул', 'en' => 'Quinoa Poached Egg Bowl', 'vn' => 'Bowl quinoa trứng chần'],
            151 => ['ru' => 'Боул с тунцом', 'en' => 'Tuna Bowl', 'vn' => 'Bowl cá ngừ'],
            152 => ['ru' => 'Креветка манго боул', 'en' => 'Shrimp & Mango Bowl', 'vn' => 'Bowl tôm & xoài'],
            154 => ['ru' => 'Шаурма лаваш помидор сыр', 'en' => 'Shawarma Cheese & Tomato', 'vn' => 'Shawarma phô mai & cà chua'],
            155 => ['ru' => 'Крем-суп грибной', 'en' => 'Mushroom Cream Soup', 'vn' => 'Súp kem nấm'],
            156 => ['ru' => 'Суп куриный с лапшой', 'en' => 'Chicken Noodle Soup', 'vn' => 'Súp gà mì'],
            175 => ['ru' => 'Мороженое с фруктами', 'en' => 'Ice Cream with Fruit', 'vn' => 'Kem với trái cây'],
            200 => ['ru' => 'Медовик', 'en' => 'Honey Cake', 'vn' => 'Bánh mật ong (Medovik)'],
            213 => ['ru' => 'Кабаноси', 'en' => 'Cabanosy', 'vn' => 'Xúc xích Cabanosy'],
            224 => ['ru' => 'Красный бархат', 'en' => 'Red Velvet', 'vn' => 'Bánh Red Velvet'],
            287 => ['ru' => 'Айран', 'en' => 'Ayran', 'vn' => 'Ayran'],
            289 => ['ru' => 'Брауни с мороженым', 'en' => 'Brownie with Ice Cream', 'vn' => 'Bánh brownie với kem'],
            291 => ['ru' => 'Манго шарлотка', 'en' => 'Mango Cake', 'vn' => 'Bánh xoài'],
            293 => ['ru' => 'Хлебная корзина', 'en' => 'Bread Basket', 'vn' => 'Giỏ bánh mì'],
            294 => ['ru' => 'Бекон', 'en' => 'Bacon', 'vn' => 'Thịt xông khói'],
            295 => ['ru' => 'Сыр', 'en' => 'Cheese', 'vn' => 'Phô mai'],
            296 => ['ru' => 'Авокадо', 'en' => 'Avocado', 'vn' => 'Bơ'],
            297 => ['ru' => 'Яйцо', 'en' => 'Egg', 'vn' => 'Trứng'],
            304 => ['ru' => 'Пельмешки', 'en' => 'Dumplings', 'vn' => 'Bánh pelmeni'],
            305 => ['ru' => 'Меню 1 закуски 3 кг', 'en' => 'Menu 1 Snacks (3 kg)', 'vn' => 'Menu 1 đồ nhắm (3 kg)'],
            306 => ['ru' => 'Метр мяса', 'en' => 'BBQ Combo', 'vn' => 'Combo BBQ'],
            307 => ['ru' => 'Хешбраун лосось', 'en' => 'Hash Brown & Salmon', 'vn' => 'Hash brown & cá hồi'],
            309 => ['ru' => 'Английский завтрак', 'en' => 'English Breakfast', 'vn' => 'Bữa sáng kiểu Anh'],
            317 => ['ru' => 'Мини бургер', 'en' => 'Mini Burger', 'vn' => 'Mini burger'],
            330 => ['ru' => 'Макароны с сыром', 'en' => 'Mac & Cheese', 'vn' => 'Mì ống phô mai'],
            343 => ['ru' => 'Салат с курицей (банкет)', 'en' => 'Chicken Salad (Banquet)', 'vn' => 'Salad gà (tiệc)'],
            344 => ['ru' => 'Пельмени жареные', 'en' => 'Fried Dumplings', 'vn' => 'Pelmeni chiên'],
            346 => ['ru' => 'Куриный шашлык (банкет)', 'en' => 'Chicken BBQ (Banquet)', 'vn' => 'Thịt gà nướng (tiệc)'],
            347 => ['ru' => 'Свиной шашлык', 'en' => 'Pork BBQ', 'vn' => 'Thịt heo nướng'],
            349 => ['ru' => 'Шаурма XL (банкет)', 'en' => 'Shawarma XL (Banquet)', 'vn' => 'Shawarma XL (tiệc)'],
            351 => ['ru' => 'Бургер континенталь (банкет)', 'en' => 'Continental Burger (Banquet)', 'vn' => 'Burger Continental (tiệc)'],
            358 => ['ru' => 'Пельмени', 'en' => 'Dumplings', 'vn' => 'Pelmeni'],
            359 => ['ru' => 'Салат с тунцом и киноа', 'en' => 'Tuna & Quinoa Salad', 'vn' => 'Salad cá ngừ & quinoa'],
            360 => ['ru' => 'Борщ', 'en' => 'Borsch', 'vn' => 'Borscht'],
            361 => ['ru' => 'Бефстроганов', 'en' => 'Beef Stroganoff', 'vn' => 'Bò Stroganoff'],
            362 => ['ru' => 'Паста с креветками', 'en' => 'Pasta with Shrimp', 'vn' => 'Mì Ý tôm'],
            363 => ['ru' => 'Шницель', 'en' => 'Schnitzel', 'vn' => 'Schnitzel'],
            365 => ['ru' => 'Уха', 'en' => 'Fish Soup (Ukha)', 'vn' => 'Súp cá Ukha'],
            366 => ['ru' => 'Шаурма говядина', 'en' => 'Beef Shawarma', 'vn' => 'Shawarma bò'],
            367 => ['ru' => 'Бургер Диабло', 'en' => 'Diablo Burger', 'vn' => 'Burger Diablo'],
            369 => ['ru' => 'Брускетта с лососем', 'en' => 'Salmon Bruschetta', 'vn' => 'Bruschetta cá hồi'],
            376 => ['ru' => 'Паштет утиный', 'en' => 'Duck Pâté', 'vn' => 'Pate vịt'],
            382 => ['ru' => 'Гренки чесночные', 'en' => 'Garlic Croutons', 'vn' => 'Bánh mì nướng tỏi'],
            392 => ['ru' => 'Креветка темпура', 'en' => 'Tempura Shrimp', 'vn' => 'Tôm tempura'],
            398 => ['ru' => 'Халапеньо', 'en' => 'Jalapeño', 'vn' => 'Ớt jalapeño'],
            400 => ['ru' => 'Шаурма говядина XL', 'en' => 'Beef Shawarma XL', 'vn' => 'Shawarma bò XL'],
            401 => ['ru' => 'Паста карбонара', 'en' => 'Pasta Carbonara', 'vn' => 'Mì Ý carbonara'],
            402 => ['ru' => 'Сыр слайс', 'en' => 'Cheese Slice', 'vn' => 'Phô mai lát'],
            403 => ['ru' => 'Чесночный соус', 'en' => 'Garlic Sauce', 'vn' => 'Sốt tỏi'],
            404 => ['ru' => 'Кетчуп', 'en' => 'Ketchup', 'vn' => 'Tương cà'],
            405 => ['ru' => 'Грибной соус', 'en' => 'Mushroom Sauce', 'vn' => 'Sốt nấm'],
            406 => ['ru' => 'Хлеб (2 ломтика)', 'en' => 'Bread (2 Slices)', 'vn' => 'Bánh mì (2 lát)'],
            408 => ['ru' => 'Пюре с котлетой', 'en' => 'Mashed Potato with Cutlet', 'vn' => 'Khoai tây nghiền với thịt viên'],
            409 => ['ru' => 'Сметана', 'en' => 'Sour Cream', 'vn' => 'Kem chua'],
            410 => ['ru' => 'Мёд (доп.)', 'en' => 'Honey', 'vn' => 'Mật ong'],
            414 => ['ru' => 'Мороженое (1 шарик)', 'en' => 'Ice Cream (1 Scoop)', 'vn' => 'Kem (1 viên)'],
            446 => ['ru' => 'Солянка', 'en' => 'Solyanka', 'vn' => 'Solyanka'],
            447 => ['ru' => 'Доп. тунец', 'en' => 'Extra Tuna', 'vn' => 'Thêm cá ngừ'],
            449 => ['ru' => 'Киноа', 'en' => 'Quinoa', 'vn' => 'Quinoa'],
            450 => ['ru' => 'Хешбраун (1 шт.)', 'en' => 'Hash Brown (1 pc.)', 'vn' => 'Hash brown (1 cái)'],
            454 => ['ru' => 'Наполеон', 'en' => 'Napoleon Cake', 'vn' => 'Bánh Napoleon'],
            455 => ['ru' => 'Метр мяса (спец.)', 'en' => 'BBQ Combo (Special)', 'vn' => 'Combo BBQ (đặc biệt)'],
            458 => ['ru' => 'Шашлык Джем', 'en' => 'Shashlik Jam', 'vn' => 'Shashlik Jam'],
            460 => ['ru' => 'Чили-соус', 'en' => 'Chili Sauce', 'vn' => 'Sốt ớt'],
            461 => ['ru' => 'Лосось с/с (доп.)', 'en' => 'Extra Cured Salmon', 'vn' => 'Thêm cá hồi muối'],
            471 => ['ru' => 'Зелёная шакшука', 'en' => 'Green Shakshuka', 'vn' => 'Shakshuka xanh'],
            472 => ['ru' => 'Завтрак Бенедикт', 'en' => 'Eggs Benedict', 'vn' => 'Trứng Benedict'],
            474 => ['ru' => 'Йогурт с гранолой и фруктами', 'en' => 'Yogurt with Granola & Fruit', 'vn' => 'Sữa chua với granola & trái cây'],
            475 => ['ru' => 'Овсянка с томатами и пармезаном', 'en' => 'Oatmeal with Tomatoes & Parmesan', 'vn' => 'Yến mạch với cà chua & Parmesan'],
            476 => ['ru' => 'Френч-тост', 'en' => 'French Toast', 'vn' => 'Bánh mì nướng kiểu Pháp'],
            482 => ['ru' => 'Блины классика', 'en' => 'Classic Pancakes', 'vn' => 'Bánh crepe truyền thống'],
            483 => ['ru' => 'Блины с ветчиной и сыром', 'en' => 'Pancakes with Ham & Cheese', 'vn' => 'Crepe với giăm bông & phô mai'],
            484 => ['ru' => 'Блины с грибами', 'en' => 'Pancakes with Mushrooms', 'vn' => 'Crepe nấm'],
            485 => ['ru' => 'Блины с лососем', 'en' => 'Pancakes with Salmon', 'vn' => 'Crepe cá hồi'],
            486 => ['ru' => 'Блины с творогом', 'en' => 'Pancakes with Cottage Cheese', 'vn' => 'Crepe phô mai tươi'],
            487 => ['ru' => 'Блины с яблоком', 'en' => 'Pancakes with Apple', 'vn' => 'Crepe táo'],
            489 => ['ru' => 'Пармезан', 'en' => 'Parmesan', 'vn' => 'Parmesan'],
            490 => ['ru' => 'Фета', 'en' => 'Feta', 'vn' => 'Feta'],
            491 => ['ru' => 'Рис', 'en' => 'Rice', 'vn' => 'Cơm'],
            492 => ['ru' => 'Ореховый соус', 'en' => 'Nut Sauce', 'vn' => 'Sốt hạt'],
            493 => ['ru' => 'Шашлычный острый соус', 'en' => 'Spicy BBQ Sauce', 'vn' => 'Sốt BBQ cay'],
            513 => ['ru' => 'Соус сырный', 'en' => 'Cheese Sauce', 'vn' => 'Sốt phô mai'],
            536 => ['ru' => 'Пюре', 'en' => 'Mashed Potatoes', 'vn' => 'Khoai tây nghiền'],
            539 => ['ru' => 'Окрошка', 'en' => 'Okroshka', 'vn' => 'Okroshka'],
            540 => ['ru' => 'Вареники с картофелем', 'en' => 'Potato Vareniki', 'vn' => 'Vareniki nhân khoai tây'],
            541 => ['ru' => 'Окрошка (кефир)', 'en' => 'Okroshka (Kefir)', 'vn' => 'Okroshka (kefir)'],
            542 => ['ru' => 'Вареники жареные', 'en' => 'Fried Vareniki', 'vn' => 'Vareniki chiên'],
            546 => ['ru' => 'Бургер Блю чиз', 'en' => 'Blue Cheese Burger', 'vn' => 'Burger phô mai xanh'],
        ];
    }
}
