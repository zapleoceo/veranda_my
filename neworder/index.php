<?php
$title = 'Новый заказ';
$assetVersion = '20260417_001';
header('X-Robots-Tag: noindex, nofollow', true);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
  <meta name="robots" content="noindex, nofollow">
  <title><?= htmlspecialchars($title) ?></title>
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/neworder/assets/style.css?v=<?= $assetVersion ?>">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/libphonenumber-js/1.10.49/libphonenumber-js.min.js" defer></script>
</head>
<body>
  <?php require $_SERVER['DOCUMENT_ROOT'] . '/partials/user_menu.php'; ?>
  
  <div class="container">
    <div class="top-header">
      <div class="header-left">
        <h1 class="page-title">Новый заказ</h1>
      </div>
      <div class="header-right">
        <button class="btn btn-cart" id="cartBtn" onclick="toggleCartModal()">
          Корзина <span class="cart-badge" id="cartBadge" hidden>0</span>
        </button>
      </div>
    </div>

    <div class="search-bar">
      <input type="search" id="productSearchInput" class="search-input" placeholder="Поиск блюд..." autocomplete="off">
      <button type="button" class="btn search-clear" id="productSearchClear" hidden>×</button>
    </div>

    <div class="content-wrapper">
      <div class="categories-sidebar" id="categoriesSidebar">
        <!-- Categories injected here -->
      </div>
      <div class="menu-main" id="menuMain">
        <div class="search-section" id="searchSection" hidden>
          <div class="menu-section-title" id="searchTitle">Результаты поиска</div>
          <div class="loading-state" id="searchLoading" hidden>Поиск...</div>
          <div class="loading-state" id="searchEmpty" hidden>Ничего не найдено</div>
          <div class="products-grid" id="searchGrid"></div>
        </div>
        <div class="menu-sections" id="menuSections">
          <!-- Menu injected here -->
          <div class="loading-state" id="loadingState">Загрузка меню...</div>
        </div>
      </div>
      
      <!-- Right Panel: Cart & Checkout -->
      <div class="cart-sidebar" id="cartSidebar">
        <div class="cart-panel">
          <h2>
            Корзина
            <button class="close-cart-btn" onclick="closeCheckoutModal()">×</button>
          </h2>
          <div class="empty-cart" id="emptyCart">Корзина пуста</div>
          <div class="cart-items" id="cartItems"></div>
          
          <div class="cart-footer" id="cartFooter" hidden>
            <div class="cart-total">Итого: <span id="cartTotalSum">0</span></div>
            
            <div class="checkout-form-container">
              <h3>Оформление заказа</h3>
              <form id="checkoutForm" onsubmit="submitOrder(event)">
                <div class="form-group">
                  <label>Имя</label>
                  <input type="text" id="orderName" required placeholder="Как к вам обращаться?">
                </div>
                <div class="form-group">
                  <label>Телефон</label>
                  <input type="tel" id="orderPhone" required placeholder="Ваш номер телефона">
                </div>
                <div class="form-group">
                  <label>Тип заказа</label>
                  <div class="service-mode-toggle">
                    <label class="sm-label">
                      <input type="radio" name="service_mode" value="3" checked>
                      <span>В заведении</span>
                    </label>
                    <label class="sm-label">
                      <input type="radio" name="service_mode" value="2">
                      <span>С собой</span>
                    </label>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="submitBtn">Подтвердить заказ</button>
                <div id="checkoutError" class="error-msg" hidden></div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="toast" id="toast" hidden></div>

  <script src="/assets/user_menu.js?v=<?= $assetVersion ?>" defer></script>
  <script src="/neworder/assets/app.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
