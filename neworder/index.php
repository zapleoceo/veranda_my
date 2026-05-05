<?php
$assetVersion = '20260505_0007';
header('X-Robots-Tag: noindex, nofollow', true);

$supportedLangs = ['ru', 'en', 'vi', 'ko'];
$lang = null;
$explicitLang = null;

if (isset($_GET['lang'])) {
    $candidate = strtolower(trim((string)$_GET['lang']));
    if (in_array($candidate, $supportedLangs, true)) {
        $lang = $candidate;
        $explicitLang = $lang;
        setcookie('links_lang', $lang, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax'
        ]);
    }
}

if ($lang === null) {
    $cookieLang = strtolower(trim((string)($_COOKIE['links_lang'] ?? '')));
    if (in_array($cookieLang, $supportedLangs, true)) {
        $lang = $cookieLang;
    }
}

if ($lang === null) {
    $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $parts = preg_split('/\s*,\s*/', $accept);
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $code = strtolower(trim(explode(';', $part, 2)[0]));
        $base = explode('-', $code, 2)[0];
        if (in_array($base, $supportedLangs, true)) {
            $lang = $base;
            break;
        }
    }
}

if ($lang === null) {
    $lang = 'ru';
}

$i18n = [
    'ru' => [
        'title' => 'Новый заказ',
        'cart' => 'Корзина',
        'search_placeholder' => 'Поиск блюд...',
        'search_results' => 'Результаты поиска',
        'search_loading' => 'Поиск...',
        'search_empty' => 'Ничего не найдено',
        'menu_loading' => 'Загрузка меню...',
        'empty_cart' => 'Корзина пуста',
        'total' => 'Итого:',
        'checkout' => 'Оформление заказа',
        'name' => 'Имя',
        'name_ph' => 'Как к вам обращаться?',
        'phone' => 'Телефон',
        'phone_ph' => 'Ваш номер телефона',
        'order_type' => 'Тип заказа',
        'in_place' => 'В заведении',
        'takeaway' => 'С собой',
        'submit' => 'Подтвердить заказ',
    ],
    'en' => [
        'title' => 'New order',
        'cart' => 'Cart',
        'search_placeholder' => 'Search dishes...',
        'search_results' => 'Search results',
        'search_loading' => 'Searching...',
        'search_empty' => 'Nothing found',
        'menu_loading' => 'Loading menu...',
        'empty_cart' => 'Cart is empty',
        'total' => 'Total:',
        'checkout' => 'Checkout',
        'name' => 'Name',
        'name_ph' => 'Your name',
        'phone' => 'Phone',
        'phone_ph' => 'Your phone number',
        'order_type' => 'Order type',
        'in_place' => 'Dine in',
        'takeaway' => 'Takeaway',
        'submit' => 'Place order',
    ],
    'vi' => [
        'title' => 'Đơn mới',
        'cart' => 'Giỏ hàng',
        'search_placeholder' => 'Tìm món...',
        'search_results' => 'Kết quả tìm kiếm',
        'search_loading' => 'Đang tìm...',
        'search_empty' => 'Không tìm thấy',
        'menu_loading' => 'Đang tải menu...',
        'empty_cart' => 'Giỏ hàng trống',
        'total' => 'Tổng:',
        'checkout' => 'Xác nhận đơn',
        'name' => 'Tên',
        'name_ph' => 'Bạn tên gì?',
        'phone' => 'Số điện thoại',
        'phone_ph' => 'Số điện thoại của bạn',
        'order_type' => 'Loại đơn',
        'in_place' => 'Dùng tại chỗ',
        'takeaway' => 'Mang đi',
        'submit' => 'Đặt hàng',
    ],
    'ko' => [
        'title' => '새 주문',
        'cart' => '장바구니',
        'search_placeholder' => '메뉴 검색...',
        'search_results' => '검색 결과',
        'search_loading' => '검색 중...',
        'search_empty' => '검색 결과 없음',
        'menu_loading' => '메뉴 불러오는 중...',
        'empty_cart' => '장바구니가 비어있습니다',
        'total' => '합계:',
        'checkout' => '주문하기',
        'name' => '이름',
        'name_ph' => '이름을 입력하세요',
        'phone' => '전화번호',
        'phone_ph' => '전화번호를 입력하세요',
        'order_type' => '주문 방식',
        'in_place' => '매장 식사',
        'takeaway' => '포장',
        'submit' => '주문 확정',
    ],
];

