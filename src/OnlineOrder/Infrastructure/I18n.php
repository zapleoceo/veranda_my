<?php

declare(strict_types=1);

namespace App\OnlineOrder\Infrastructure;

use App\Order\Infrastructure\I18n as OrderI18n;

/**
 * Three-language dictionary for the public /onlineorder page. The
 * resolution logic (?lang → cookie → Accept-Language → ru) is the
 * same as /neworder, so we delegate to that resolver instead of
 * copying it; only the cookie name and the strings are our own.
 *
 * Menu item names come from Poster as-is and are never translated —
 * this dictionary covers UI chrome only. ALL keys must exist in ALL
 * languages.
 */
final class I18n
{
    public const SUPPORTED    = OrderI18n::SUPPORTED;          // ['ru','en','vi']
    public const DEFAULT_LANG = OrderI18n::DEFAULT_LANG;
    public const COOKIE_NAME  = 'onlineorder_lang';
    public const COOKIE_TTL   = 31536000;                      // 1 year

    public static function resolve(?string $requested, ?string $cookie, string $acceptHeader): string
    {
        return OrderI18n::resolve($requested, $cookie, $acceptHeader);
    }

    public static function strings(string $lang): array
    {
        $all = self::all();
        return $all[$lang] ?? $all[self::DEFAULT_LANG];
    }

