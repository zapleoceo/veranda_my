<?php
declare(strict_types=1);

function tr3_api_menu_preorder(array $ctx): void {
  api_json_headers(true);

  $db = $ctx['db'] ?? null;
  if (!($db instanceof \App\Classes\Database)) api_error(500, 'DB not configured');

  $supportedMenuLangs = ['ru', 'en', 'vi', 'ko'];
  $menuLang = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
  if (!in_array($menuLang, $supportedMenuLangs, true)) $menuLang = 'ru';
  $trLang = $menuLang === 'vi' ? 'vn' : $menuLang;

  try {
    $db->createMenuTables();
  } catch (\Throwable $e) {}

  $metaTable = $db->t('system_meta');
  $pmi = $db->t('poster_menu_items');
  $mw = $db->t('menu_workshops');
  $mwTr = $db->t('menu_workshop_tr');
  $mc = $db->t('menu_categories');
  $mcTr = $db->t('menu_category_tr');
  $mi = $db->t('menu_items');
  $miTr = $db->t('menu_item_tr');

  $lastMenuSyncAt = null;
  try {
    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'menu_last_sync_at' LIMIT 1")->fetch();
    if (is_array($row) && !empty($row['meta_value'])) $lastMenuSyncAt = (string)$row['meta_value'];
  } catch (\Throwable $e) {}

  try {
    $rows = $db->query(
      "SELECT
          w.id AS workshop_id,
          COALESCE(NULLIF(wtr.name,''), NULLIF(w.name_raw,''), '') AS main_label,
          c.id AS category_id,
          COALESCE(NULLIF(ctr.name,''), NULLIF(c.name_raw,''), '') AS sub_label,
          mi.id AS menu_item_id,
          p.poster_id,
          p.price_raw,
          COALESCE(NULLIF(itr.title,''), NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS title,
          COALESCE(NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS ru_title,
          COALESCE(NULLIF(itr.description,''), NULLIF(itr_ru.description,''), '') AS description,
          COALESCE(NULLIF(mi.image_url,''), '') AS image_url,
          COALESCE(mi.sort_order, 0) AS sort_order,
          COALESCE(w.sort_order, 0) AS main_sort,
          COALESCE(c.sort_order, 0) AS sub_sort
       FROM {$mi} mi
       JOIN {$pmi} p ON p.id = mi.poster_item_id AND p.is_active = 1
       JOIN {$mc} c ON c.id = mi.category_id AND c.show_on_site = 1
       JOIN {$mw} w ON w.id = c.workshop_id AND w.show_on_site = 1
       LEFT JOIN {$miTr} itr ON itr.item_id = mi.id AND itr.lang = ?
       LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id = mi.id AND itr_ru.lang = 'ru'
       LEFT JOIN {$mcTr} ctr ON ctr.category_id = c.id AND ctr.lang = ?
       LEFT JOIN {$mwTr} wtr ON wtr.workshop_id = w.id AND wtr.lang = ?
       WHERE mi.is_published = 1
       ORDER BY
          w.sort_order ASC,
          main_label ASC,
          c.sort_order ASC,
          sub_label ASC,
          mi.sort_order ASC,
          title ASC",
      [$trLang, $trLang, $trLang]
    )->fetchAll();
  } catch (\Throwable $e) {
    api_error(500, 'Menu query failed');
  }

  $groups = [];
  foreach ($rows as $it) {
    if (!is_array($it)) continue;
    $mainLabel = trim((string)($it['main_label'] ?? ''));
    $subLabel = trim((string)($it['sub_label'] ?? ''));
    if ($mainLabel === '' || $subLabel === '') continue;
    $workshopId = (int)($it['workshop_id'] ?? 0);
    $categoryId = (int)($it['category_id'] ?? 0);
    $mainSort = (int)($it['main_sort'] ?? 0);
    $subSort = (int)($it['sub_sort'] ?? 0);
    $sortOrder = (int)($it['sort_order'] ?? 0);

    $groupsKey = $workshopId . '|' . $mainLabel;
    if (!isset($groups[$groupsKey])) {
      $groups[$groupsKey] = ['workshop_id' => $workshopId, 'title' => $mainLabel, 'sort' => $mainSort, 'categories' => []];
    }

    $catKey = $categoryId . '|' . $subLabel;
    if (!isset($groups[$groupsKey]['categories'][$catKey])) {
      $groups[$groupsKey]['categories'][$catKey] = ['category_id' => $categoryId, 'title' => $subLabel, 'sort' => $subSort, 'items' => []];
    }

    $title = trim((string)($it['title'] ?? ''));
    if ($title === '') continue;
    $priceRaw = (string)($it['price_raw'] ?? '');
    $price = is_numeric($priceRaw) ? (int)$priceRaw : null;

    $groups[$groupsKey]['categories'][$catKey]['items'][] = [
      'id' => (int)($it['menu_item_id'] ?? 0),
      'title' => $title,
      'ru_title' => trim((string)($it['ru_title'] ?? '')),
      'price' => $price,
      'description' => trim((string)($it['description'] ?? '')),
      'image_url' => trim((string)($it['image_url'] ?? '')),
      'sort' => $sortOrder,
    ];
  }

  $out = array_values($groups);
  usort($out, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
  foreach ($out as &$g) {
    $cats = isset($g['categories']) && is_array($g['categories']) ? array_values($g['categories']) : [];
    usort($cats, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    foreach ($cats as &$c) {
      $items = isset($c['items']) && is_array($c['items']) ? $c['items'] : [];
      usort($items, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
      $c['items'] = $items;
    }
    unset($c);
    $g['categories'] = $cats;
  }
  unset($g);

  api_send_json(['ok' => true, 'lang' => $menuLang, 'last_sync_at' => $lastMenuSyncAt, 'groups' => $out], 200);
}