$t = $i18n[$lang] ?? $i18n['ru'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
  <meta name="robots" content="noindex, nofollow">
  <title><?= htmlspecialchars((string)($t['title'] ?? '')) ?></title>
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/neworder/assets/style.css?v=<?= $assetVersion ?>">
</head>
<body>
  <div class="container">
    <h1 class="page-title" id="pageTitle"><?= htmlspecialchars((string)($t['title'] ?? '')) ?></h1>

    <div class="search-bar" id="searchBar">
      <div class="search-input-wrap">
        <input type="search" id="productSearchInput" class="search-input" placeholder="<?= htmlspecialchars((string)($t['search_placeholder'] ?? '')) ?>" autocomplete="off">
        <button type="button" class="search-clear-in" id="productSearchClear" hidden>×</button>
      </div>
      <details class="lang-menu" id="langMenu">
        <summary aria-label="Language">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm7.93 9h-3.2a15.7 15.7 0 0 0-1.47-5 8.05 8.05 0 0 1 4.67 5ZM12 4c1.1 0 2.7 2.2 3.4 7H8.6C9.3 6.2 10.9 4 12 4ZM4.07 11a8.05 8.05 0 0 1 4.67-5 15.7 15.7 0 0 0-1.47 5Zm0 2h3.2a15.7 15.7 0 0 0 1.47 5 8.05 8.05 0 0 1-4.67-5ZM12 20c-1.1 0-2.7-2.2-3.4-7h6.8c-.7 4.8-2.3 7-3.4 7Zm3.26-2a15.7 15.7 0 0 0 1.47-5h3.2a8.05 8.05 0 0 1-4.67 5Z"/></svg>
        </summary>
        <div class="lang-panel">
          <a href="?lang=ru" class="<?= $lang === 'ru' ? 'active' : '' ?>" aria-label="Русский">Русский</a>
          <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>" aria-label="English">English</a>
          <a href="?lang=vi" class="<?= $lang === 'vi' ? 'active' : '' ?>" aria-label="Tiếng Việt">Tiếng Việt</a>
          <a href="?lang=ko" class="<?= $lang === 'ko' ? 'active' : '' ?>" aria-label="한국어">한국어</a>
        </div>
      </details>
    </div>

    <div class="content-wrapper">
      <div class="categories-sidebar" id="categoriesSidebar">
        <!-- Categories injected here -->
      </div>
      <div class="menu-main" id="menuMain">
        <div class="menu-sections" id="menuSections">
          <!-- Menu injected here -->
          <div class="loading-state" id="loadingState"><?= htmlspecialchars((string)($t['menu_loading'] ?? '')) ?></div>
        </div>
      </div>
      
      <!-- Right Panel: Cart & Checkout -->
      <div class="cart-sidebar" id="cartSidebar">
        <div class="cart-panel">
          <h2>
            <span id="cartTitle"><?= htmlspecialchars((string)($t['cart'] ?? '')) ?></span>
            <span class="cart-badge" id="cartBadge" hidden>0</span>
          </h2>
          <div class="empty-cart" id="emptyCart"><?= htmlspecialchars((string)($t['empty_cart'] ?? '')) ?></div>
          <div class="cart-items" id="cartItems"></div>
          
          <div class="cart-footer" id="cartFooter" hidden>
            <div class="cart-total"><span id="cartTotalLabel"><?= htmlspecialchars((string)($t['total'] ?? '')) ?></span> <span id="cartTotalSum">0</span></div>
            
            <div class="checkout-form-container">
              <h3 id="checkoutTitle"><?= htmlspecialchars((string)($t['checkout'] ?? '')) ?></h3>
              <form id="checkoutForm">
                <div class="form-group">
                  <label id="labelHall">Hall</label>
                  <select id="hallIdSelect"></select>
                </div>
                <div class="form-group">
                  <label id="labelSpot">Spot</label>
                  <select id="spotIdSelect"></select>
                </div>
                <div class="form-group">
                  <label id="labelTable">Table</label>
                  <select id="tableIdSelect"></select>
                </div>
                <div class="form-group">
                  <label id="labelName"><?= htmlspecialchars((string)($t['name'] ?? '')) ?></label>
                  <input type="text" id="orderName" required placeholder="<?= htmlspecialchars((string)($t['name_ph'] ?? '')) ?>">
                </div>
                <div class="form-group">
                  <label id="labelServiceMode"><?= htmlspecialchars((string)($t['order_type'] ?? '')) ?></label>
                  <div class="service-mode-toggle">
                    <label class="sm-label">
                      <input type="radio" name="service_mode" value="3" checked>
                      <span id="serviceInPlaceLabel"><?= htmlspecialchars((string)($t['in_place'] ?? '')) ?></span>
                    </label>
                    <label class="sm-label">
                      <input type="radio" name="service_mode" value="2">
                      <span id="serviceTakeawayLabel"><?= htmlspecialchars((string)($t['takeaway'] ?? '')) ?></span>
                    </label>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="submitBtn"><?= htmlspecialchars((string)($t['submit'] ?? '')) ?></button>
                <div id="checkoutError" class="error-msg" hidden></div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="toast" id="toast" hidden></div>

  <script src="/neworder/assets/app.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