    public static function all(): array
    {
        return [
            // ─── Russian (canonical / fallback) ──────────────────
            'ru' => [
                'title'            => 'Доставка',
                'subtitle'         => 'Закажите любимые блюда Veranda с доставкой по Нячангу',
                'searchPh'         => 'Поиск по меню',
                'menuLoadError'    => 'Не удалось загрузить меню — обновите страницу',
                'menuEmpty'        => 'Меню пусто.',
                'searchEmptyTpl'   => 'Ничего не найдено по запросу «{q}».',
                'categoryOther'    => 'Прочее',
                'priceFrom'        => 'от',
                'add'              => 'Добавить',
                'addModifTotal'    => 'К позиции:',
                'modifExtras'      => 'Дополнительно',
                'cart'             => 'Корзина',
                'cartEmpty'        => 'Корзина пуста — добавьте что-нибудь вкусное',
                'commentLabel'     => 'Комментарий к заказу',
                'commentPh'        => 'Например, «поострее» или «без лука»',
                'foodTotal'        => 'Еда',
                'deliveryRow'      => 'Доставка',
                'total'            => 'Итого',
                'toCheckout'       => 'Оформить доставку',
                'close'            => 'Закрыть',
                'checkoutTitle'    => 'Доставка',
                'nameLabel'        => 'Имя',
                'namePh'           => 'Как к вам обращаться',
                'phoneLabel'       => 'Телефон',
                'phonePh'          => '+84 / 0… (Telegram/Zalo — плюс)',
                'addressLabel'     => 'Адрес доставки',
                'addressPh'        => 'Улица, дом — начните вводить…',
                'apartmentLabel'   => 'Квартира / этаж / отель',
                'apartmentPh'      => 'Кв. 12, 3 этаж / отель, номер',
                'noteLabel'        => 'Ориентир для курьера',
                'notePh'           => 'Подъезд со двора, синяя дверь…',
                'quoteCalculating' => 'Считаем стоимость доставки…',
                'quoteUnavailable' => 'Стоимость доставки подтвердит оператор после оформления',
                'quoteOutOfZone'   => 'Адрес вне зоны доставки (до {km} км). Свяжитесь с нами — что-нибудь придумаем!',
                'quoteGeocodeFail' => 'Не удалось найти адрес — уточните или добавьте ориентир',
                'quoteRow'         => 'Доставка ({provider})',
                'quoteEtaTpl'      => '~{min} мин',
                'quoteDistanceTpl' => '{km} км',
                'quoteCourierNote' => 'Доставку оплачиваете курьеру при получении — наличными или по QR',
                'minOrderTpl'      => 'Минимальный заказ — {sum}',
                'placeOrder'       => 'Подтвердить заказ',
                'requiredField'    => 'Обязательное поле',
                'phoneInvalid'     => 'Проверьте номер телефона',
                'addressMissing'   => 'Укажите адрес доставки',
                'cartInvalid'      => 'Корзина пуста или устарела — обновите страницу',
                'submitError'      => 'Не получилось отправить заказ. Попробуйте ещё раз или позвоните нам.',
                'throttled'        => 'Слишком много заказов подряд. Подождите немного или позвоните нам.',
                'success'          => 'Заказ принят!',
                'orderAcceptedTpl' => 'Заказ #{id} передан на кухню',
                'payTitle'         => 'Оплата еды — по QR',
                'payInstruction'   => 'Отсканируйте QR в банковском приложении. Сумма и назначение подставятся сами — ничего не меняйте.',
                'payAmount'        => 'Сумма',
                'payReference'     => 'Назначение',
                'payAccount'       => 'Счёт',
                'payAccountName'   => 'Получатель',
                'payPendingNote'   => 'Готовить начнём после поступления оплаты — обычно это пара минут.',
                'payNotConfigured' => 'Мы свяжемся с вами по оплате — телефон уже у нас.',
                'deliveryCourier'  => 'Доставка оплачивается курьеру при получении.',
                'dispatchOk'       => 'Курьер уже вызван — следите за звонком.',
                'newOrderBtn'      => 'Новый заказ',
                'errorGeneric'     => 'Ошибка',
                'backToMenu'       => 'К меню',
                'qty'              => 'шт.',
            ],

            // ─── English ──────────────────────────────────────────
            'en' => [
                'title'            => 'Delivery',
                'subtitle'         => 'Your favourite Veranda dishes, delivered across Nha Trang',
                'searchPh'         => 'Search the menu',
                'menuLoadError'    => 'Failed to load the menu — refresh the page',
                'menuEmpty'        => 'Menu is empty.',
                'searchEmptyTpl'   => 'Nothing found for “{q}”.',
                'categoryOther'    => 'Other',
                'priceFrom'        => 'from',
                'add'              => 'Add',
                'addModifTotal'    => 'Item price:',
                'modifExtras'      => 'Extras',
                'cart'             => 'Cart',
                'cartEmpty'        => 'Cart is empty — add something tasty',
                'commentLabel'     => 'Order comment',
                'commentPh'        => 'e.g. “extra spicy” or “no onions”',
                'foodTotal'        => 'Food',
                'deliveryRow'      => 'Delivery',
                'total'            => 'Total',
                'toCheckout'       => 'Checkout',
                'close'            => 'Close',
                'checkoutTitle'    => 'Delivery details',
                'nameLabel'        => 'Name',
                'namePh'           => 'What should we call you',
                'phoneLabel'       => 'Phone',
                'phonePh'          => '+84 / 0… (Telegram/Zalo — with +)',
                'addressLabel'     => 'Delivery address',
                'addressPh'        => 'Street, building — start typing…',
                'apartmentLabel'   => 'Apartment / floor / hotel',
                'apartmentPh'      => 'Apt 12, 3rd floor / hotel & room',
                'noteLabel'        => 'Landmark for the courier',
                'notePh'           => 'Entrance from the yard, blue door…',
                'quoteCalculating' => 'Calculating delivery cost…',
                'quoteUnavailable' => 'Delivery cost will be confirmed by our operator after checkout',
                'quoteOutOfZone'   => 'Address is outside our delivery zone (up to {km} km). Contact us — we’ll figure something out!',
                'quoteGeocodeFail' => 'Couldn’t find the address — refine it or add a landmark',
                'quoteRow'         => 'Delivery ({provider})',
                'quoteEtaTpl'      => '~{min} min',
                'quoteDistanceTpl' => '{km} km',
                'quoteCourierNote' => 'Delivery is paid to the courier on arrival — cash or QR',
                'minOrderTpl'      => 'Minimum order — {sum}',
                'placeOrder'       => 'Place order',
                'requiredField'    => 'Required field',
                'phoneInvalid'     => 'Check the phone number',
                'addressMissing'   => 'Enter the delivery address',
                'cartInvalid'      => 'Cart is empty or stale — refresh the page',
                'submitError'      => 'Couldn’t submit the order. Try again or give us a call.',
                'throttled'        => 'Too many orders in a row. Wait a bit or give us a call.',
                'success'          => 'Order received!',
                'orderAcceptedTpl' => 'Order #{id} sent to the kitchen',
                'payTitle'         => 'Pay for the food via QR',
                'payInstruction'   => 'Scan the QR in your banking app. Amount and reference are pre-filled — don’t change them.',
                'payAmount'        => 'Amount',
                'payReference'     => 'Reference',
                'payAccount'       => 'Account',
                'payAccountName'   => 'Recipient',
                'payPendingNote'   => 'We start cooking once the payment lands — usually a couple of minutes.',
                'payNotConfigured' => 'We’ll contact you about payment — we have your phone.',
                'deliveryCourier'  => 'Delivery is paid to the courier on arrival.',
                'dispatchOk'       => 'Courier is already booked — expect a call.',
                'newOrderBtn'      => 'New order',
                'errorGeneric'     => 'Error',
                'backToMenu'       => 'Back to menu',
                'qty'              => 'pcs',
            ],

            // ─── Vietnamese ───────────────────────────────────────
            'vi' => [
                'title'            => 'Giao hàng',
                'subtitle'         => 'Món ngon Veranda giao tận nơi khắp Nha Trang',
                'searchPh'         => 'Tìm trong thực đơn',
                'menuLoadError'    => 'Không tải được thực đơn — hãy tải lại trang',
                'menuEmpty'        => 'Thực đơn trống.',
                'searchEmptyTpl'   => 'Không tìm thấy «{q}».',
                'categoryOther'    => 'Khác',
                'priceFrom'        => 'từ',
                'add'              => 'Thêm',
                'addModifTotal'    => 'Cho món:',
                'modifExtras'      => 'Thêm lựa chọn',
                'cart'             => 'Giỏ hàng',
                'cartEmpty'        => 'Giỏ hàng trống — hãy thêm món ngon nhé',
                'commentLabel'     => 'Ghi chú đơn hàng',
                'commentPh'        => 'Ví dụ «cay hơn» hoặc «không hành»',
                'foodTotal'        => 'Món ăn',
                'deliveryRow'      => 'Giao hàng',
                'total'            => 'Tổng',
                'toCheckout'       => 'Đặt giao hàng',
                'close'            => 'Đóng',
                'checkoutTitle'    => 'Thông tin giao hàng',
                'nameLabel'        => 'Tên',
                'namePh'           => 'Chúng tôi gọi bạn là gì',
                'phoneLabel'       => 'Số điện thoại',
                'phonePh'          => '+84 / 0…',
                'addressLabel'     => 'Địa chỉ giao hàng',
                'addressPh'        => 'Đường, số nhà — bắt đầu nhập…',
                'apartmentLabel'   => 'Căn hộ / tầng / khách sạn',
                'apartmentPh'      => 'Căn 12, tầng 3 / khách sạn, phòng',
                'noteLabel'        => 'Mốc chỉ đường cho tài xế',
                'notePh'           => 'Vào từ sân sau, cửa màu xanh…',
                'quoteCalculating' => 'Đang tính phí giao hàng…',
                'quoteUnavailable' => 'Phí giao hàng sẽ được nhân viên xác nhận sau khi đặt',
                'quoteOutOfZone'   => 'Địa chỉ ngoài vùng giao hàng (tối đa {km} km). Hãy liên hệ với chúng tôi!',
                'quoteGeocodeFail' => 'Không tìm thấy địa chỉ — hãy nhập rõ hơn',
                'quoteRow'         => 'Giao hàng ({provider})',
                'quoteEtaTpl'      => '~{min} phút',
                'quoteDistanceTpl' => '{km} km',
                'quoteCourierNote' => 'Phí giao hàng trả cho tài xế khi nhận — tiền mặt hoặc QR',
                'minOrderTpl'      => 'Đơn tối thiểu — {sum}',
                'placeOrder'       => 'Xác nhận đặt hàng',
                'requiredField'    => 'Trường bắt buộc',
                'phoneInvalid'     => 'Kiểm tra lại số điện thoại',
                'addressMissing'   => 'Nhập địa chỉ giao hàng',
                'cartInvalid'      => 'Giỏ hàng trống hoặc đã cũ — tải lại trang',
                'submitError'      => 'Không gửi được đơn. Thử lại hoặc gọi cho chúng tôi.',
                'throttled'        => 'Quá nhiều đơn liên tiếp. Vui lòng đợi hoặc gọi cho chúng tôi.',
                'success'          => 'Đã nhận đơn!',
                'orderAcceptedTpl' => 'Đơn #{id} đã chuyển vào bếp',
                'payTitle'         => 'Thanh toán món ăn qua QR',
                'payInstruction'   => 'Quét QR bằng ứng dụng ngân hàng. Số tiền và nội dung đã điền sẵn — đừng thay đổi.',
                'payAmount'        => 'Số tiền',
                'payReference'     => 'Nội dung',
                'payAccount'       => 'Tài khoản',
                'payAccountName'   => 'Người nhận',
                'payPendingNote'   => 'Bếp bắt đầu nấu khi nhận được thanh toán — thường chỉ vài phút.',
                'payNotConfigured' => 'Chúng tôi sẽ liên hệ về thanh toán — đã có số của bạn.',
                'deliveryCourier'  => 'Phí giao hàng trả cho tài xế khi nhận hàng.',
                'dispatchOk'       => 'Tài xế đã được gọi — chờ điện thoại nhé.',
                'newOrderBtn'      => 'Đơn mới',
                'errorGeneric'     => 'Lỗi',
                'backToMenu'       => 'Về thực đơn',
                'qty'              => 'phần',
            ],
        ];
    }
}
