<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;

class MenuPublicService
{
    private const SUPPORTED_LANGS = ['ru', 'en', 'vi', 'ko'];
    private const BASE_URL        = 'https://veranda.my/links/menu';

    public function __construct(private readonly Database $db) {}

    public function resolveLanguage(?string $requested, ?string $cookie, string $acceptHeader): string
    {
        foreach ([$requested, $cookie] as $candidate) {
            if ($candidate !== null && in_array(strtolower($candidate), self::SUPPORTED_LANGS, true)) {
                return strtolower($candidate);
            }
        }

        foreach (preg_split('/\s*,\s*/', $acceptHeader) ?: [] as $part) {
            $code = strtolower(explode(';', $part, 2)[0]);
            $base = explode('-', trim($code), 2)[0];
            if (in_array($base, self::SUPPORTED_LANGS, true)) return $base;
        }

        return 'ru';
    }

    public function getMenuData(string $lang): array
    {
        $trLang = $lang === 'vi' ? 'vn' : $lang;

        $pmi  = $this->db->t('poster_menu_items');
        $mw   = $this->db->t('menu_workshops');
        $mwTr = $this->db->t('menu_workshop_tr');
        $mc   = $this->db->t('menu_categories');
        $mcTr = $this->db->t('menu_category_tr');
        $mi   = $this->db->t('menu_items');
        $miTr = $this->db->t('menu_item_tr');

        try {
            $rows = $this->db->query(
                "SELECT
                    w.id AS workshop_id,
                    COALESCE(NULLIF(wtr.name,''), NULLIF(wtr_ru.name,''), '') AS main_label,
                    c.id AS category_id,
                    COALESCE(NULLIF(ctr.name,''), NULLIF(ctr_ru.name,''), '') AS sub_label,
                    p.poster_id, p.price_raw,
                    COALESCE(NULLIF(itr.title,''), NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS title,
                    COALESCE(NULLIF(itr.description,''), NULLIF(itr_ru.description,''), '') AS description,
                    COALESCE(NULLIF(mi.image_url,''), '') AS image_url,
                    COALESCE(mi.sort_order, 0) AS sort_order,
                    COALESCE(w.sort_order, 0) AS main_sort,
                    COALESCE(c.sort_order, 0) AS sub_sort
                 FROM {$mi} mi
                 JOIN {$pmi} p ON p.id=mi.poster_item_id AND p.is_active=1
                 JOIN {$mc} c ON c.id=mi.category_id AND c.show_on_site=1
                 JOIN {$mw} w ON w.id=c.workshop_id AND w.show_on_site=1
                 LEFT JOIN {$miTr} itr ON itr.item_id=mi.id AND itr.lang=?
                 LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id=mi.id AND itr_ru.lang='ru'
                 LEFT JOIN {$mcTr} ctr ON ctr.category_id=c.id AND ctr.lang=?
                 LEFT JOIN {$mwTr} wtr ON wtr.workshop_id=w.id AND wtr.lang=?
                 JOIN {$mcTr} ctr_ru ON ctr_ru.category_id=c.id AND ctr_ru.lang='ru' AND ctr_ru.name<>''
                 JOIN {$mwTr} wtr_ru ON wtr_ru.workshop_id=w.id AND wtr_ru.lang='ru' AND wtr_ru.name<>''
                 WHERE mi.is_published=1
                 ORDER BY w.sort_order ASC, main_label ASC, c.sort_order ASC, sub_label ASC, mi.sort_order ASC, title ASC",
                [$trLang, $trLang, $trLang]
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $this->_groupItems($rows);
    }

    public function getLastSyncAt(): ?string
    {
        $mt = $this->db->t('system_meta');
        try {
            $row = $this->db->query("SELECT meta_value FROM {$mt} WHERE meta_key='menu_last_sync_at' LIMIT 1")->fetch();
            return !empty($row['meta_value']) ? (string)$row['meta_value'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function buildSeoMeta(string $lang, bool $explicit): array
    {
        $base    = self::BASE_URL;
        $langUrl = static fn(string $c): string => $base . '?lang=' . urlencode($c);

        return [
            'canonical' => $explicit ? $langUrl($lang) : $base,
            'hreflang'  => [
                'x-default' => $base,
                'ru'        => $langUrl('ru'),
                'en'        => $langUrl('en'),
                'vi'        => $langUrl('vi'),
                'ko'        => $langUrl('ko'),
            ],
            'title' => match ($lang) {
                'en' => 'Veranda - menu. Nha Trang, Vietnam',
                'vi' => 'Veranda - thực đơn. Nha Trang, Việt Nam',
                'ko' => 'Veranda — 메뉴, 나트랑, 베트남',
                default => 'Veranda — меню, Нячанг, Вьетнам',
            },
            'description' => match ($lang) {
                'en' => 'Veranda online menu — restaurant & bar in Nha Trang, Vietnam. Dishes by categories and prices.',
                'vi' => 'Thực đơn online Veranda — nhà hàng & quầy bar tại Nha Trang, Việt Nam. Món theo danh mục và giá.',
                'ko' => 'Veranda 온라인 메뉴 — 베트남 나트랑의 레스토랑 & 바. 카테고리별 메뉴와 가격.',
                default => 'Онлайн меню Veranda — ресторан и бар в Нячанге, Вьетнам. Блюда по категориям и цены.',
            },
        ];
    }

    private function _groupItems(array $rows): array
    {
        $groups = [];
        foreach ($rows as $it) {
            $wid      = (int)($it['workshop_id'] ?? 0);
            $cid      = (int)($it['category_id'] ?? 0);
            $mainLabel = trim((string)($it['main_label'] ?? ''));
            $subLabel  = trim((string)($it['sub_label'] ?? ''));

            if ($mainLabel === '' || $subLabel === '') continue;

            $groups[$wid] ??= ['label' => $mainLabel, 'cats' => []];
            $groups[$wid]['cats'][$cid] ??= ['label' => $subLabel, 'items' => []];
            $groups[$wid]['cats'][$cid]['items'][] = $it;
        }

        foreach ($groups as $wid => $g) {
            foreach ($g['cats'] as $cid => $cat) {
                if (empty($cat['items'])) unset($groups[$wid]['cats'][$cid]);
            }
            if (empty($groups[$wid]['cats'])) unset($groups[$wid]);
        }

        return $groups;
    }
}
