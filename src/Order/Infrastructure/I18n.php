<?php

declare(strict_types=1);

namespace App\Order\Infrastructure;

/**
 * Three-language dictionary + a tiny resolver for /neworder.
 *
 * Lookup order:   ?lang=         → cookie `neworder_lang`
 *                 → Accept-Language → 'ru' fallback
 *
 * Menu names come from Poster as-is — we never translate them.
 * Only the static UI chrome lives here. Both the server-rendered
 * view and the client-side JS read from the same dictionary
 * (the view consumes $t directly; the JS receives the matching
 * language slice inline as window.__noI18n).
 */
final class I18n
{
    public const SUPPORTED = ['ru', 'en', 'vi'];
    public const DEFAULT_LANG = 'ru';
    public const COOKIE_NAME = 'neworder_lang';
    public const COOKIE_TTL  = 31536000;   // 1 year

    /**
     * @param string|null $requested ?lang= from query string
     * @param string|null $cookie    current cookie value
     * @param string      $acceptHeader HTTP_ACCEPT_LANGUAGE
     */
    public static function resolve(?string $requested, ?string $cookie, string $acceptHeader): string
    {
        foreach ([$requested, $cookie] as $candidate) {
            if (is_string($candidate)) {
                $c = strtolower(trim($candidate));
                if (in_array($c, self::SUPPORTED, true)) return $c;
            }
        }
        foreach (preg_split('/\s*,\s*/', $acceptHeader) ?: [] as $part) {
            $code = strtolower(trim(explode(';', $part, 2)[0]));
            $base = explode('-', $code, 2)[0];
            if (in_array($base, self::SUPPORTED, true)) return $base;
        }
        return self::DEFAULT_LANG;
    }

    /** Full dictionary for the chosen language. */
    public static function strings(string $lang): array
    {
        $all = self::all();
        return $all[$lang] ?? $all[self::DEFAULT_LANG];
    }

