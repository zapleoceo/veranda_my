<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\AiBrokerClient;
use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

/**
 * Перевод названий позиций меню, у которых нет перевода, через AIbroker.
 *
 * Работает «при первом касании»: публичное меню вызывает translateMissing()
 * после отдачи страницы, новинка до перевода показывается с названием из
 * Poster. Готовые переводы (ручные из админки и прошлые ИИ) не трогаем —
 * пишем только в пустые языки.
 */
final class MenuTranslationService
{
    /** Язык в menu_item_tr => как просим у ИИ. vi в БД исторически «vn». */
    private const LANGS = ['ru' => 'ru', 'en' => 'en', 'vn' => 'vi', 'ko' => 'ko'];
    private const LOCK = 'veranda_menu_translate';
    private const FAIL_KEY = 'menu_translate_failed_at';
    private const FAIL_PAUSE_SEC = 600;
    private const BATCH = 40;

    public function __construct(
        private readonly Database $db,
        private readonly AiBrokerClient $ai,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int сколько позиций переведено */
    public function translateMissing(): int
    {
        if (!$this->ai->isConfigured()) {
            return 0;
        }
        $items = $this->missing(self::BATCH);
        if ($items === []) {
            return 0;
        }

        $got = $this->db->query('SELECT GET_LOCK(?, 0)', [self::LOCK])->fetchColumn();
        if ((int) $got !== 1) {
            return 0;
        }
        try {
            if ($this->recentlyFailed()) {
                return 0;
            }
            // Пока ждали замок, соседний запрос мог уже перевести.
            $items = $this->missing(self::BATCH);
            if ($items === []) {
                return 0;
            }
            try {
                $ai = $this->ai->structured(self::messages($items), 'menu_titles', self::schema(), 4000, 90);
            } catch (\Throwable $e) {
                $this->logger->warning('menu.translate_failed', ['err' => $e->getMessage(), 'items' => count($items)]);
                $this->markFailed();

                return 0;
            }

            return $this->save($items, self::parse($ai, array_keys($items)));
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * Видимые на сайте позиции, у которых пуст хотя бы один язык.
     *
     * @return array<int,array{name:string,category:string}> item_id => данные
     */
    private function missing(int $limit): array
    {
        $pmi = $this->db->t('poster_menu_items');
        $mi = $this->db->t('menu_items');
        $mc = $this->db->t('menu_categories');
        $mw = $this->db->t('menu_workshops');
        $tr = $this->db->t('menu_item_tr');
        $langs = array_keys(self::LANGS);
        $in = implode(',', array_fill(0, count($langs), '?'));

        $rows = $this->db->query(
            "SELECT mi.id, p.name_raw, COALESCE(p.sub_category_name, '') AS category
             FROM {$mi} mi
             JOIN {$pmi} p ON p.id = mi.poster_item_id AND p.is_active = 1
             JOIN {$mc} c ON c.id = mi.category_id AND c.show_on_site = 1
             JOIN {$mw} w ON w.id = c.workshop_id AND w.show_on_site = 1
             LEFT JOIN {$tr} t ON t.item_id = mi.id AND t.lang IN ({$in}) AND t.title IS NOT NULL AND t.title <> ''
             WHERE mi.is_published = 1
             GROUP BY mi.id, p.name_raw, p.sub_category_name
             HAVING COUNT(t.lang) < ?
             ORDER BY mi.id DESC
             LIMIT " . max(1, $limit),
            [...$langs, count($langs)]
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['name' => trim((string) $r['name_raw']), 'category' => trim((string) $r['category'])];
        }

        return $out;
    }

    /**
     * @param array<int,array{name:string,category:string}> $items
     * @param array<int,array<string,string>>               $titles item_id => [db_lang => title]
     */
    private function save(array $items, array $titles): int
    {
        $tr = $this->db->t('menu_item_tr');
        $done = 0;
        foreach ($titles as $itemId => $byLang) {
            if (!isset($items[$itemId])) {
                continue;
            }
            foreach ($byLang as $lang => $title) {
                // Только в пустые: ручной перевод из админки приоритетнее.
                $this->db->query(
                    "INSERT INTO {$tr} (item_id, lang, title) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE title = IF(title IS NULL OR title = '', VALUES(title), title)",
                    [$itemId, $lang, $title]
                );
            }
            $done++;
        }
        $this->logger->info('menu.translated', ['items' => $done]);

        return $done;
    }

    /**
     * Разбор ответа ИИ: только запрошенные id, непустые строки, языки в ключах БД.
     *
     * @param array<string,mixed> $ai
     * @param int[]               $allowedIds
     * @return array<int,array<string,string>>
     */
    public static function parse(array $ai, array $allowedIds): array
    {
        $allowed = array_flip($allowedIds);
        $out = [];
        foreach ((array) ($ai['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if (!isset($allowed[$id])) {
                continue;
            }
            foreach (self::LANGS as $dbLang => $aiLang) {
                $t = trim(preg_replace('/\s+/u', ' ', (string) ($row[$aiLang] ?? '')) ?? '');
                if ($t !== '') {
                    $out[$id][$dbLang] = mb_substr($t, 0, 200, 'UTF-8');
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int,array{name:string,category:string}> $items
     * @return array<array{role:string,content:string}>
     */
    private static function messages(array $items): array
    {
        $list = [];
        foreach ($items as $id => $it) {
            $list[] = ['id' => $id, 'name' => $it['name'], 'category' => $it['category']];
        }

        return [
            [
                'role' => 'system',
                'content' => "Ты переводишь названия позиций меню ресторана-бара Veranda (Нячанг, Вьетнам) для сайта.\n"
                    . "Названия взяты из кассы Poster: часто это «Русское/English», бывают номера вроде «7.2» в начале, опечатки и лишние пробелы.\n"
                    . "Для каждой позиции верни аккуратное название на четырёх языках: ru (русский), en (английский), vi (вьетнамский), ko (корейский).\n"
                    . "Правила: убери номера и служебные пометки; исправь очевидные опечатки; если в исходнике есть русский и английский вариант — опирайся на оба; "
                    . "фирменные названия коктейлей и напитков (например «Veranda Blue», «Pink Pantera») не переводи, оставь как есть на всех языках; "
                    . "коротко, как в меню, без описаний и кавычек; первая буква заглавная.\n"
                    . "Верни все id из запроса.",
            ],
            ['role' => 'user', 'content' => json_encode($list, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** @return array<string,mixed> */
    private static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'ru', 'en', 'vi', 'ko'],
                        'properties' => ['id' => ['type' => 'integer'], 'ru' => $str, 'en' => $str, 'vi' => $str, 'ko' => $str],
                    ],
                ],
            ],
        ];
    }

    private function recentlyFailed(): bool
    {
        $mt = $this->db->t('system_meta');
        $at = $this->db->query("SELECT meta_value FROM {$mt} WHERE meta_key = ? LIMIT 1", [self::FAIL_KEY])->fetchColumn();

        return is_string($at) && ctype_digit($at) && time() - (int) $at < self::FAIL_PAUSE_SEC;
    }

    private function markFailed(): void
    {
        $mt = $this->db->t('system_meta');
        $this->db->query(
            "INSERT INTO {$mt} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [self::FAIL_KEY, (string) time()]
        );
    }
}
