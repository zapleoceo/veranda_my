<?php

require_once __DIR__ . '/admin/lib/admin_utils.php';

function t_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        echo "PASS: {$label}\n";
        return;
    }

    echo "FAIL: {$label}\n";
    echo "  expected: " . var_export($expected, true) . "\n";
    echo "  actual:   " . var_export($actual, true) . "\n";
    exit(1);
}

t_assert_same('Hello', admin_strip_number_prefix('1.2. Hello'), 'strip number prefix + dot');
t_assert_same('Hello', admin_strip_number_prefix('   12   Hello  '), 'strip number prefix spaces');
t_assert_same('', admin_strip_number_prefix('   '), 'strip empty');
t_assert_same('. abc', admin_strip_number_prefix('  1.2.3.. abc  '), 'strip weird dot');

t_assert_same('poster_station', admin_menu_normalize_sort_key('station'), 'menu sort station alias');
t_assert_same('poster_station', admin_menu_normalize_sort_key('poster_category'), 'menu sort poster_category alias');
t_assert_same('poster_category', admin_menu_normalize_sort_key('poster_subcategory'), 'menu sort poster_subcategory alias');
t_assert_same('adapted_workshop_ru', admin_menu_normalize_sort_key('adapted_category_ru'), 'menu sort adapted_category_* alias');
t_assert_same('adapted_category_vn', admin_menu_normalize_sort_key('adapted_subcategory_vn'), 'menu sort adapted_subcategory_* alias');
t_assert_same('main_sort', admin_menu_normalize_sort_key('MAIN_SORT'), 'menu sort lower/trim');

t_assert_same(
    'poster_station,poster_id,poster_category,adapted_workshop_ru,adapted_category_en',
    admin_menu_normalize_cols_param('poster_category, poster_id, poster_subcategory,poster_category, adapted_category_ru, adapted_subcategory_en'),
    'menu cols param normalization + dedupe'
);
t_assert_same('', admin_menu_normalize_cols_param(''), 'menu cols empty');

t_assert_same('каждые 5 минут', admin_logs_cron_human('*/5 * * * *'), 'cron human 5 min');
t_assert_same('каждый час (в :05)', admin_logs_cron_human('5 * * * *'), 'cron human hourly 05');
t_assert_same('по расписанию cron', admin_logs_cron_human('10 1 * * *'), 'cron human default');

$now = 1_700_000_000;
t_assert_same(['kind' => 'bad', 'label' => 'ПРОБЛЕМА'], admin_logs_sync_status(['exists' => false], $now), 'sync status missing file');
t_assert_same(['kind' => 'bad', 'label' => 'ПРОБЛЕМА'], admin_logs_sync_status(['exists' => true, 'mtime' => $now - 8000], $now), 'sync status too old');
t_assert_same(['kind' => 'ok', 'label' => 'есть'], admin_logs_sync_status(['exists' => true, 'mtime' => $now - 60], $now), 'sync status ok');

echo "OK\n";