    /**
     * Single canonical dictionary — ALL keys must exist in ALL languages.
     * Keep entries sorted to make missing-translation review trivial.
     */
    public static function all(): array
    {
        return [
            // ─── Russian (canonical / fallback) ──────────────────
            'ru' => [
                'title'             => 'Новый заказ',
                'locationDefault'   => 'Стол',
                'searchPh'          => 'Поиск по меню',
                'refreshMenu'       => 'Обновить меню',
                'menuRefreshing'    => 'Меню обновляется…',
                'menuEmpty'         => 'Меню пусто.',
                'menuLoadError'     => 'Не удалось загрузить меню',
                'searchEmptyTpl'    => 'Ничего не найдено по запросу «{q}».',
                'priceFrom'         => 'от',
                'categoryOther'     => 'Прочее',
                'cart'              => 'Корзина',
                'cartEmpty'         => 'Корзина пуста',
                'commentLabel'      => 'Комментарий к заказу',
                'commentPh'         => 'Например, «без лука»',
                'total'             => 'Итого',
                'place'             => 'Подтвердить заказ',
                'close'             => 'Закрыть',
                'add'               => 'Добавить',
                'addModifTotal'     => 'К позиции:',
                'modifNoOptions'    => 'Без опций',
                'modifExtras'       => 'Дополнительно',
                'pickTable'         => 'Выбрать стол',
                'selectTable'       => 'Выберите стол',
                'spot'              => 'Заведение',
                'hall'              => 'Зал',
                'hallAll'           => 'Все залы',
                'noTables'          => 'Нет столов',
                'openCheckOne'      => 'На столе уже открыт чек',
                'openCheckMany'     => 'На столе уже открыто {n} чека/-ов',
                'newSeparateOrder'  => 'Новый отдельный заказ',
                'addToCheckTpl'     => 'Добавить к чеку #{id} · {sum}',
                'success'           => 'Заказ принят',
                'orderAcceptedTpl'  => 'Заказ #{id} принят в Poster',
                'orderAppendedTpl'  => 'Добавлено {n} поз. к чеку #{id}',
                'newOrderBtn'       => 'Новый заказ',
                'errorGeneric'      => 'Ошибка',
            ],

            // ─── English ──────────────────────────────────────────
            'en' => [
                'title'             => 'New order',
                'locationDefault'   => 'Table',
                'searchPh'          => 'Search the menu',
                'refreshMenu'       => 'Refresh menu',
                'menuRefreshing'    => 'Refreshing menu…',
                'menuEmpty'         => 'Menu is empty.',
                'menuLoadError'     => 'Failed to load menu',
                'searchEmptyTpl'    => 'Nothing found for “{q}”.',
                'priceFrom'         => 'from',
                'categoryOther'     => 'Other',
                'cart'              => 'Cart',
                'cartEmpty'         => 'Cart is empty',
                'commentLabel'      => 'Order comment',
                'commentPh'         => 'e.g. “no onions, please”',
                'total'             => 'Total',
                'place'             => 'Place order',
                'close'             => 'Close',
                'add'               => 'Add',
                'addModifTotal'     => 'Item price:',
                'modifNoOptions'    => 'No options',
                'modifExtras'       => 'Extras',
                'pickTable'         => 'Pick a table',
                'selectTable'       => 'Select a table',
                'spot'              => 'Venue',
                'hall'              => 'Hall',
                'hallAll'           => 'All halls',
                'noTables'          => 'No tables',
                'openCheckOne'      => 'There is already an open check on this table',
                'openCheckMany'     => 'There are already {n} open checks on this table',
                'newSeparateOrder'  => 'New separate order',
                'addToCheckTpl'     => 'Add to check #{id} · {sum}',
                'success'           => 'Order placed',
                'orderAcceptedTpl'  => 'Order #{id} accepted in Poster',
                'orderAppendedTpl'  => 'Added {n} item(s) to check #{id}',
                'newOrderBtn'       => 'New order',
                'errorGeneric'      => 'Error',
            ],

            // ─── Vietnamese ───────────────────────────────────────
            'vi' => [
                'title'             => 'Đơn mới',
                'locationDefault'   => 'Bàn',
                'searchPh'          => 'Tìm trong thực đơn',
                'refreshMenu'       => 'Cập nhật thực đơn',
                'menuRefreshing'    => 'Đang cập nhật thực đơn…',
                'menuEmpty'         => 'Thực đơn trống.',
                'menuLoadError'     => 'Không tải được thực đơn',
                'searchEmptyTpl'    => 'Không tìm thấy «{q}».',
                'priceFrom'         => 'từ',
                'categoryOther'     => 'Khác',
                'cart'              => 'Giỏ hàng',
                'cartEmpty'         => 'Giỏ hàng trống',
                'commentLabel'      => 'Ghi chú đơn',
                'commentPh'         => 'Ví dụ «không hành»',
                'total'             => 'Tổng',
                'place'             => 'Đặt hàng',
                'close'             => 'Đóng',
                'add'               => 'Thêm',
                'addModifTotal'     => 'Cho món:',
                'modifNoOptions'    => 'Không có tùy chọn',
                'modifExtras'       => 'Thêm',
                'pickTable'         => 'Chọn bàn',
                'selectTable'       => 'Hãy chọn bàn',
                'spot'              => 'Cơ sở',
                'hall'              => 'Sảnh',
                'hallAll'           => 'Tất cả sảnh',
                'noTables'          => 'Không có bàn',
                'openCheckOne'      => 'Bàn này đang có một hoá đơn mở',
                'openCheckMany'     => 'Bàn này đang có {n} hoá đơn mở',
                'newSeparateOrder'  => 'Tạo đơn riêng mới',
                'addToCheckTpl'     => 'Thêm vào hoá đơn #{id} · {sum}',
                'success'           => 'Đã nhận đơn',
                'orderAcceptedTpl'  => 'Đơn #{id} đã vào Poster',
                'orderAppendedTpl'  => 'Đã thêm {n} món vào hoá đơn #{id}',
                'newOrderBtn'       => 'Đơn mới',
                'errorGeneric'      => 'Lỗi',
            ],
        ];
    }
}
