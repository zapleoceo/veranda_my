# /onlineorder — что получить утром и куда вписать

Страница **https://veranda.my/onlineorder** уже работает end-to-end:
меню живое из Poster, заказ улетает в Poster (`incomingOrders.createIncomingOrder`,
service_mode=3 доставка), алерт в Telegram, стоимость доставки считается
fallback-тарифом по расстоянию (Nominatim-геокодер, без ключей).

Ниже — 4 интеграции, которые включаются **только ключами в `.env`**
(код готов, деплоить ничего не нужно). После правки `.env` ничего
перезапускать не надо — конфиг читается на каждый запрос.

---

## 1. VietQR — оплата еды по QR (5 минут, без регистрации) ⭐ начни с этого

QR генерится бесплатным image-API `img.vietqr.io` — нужны только реквизиты счёта.

```env
VIETQR_BANK_BIN=970418        # BIN банка (970418 = BIDV, 970436 = VCB, 970422 = MB…)
VIETQR_ACCOUNT_NO=XXXXXXXXXX  # номер счёта получателя
VIETQR_ACCOUNT_NAME=NGUYEN VAN A   # имя ЛАТИНИЦЕЙ, как в банке
```

Полный список BIN: https://api.vietqr.io/v2/banks (поле `bin`).
Проверка: оформи тестовый заказ — на экране успеха появится QR с суммой
и назначением `VERANDA <id>`. Деньги падают сразу на счёт; сверка пока
руками (Telegram-алерт напоминает «проверить поступление»). Автосверку
через SePay-webhook добавим следующим шагом — интерфейс уже заложен.

## 2. Google Maps / Places — подсказки адреса при вводе (15 минут)

1. https://console.cloud.google.com → проект → APIs & Services.
2. Включить **Maps JavaScript API** + **Places API** (+ **Geocoding API** — желательно).
3. Credentials → Create API key:
   - **Browser key**: ограничение HTTP referrers `veranda.my/*` → в `GOOGLE_MAPS_API_KEY`
   - (опц.) **Server key**: ограничение по IP сервера → в `GOOGLE_MAPS_SERVER_KEY`
4. Привязать billing (есть бесплатные $200/мес — нам хватит с запасом).

```env
ONLINEORDER_GEOCODER=google
GOOGLE_MAPS_API_KEY=AIza...      # browser, виден публично — это нормально
GOOGLE_MAPS_SERVER_KEY=AIza...   # server; можно не делать — возьмётся browser-key
```

Без ключа страница работает: обычное текстовое поле, адрес геокодит
Nominatim (OSM) на сервере.

## 3. Grab Express — живой расчёт доставки + автовызов курьера

Нужен партнёрский доступ GrabExpress:
1. https://developer.grab.com → Sign up → создать app с продуктом **GrabExpress** (Vietnam).
2. Получить `Client ID` + `Client Secret` (сначала sandbox).

```env
ONLINEORDER_DELIVERY_PROVIDER=grab
GRAB_CLIENT_ID=...
GRAB_CLIENT_SECRET=...
GRAB_SANDBOX=true                # боевой режим: false (после approve)
```

Что включится: на чек-ауте цена/время доставки из Grab вместо тарифа по км;
вызов курьера — пока вручную оператором (кнопка-автомат `ONLINEORDER_AUTO_DISPATCH=true`
включай только когда появится автоподтверждение оплаты, иначе курьер
поедет за неоплаченным заказом).

## 4. Maxim — альтернатива Grab (дешевле в Нячанге)

Публичного API нет — только партнёрский договор:
- b2b@taximaxim.com или местный офис Maxim Vietnam (приложение → «для бизнеса»),
- просить «API для вызова доставки от юрлица/ресторана».
Выдадут base-URL + ключ + спецификацию.

```env
ONLINEORDER_DELIVERY_PROVIDER=maxim
MAXIM_API_BASE=...
MAXIM_API_KEY=...
MAXIM_CITY_ID=...
```

⚠️ Когда придёт их спецификация — сверить поля в
`src/OnlineOrder/Services/MaximDeliveryProvider.php` (методы `mapPayload()` /
`parseQuote()` помечены комментарием). Это единственное место под правку.

---

## Обязательно проверить/поправить сразу

```env
# Точные координаты ресторана (сейчас — центр Нячанга, ЗАМЕНИТЬ на точку Veranda):
ONLINEORDER_RESTAURANT_LAT=12.2xxxxx
ONLINEORDER_RESTAURANT_LNG=109.1xxxxx
ONLINEORDER_RESTAURANT_ADDRESS=Veranda, <улица, дом>, Nha Trang
ONLINEORDER_PHONE=+84xxxxxxxxx        # телефон для Grab-отправителя

# Куда слать алерты о заказах (если пусто — упадёт в TELEGRAM_CHAT_ID):
ONLINEORDER_TG_CHAT_ID=
```

Координаты: Google Maps → ПКМ по точке ресторана → первая строка — lat, lng.

## Параметры на вкус

```env
ONLINEORDER_MIN_ORDER_VND=100000      # мин. заказ (0 = выключен)
ONLINEORDER_MAX_RADIUS_KM=15          # радиус зоны доставки
ONLINEORDER_DELIVERY_BASE_VND=15000   # fallback-тариф: посадка
ONLINEORDER_DELIVERY_PER_KM_VND=5000  #   + за км (по дороге, ×1.3 к прямой)
```

## Как это устроено (карта кода)

| Слой | Файлы |
|---|---|
| Страница | `src/Views/onlineorder/index.php`, `onlineorder/assets/*` |
| HTTP | `src/OnlineOrder/Http/` — контроллер + QuoteAction + OrderCreateAction |
| Контракты | `src/OnlineOrder/Contracts/` — Geocoder, DeliveryQuoteProvider, TaxiDispatch, IncomingOrderService, PaymentQrProvider, OrderNotifier |
| Реализации | `src/OnlineOrder/Services/` — Google/Nominatim, Grab/Maxim/Distance/Null, VietQR, IncomingOrderService (Poster), TelegramOrderNotifier |
| Выбор реализаций | `src/Bootstrap/container.php` (секция `/onlineorder`) — по `.env`, без правок кода |
| Переиспользовано из /neworder | живое меню Poster, CartLine, CSRF-guard, JsonResponse |

Защита: CSRF+Origin на мутациях, honeypot, троттлинг 3 заказа/10 мин/сессия,
цены и состав пересчитываются сервером по живому меню Poster (клиенту не верим),
радиус и квоут перепроверяются при сабмите.
