const d = document;
let menuData = [];
let cart = {}; // product_id -> { item, count }

const elCategories = d.getElementById('categoriesSidebar');
const elMenuMain = d.getElementById('menuMain');
const elMenuSections = d.getElementById('menuSections');
const elCartSidebar = d.getElementById('cartSidebar');
const elCartBadge = d.getElementById('cartBadge');
const elCartItems = d.getElementById('cartItems');
const elCartTotal = d.getElementById('cartTotalSum');
const elCartFooter = d.getElementById('cartFooter');
const elEmptyCart = d.getElementById('emptyCart');
const elToast = d.getElementById('toast');

const elSearchInput = d.getElementById('productSearchInput');
const elSearchClear = d.getElementById('productSearchClear');
const elSearchSection = d.getElementById('searchSection');
const elSearchTitle = d.getElementById('searchTitle');
const elSearchLoading = d.getElementById('searchLoading');
const elSearchEmpty = d.getElementById('searchEmpty');
const elSearchGrid = d.getElementById('searchGrid');

const elPageTitle = d.getElementById('pageTitle');
const elCartBtnLabel = d.getElementById('cartBtnLabel');
const elCartTitle = d.getElementById('cartTitle');
const elCartTotalLabel = d.getElementById('cartTotalLabel');
const elCheckoutTitle = d.getElementById('checkoutTitle');
const elLabelName = d.getElementById('labelName');
const elLabelPhone = d.getElementById('labelPhone');
const elLabelServiceMode = d.getElementById('labelServiceMode');
const elServiceInPlaceLabel = d.getElementById('serviceInPlaceLabel');
const elServiceTakeawayLabel = d.getElementById('serviceTakeawayLabel');
const elOrderName = d.getElementById('orderName');
const elOrderPhone = d.getElementById('orderPhone');
const elSubmitBtn = d.getElementById('submitBtn');
const elLoadingState = d.getElementById('loadingState');
const elLangMenu = d.getElementById('langMenu');

const fmtPrice = (val) => new Intl.NumberFormat('vi-VN').format(val) + ' đ';

let posterProducts = [];
let posterSpotId = 1;
const lsProductsKey = 'neworder_poster_products_v1';
const lsProductsTsKey = 'neworder_poster_products_ts_v1';

const supportedLangs = ['ru', 'en', 'vi', 'ko'];
let currentLang = (document.documentElement.getAttribute('lang') || 'ru').toLowerCase();
if (!supportedLangs.includes(currentLang)) currentLang = 'ru';

const i18n = {
  ru: {
    title: 'Новый заказ',
    cart: 'Корзина',
    searchPlaceholder: 'Поиск блюд...',
    searchResultsPrefix: 'Результаты поиска',
    searchLoading: 'Поиск...',
    searchEmpty: 'Ничего не найдено',
    menuLoading: 'Загрузка меню...',
    menuEmpty: 'Меню пусто',
    loadMenuFailPrefix: 'Не удалось загрузить меню: ',
    addedPrefix: 'Добавлено: ',
    emptyCart: 'Корзина пуста',
    total: 'Итого:',
    checkout: 'Оформление заказа',
    name: 'Имя',
    namePh: 'Как к вам обращаться?',
    phone: 'Телефон',
    phonePh: 'Ваш номер телефона',
    orderType: 'Тип заказа',
    inPlace: 'В заведении',
    takeaway: 'С собой',
    submit: 'Подтвердить заказ',
    sending: 'Отправка...',
    phoneInvalid: 'Проверьте корректность номера телефона',
    orderUnknown: 'Неизвестная ошибка',
    orderSuccessPrefix: 'Заказ успешно создан! ID: ',
    searchFail: 'Ошибка поиска',
  },
  en: {
    title: 'New order',
    cart: 'Cart',
    searchPlaceholder: 'Search dishes...',
    searchResultsPrefix: 'Search results',
    searchLoading: 'Searching...',
    searchEmpty: 'Nothing found',
    menuLoading: 'Loading menu...',
    menuEmpty: 'Menu is empty',
    loadMenuFailPrefix: 'Failed to load menu: ',
    addedPrefix: 'Added: ',
    emptyCart: 'Cart is empty',
    total: 'Total:',
    checkout: 'Checkout',
    name: 'Name',
    namePh: 'Your name',
    phone: 'Phone',
    phonePh: 'Your phone number',
    orderType: 'Order type',
    inPlace: 'Dine in',
    takeaway: 'Takeaway',
    submit: 'Place order',
    sending: 'Sending...',
    phoneInvalid: 'Check phone number',
    orderUnknown: 'Unknown error',
    orderSuccessPrefix: 'Order created! ID: ',
    searchFail: 'Search error',
  },
  vi: {
    title: 'Đơn mới',
    cart: 'Giỏ hàng',
    searchPlaceholder: 'Tìm món...',
    searchResultsPrefix: 'Kết quả tìm kiếm',
    searchLoading: 'Đang tìm...',
    searchEmpty: 'Không tìm thấy',
    menuLoading: 'Đang tải menu...',
    menuEmpty: 'Không có món',
    loadMenuFailPrefix: 'Không tải được menu: ',
    addedPrefix: 'Đã thêm: ',
    emptyCart: 'Giỏ hàng trống',
    total: 'Tổng:',
    checkout: 'Xác nhận đơn',
    name: 'Tên',
    namePh: 'Bạn tên gì?',
    phone: 'Số điện thoại',
    phonePh: 'Số điện thoại của bạn',
    orderType: 'Loại đơn',
    inPlace: 'Dùng tại chỗ',
    takeaway: 'Mang đi',
    submit: 'Đặt hàng',
    sending: 'Đang gửi...',
    phoneInvalid: 'Kiểm tra số điện thoại',
    orderUnknown: 'Lỗi không xác định',
    orderSuccessPrefix: 'Đã tạo đơn! ID: ',
    searchFail: 'Lỗi tìm kiếm',
  },
  ko: {
    title: '새 주문',
    cart: '장바구니',
    searchPlaceholder: '메뉴 검색...',
    searchResultsPrefix: '검색 결과',
    searchLoading: '검색 중...',
    searchEmpty: '검색 결과 없음',
    menuLoading: '메뉴 불러오는 중...',
    menuEmpty: '메뉴가 없습니다',
    loadMenuFailPrefix: '메뉴 로드 실패: ',
    addedPrefix: '추가됨: ',
    emptyCart: '장바구니가 비어있습니다',
    total: '합계:',
    checkout: '주문하기',
    name: '이름',
    namePh: '이름을 입력하세요',
    phone: '전화번호',
    phonePh: '전화번호를 입력하세요',
    orderType: '주문 방식',
    inPlace: '매장 식사',
    takeaway: '포장',
    submit: '주문 확정',
    sending: '전송 중...',
    phoneInvalid: '전화번호를 확인하세요',
    orderUnknown: '알 수 없는 오류',
    orderSuccessPrefix: '주문 생성됨! ID: ',
    searchFail: '검색 오류',
  }
};

function cookieSet(name, value) {
  const maxAge = 31536000;
  document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; Path=/; Max-Age=${maxAge}; SameSite=Lax`;
}

function setActiveLangLink(lang) {
  if (!elLangMenu) return;
  const links = Array.from(elLangMenu.querySelectorAll('.lang-panel a'));
  links.forEach((a) => {
    const href = String(a.getAttribute('href') || '');
    const m = href.match(/[?&]lang=([a-zA-Z-]+)/);
    const code = m ? m[1].toLowerCase() : '';
    a.classList.toggle('active', code === lang);
  });
}

function applyLang(lang) {
  const next = String(lang || '').toLowerCase();
  if (!supportedLangs.includes(next)) return;
  currentLang = next;
  const t = i18n[currentLang] || i18n.ru;

  document.documentElement.setAttribute('lang', currentLang);
  document.title = t.title;
  setActiveLangLink(currentLang);

  if (elPageTitle) elPageTitle.textContent = t.title;
  if (elCartBtnLabel) elCartBtnLabel.textContent = t.cart;
  if (elCartTitle) elCartTitle.textContent = t.cart;
  if (elEmptyCart) elEmptyCart.textContent = t.emptyCart;
  if (elCartTotalLabel) elCartTotalLabel.textContent = t.total;
  if (elCheckoutTitle) elCheckoutTitle.textContent = t.checkout;
  if (elLabelName) elLabelName.textContent = t.name;
  if (elLabelPhone) elLabelPhone.textContent = t.phone;
  if (elLabelServiceMode) elLabelServiceMode.textContent = t.orderType;
  if (elServiceInPlaceLabel) elServiceInPlaceLabel.textContent = t.inPlace;
  if (elServiceTakeawayLabel) elServiceTakeawayLabel.textContent = t.takeaway;
  if (elSubmitBtn && !elSubmitBtn.disabled) elSubmitBtn.textContent = t.submit;
  if (elOrderName) elOrderName.placeholder = t.namePh;
  if (elOrderPhone) elOrderPhone.placeholder = t.phonePh;
  if (elLoadingState) elLoadingState.textContent = t.menuLoading;
  if (elSearchInput) elSearchInput.placeholder = t.searchPlaceholder;
  if (elSearchLoading) elSearchLoading.textContent = t.searchLoading;
  if (elSearchEmpty) elSearchEmpty.textContent = t.searchEmpty;

  const url = new URL(window.location.href);
  url.searchParams.set('lang', currentLang);
  window.history.replaceState({}, '', url.toString());
  cookieSet('links_lang', currentLang);
}

function extractPosterPriceCents(p) {
  const spots = p && p.spots ? p.spots : null;
  if (Array.isArray(spots)) {
    for (const s of spots) {
      if (!s || typeof s !== 'object') continue;
      if (Number(s.spot_id || 0) !== Number(posterSpotId || 0)) continue;
      if (String(s.visible || '1') === '0') continue;
      const v = s.price;
      if (typeof v === 'number' && Number.isFinite(v)) return Math.trunc(v);
      if (typeof v === 'string' && /^\d+$/.test(v)) return Number(v);
    }
  }

  const price = p && p.price ? p.price : null;
  if (price && typeof price === 'object' && !Array.isArray(price)) {
    const key = String(posterSpotId);
    if (price[key] != null && /^\d+$/.test(String(price[key]))) return Number(price[key]);
    for (const k of Object.keys(price)) {
      if (price[k] != null && /^\d+$/.test(String(price[k]))) return Number(price[k]);
    }
  }

  return 0;
}

function buildMenuFromPosterProducts(products) {
  const catMap = new Map();

  (products || []).forEach((p) => {
    if (!p || typeof p !== 'object') return;
    if (String(p.hidden || '0') === '1') return;

    const categoryId = Number(p.menu_category_id || 0) || 0;
    const categoryName = String(p.category_name || '').trim() || '—';
    const productId = Number(p.product_id || 0) || 0;
    const productName = String(p.product_name || '').trim();
    if (!productId || !productName) return;

    const key = `${categoryId}|${categoryName}`;
    if (!catMap.has(key)) {
      catMap.set(key, {
        id: categoryId || Math.abs(hashString(categoryName)),
        title: categoryName,
        items: [],
      });
    }

    const item = {
      id: productId,
      product_id: productId,
      product_name: productName,
      name: productName,
      desc: categoryName,
      price: extractPosterPriceCents(p),
    };

    catMap.get(key).items.push(item);
  });

  const groups = Array.from(catMap.values());
  groups.forEach((g) => {
    g.items.sort((a, b) => String(a.product_name || '').localeCompare(String(b.product_name || ''), undefined, { sensitivity: 'base' }));
  });
  groups.sort((a, b) => String(a.title || '').localeCompare(String(b.title || ''), undefined, { sensitivity: 'base' }));
  return groups;
}

function hashString(s) {
  let h = 0;
  const str = String(s || '');
  for (let i = 0; i < str.length; i++) {
    h = ((h << 5) - h) + str.charCodeAt(i);
    h |= 0;
  }
  return h || 1;
}

async function loadProducts() {
  try {
    const res = await fetch('/neworder/api.php?ajax=get_products', { headers: { 'Accept': 'application/json' } });
    const json = await res.json();
    const t = i18n[currentLang] || i18n.ru;
    if (!json.ok) throw new Error(json.error || t.loadMenuFailPrefix.trim());
    posterProducts = Array.isArray(json.products) ? json.products : [];
    posterSpotId = Number(json.spot_id || 1) || 1;

    try {
      localStorage.setItem(lsProductsKey, JSON.stringify({ products: posterProducts, spot_id: posterSpotId }));
      localStorage.setItem(lsProductsTsKey, String(Date.now()));
    } catch (e) {}

    menuData = buildMenuFromPosterProducts(posterProducts);
    renderMenu();
  } catch (err) {
    const t = i18n[currentLang] || i18n.ru;
    elMenuSections.innerHTML = `<div class="error-msg">${t.loadMenuFailPrefix}${err.message}</div>`;
  }
}

function renderMenu() {
  if (!menuData.length) {
    const t = i18n[currentLang] || i18n.ru;
    elMenuSections.innerHTML = `<div class="loading-state">${t.menuEmpty}</div>`;
    return;
  }
  
  elCategories.innerHTML = '';
  elMenuSections.innerHTML = '';

  menuData.forEach((group, idx) => {
    // Category Link
    const a = d.createElement('a');
    a.className = 'category-link';
    a.textContent = group.title;
    a.href = `#cat-${group.id}`;
    if (idx === 0) a.classList.add('active');
    
    a.onclick = (e) => {
      d.querySelectorAll('.category-link').forEach(link => link.classList.remove('active'));
      a.classList.add('active');
    };
    elCategories.appendChild(a);

    // Section
    const section = d.createElement('div');
    section.className = 'menu-section';
    section.id = `cat-${group.id}`;

    const title = d.createElement('div');
    title.className = 'menu-section-title';
    title.textContent = group.title;
    section.appendChild(title);

    const grid = d.createElement('div');
    grid.className = 'products-grid';

    (group.items || []).forEach(item => {
      const priceVal = Number(item.price || 0) / 100;
      
      const card = d.createElement('div');
      card.className = 'product-card';
      
      const name = d.createElement('h3');
      name.className = 'product-name';
      name.textContent = item.name;

      const desc = d.createElement('p');
      desc.className = 'product-desc';
      desc.textContent = item.desc || '';

      const footer = d.createElement('div');
      footer.className = 'product-footer';

      const price = d.createElement('div');
      price.className = 'product-price';
      price.textContent = fmtPrice(priceVal);

      const addBtn = d.createElement('button');
      addBtn.className = 'product-add';
      addBtn.textContent = '+';
      addBtn.onclick = () => addToCart(item, priceVal);

      footer.appendChild(price);
      footer.appendChild(addBtn);

      card.appendChild(name);
      card.appendChild(desc);
      card.appendChild(footer);
      grid.appendChild(card);
    });

    section.appendChild(grid);
    elMenuSections.appendChild(section);
  });
}

function addToCart(item, priceVal) {
  const pid = item.id;
  if (!cart[pid]) {
    cart[pid] = { item: item, price: priceVal, count: 1 };
  } else {
    cart[pid].count++;
  }
  updateCartBadge();
  renderCart();
  const t = i18n[currentLang] || i18n.ru;
  showToast(`${t.addedPrefix}${item.name}`);
}

function updateCart(pid, delta) {
  if (!cart[pid]) return;
  cart[pid].count += delta;
  if (cart[pid].count <= 0) {
    delete cart[pid];
  }
  updateCartBadge();
  renderCart();
}

function updateCartBadge() {
  let totalCount = 0;
  Object.values(cart).forEach(c => totalCount += c.count);
  elCartBadge.textContent = totalCount;
  elCartBadge.hidden = totalCount === 0;
}

function renderCart() {
  elCartItems.innerHTML = '';
  const items = Object.values(cart);
  
  if (items.length === 0) {
    elEmptyCart.hidden = false;
    elCartFooter.hidden = true;
    return;
  }
  
  elEmptyCart.hidden = true;
  elCartFooter.hidden = false;

  let sum = 0;

  items.forEach(c => {
    sum += c.price * c.count;
    
    const row = d.createElement('div');
    row.className = 'cart-item';

    const info = d.createElement('div');
    info.className = 'cart-item-info';
    info.innerHTML = `
      <div class="cart-item-name">${c.item.name}</div>
      <div class="cart-item-price">${fmtPrice(c.price * c.count)}</div>
    `;

    const controls = d.createElement('div');
    controls.className = 'cart-item-controls';

    const minus = d.createElement('button');
    minus.className = 'qty-btn';
    minus.textContent = '−';
    minus.onclick = () => updateCart(c.item.id, -1);

    const count = d.createElement('div');
    count.style.fontWeight = 'bold';
    count.textContent = c.count;

    const plus = d.createElement('button');
    plus.className = 'qty-btn';
    plus.textContent = '+';
    plus.onclick = () => updateCart(c.item.id, 1);

    controls.appendChild(minus);
    controls.appendChild(count);
    controls.appendChild(plus);

    row.appendChild(info);
    row.appendChild(controls);
    elCartItems.appendChild(row);
  });

  elCartTotal.textContent = fmtPrice(sum);
}

function toggleCartModal() {
  if (window.innerWidth <= 800) {
    if (elCartSidebar.classList.contains('open')) {
      elCartSidebar.classList.remove('open');
      d.body.style.overflow = '';
    } else {
      elCartSidebar.classList.add('open');
      d.body.style.overflow = 'hidden';
      renderCart();
    }
  } else {
    // On desktop, cart is always visible in the grid, but we can jump to it
    elCartSidebar.scrollIntoView({ behavior: 'smooth' });
  }
}

function openCheckoutModal() {
  // Not used anymore as checkout is inline
}

function closeCheckoutModal() {
  if (window.innerWidth <= 800) {
    elCartSidebar.classList.remove('open');
    d.body.style.overflow = '';
  }
}

function showToast(msg, isError = false) {
  elToast.textContent = msg;
  elToast.className = 'toast ' + (isError ? 'error' : '');
  elToast.hidden = false;
  setTimeout(() => elToast.hidden = true, 3000);
}

let searchTimer = null;

function setSearchActive(active) {
  elSearchSection.hidden = !active;
  elMenuSections.hidden = active;
  elCategories.hidden = active;
  if (!active) {
    elSearchGrid.innerHTML = '';
    elSearchLoading.hidden = true;
    elSearchEmpty.hidden = true;
  }
}

function renderSearchResults(query, products) {
  const t = i18n[currentLang] || i18n.ru;
  elSearchTitle.textContent = `${t.searchResultsPrefix}: ${query}`;
  elSearchGrid.innerHTML = '';

  (products || []).forEach(item => {
    const priceVal = Number(item.price || 0) / 100;

    const card = d.createElement('div');
    card.className = 'product-card';

    const name = d.createElement('h3');
    name.className = 'product-name';
    name.textContent = item.name;

    const desc = d.createElement('p');
    desc.className = 'product-desc';
    desc.textContent = item.desc || '';

    const footer = d.createElement('div');
    footer.className = 'product-footer';

    const price = d.createElement('div');
    price.className = 'product-price';
    price.textContent = fmtPrice(priceVal);

    const addBtn = d.createElement('button');
    addBtn.className = 'product-add';
    addBtn.textContent = '+';
    addBtn.onclick = () => addToCart(item, priceVal);

    footer.appendChild(price);
    footer.appendChild(addBtn);

    card.appendChild(name);
    card.appendChild(desc);
    card.appendChild(footer);
    elSearchGrid.appendChild(card);
  });

  elSearchLoading.hidden = true;
  elSearchEmpty.hidden = (products || []).length > 0;
}

function doSearch(query) {
  elSearchLoading.hidden = false;
  elSearchEmpty.hidden = true;
  elSearchGrid.innerHTML = '';

  const q = String(query || '').trim().toLowerCase();
  const out = [];
  for (const p of posterProducts || []) {
    if (!p || typeof p !== 'object') continue;
    if (String(p.hidden || '0') === '1') continue;
    const name = String(p.product_name || '').trim();
    if (!name) continue;
    if (!name.toLowerCase().includes(q)) continue;

    const categoryName = String(p.category_name || '').trim();
    const productId = Number(p.product_id || 0) || 0;
    if (!productId) continue;

    out.push({
      id: productId,
      product_id: productId,
      product_name: name,
      name: name,
      desc: categoryName,
      price: extractPosterPriceCents(p),
    });

    if (out.length >= 30) break;
  }

  renderSearchResults(query, out);
}

function handleSearchInput() {
  const q = (elSearchInput.value || '').trim();
  elSearchClear.hidden = q === '';

  if (searchTimer) clearTimeout(searchTimer);

  if (q.length < 2) {
    setSearchActive(false);
    return;
  }

  setSearchActive(true);
  searchTimer = setTimeout(() => doSearch(q), 250);
}

if (elSearchInput && elSearchClear) {
  elSearchInput.addEventListener('input', handleSearchInput);
  elSearchClear.addEventListener('click', () => {
    elSearchInput.value = '';
    handleSearchInput();
    elSearchInput.focus();
  });
}

const phoneDigits = (raw) => String(raw || '').replace(/\D+/g, '').slice(0, 15);
const isPhoneValid = (raw) => {
  try {
    if (typeof libphonenumber === 'undefined') return /^[1-9]\d{6,15}$/.test(phoneDigits(raw));
    const parsed = libphonenumber.parsePhoneNumber(String(raw).trim());
    return parsed && parsed.isValid();
  } catch (e) {
    return false;
  }
};

const getPhoneE164 = (raw) => {
  try {
    if (typeof libphonenumber !== 'undefined') {
      const parsed = libphonenumber.parsePhoneNumber(String(raw).trim());
      if (parsed && parsed.isValid()) return parsed.format('E.164');
    }
  } catch (e) {}
  const digits = phoneDigits(raw);
  return digits ? ('+' + digits) : '';
};

async function submitOrder(e) {
  e.preventDefault();
  const btn = d.getElementById('submitBtn');
  const errEl = d.getElementById('checkoutError');
  errEl.hidden = true;

  const name = d.getElementById('orderName').value.trim();
  const rawPhone = d.getElementById('orderPhone').value;
  const sm = d.querySelector('input[name="service_mode"]:checked').value;

  if (!isPhoneValid(rawPhone)) {
    const t = i18n[currentLang] || i18n.ru;
    errEl.textContent = t.phoneInvalid;
    errEl.hidden = false;
    return;
  }

  const phoneE164 = getPhoneE164(rawPhone);

  const products = Object.values(cart).map(c => ({
    product_id: Number(c.item.id),
    count: c.count
  }));

  btn.disabled = true;
  {
    const t = i18n[currentLang] || i18n.ru;
    btn.textContent = t.sending;
  }

  try {
    const res = await fetch('/neworder/api.php?ajax=create_order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: name,
        phone: phoneE164,
        service_mode: Number(sm),
        products: products
      })
    });
    
    const json = await res.json();
    const t = i18n[currentLang] || i18n.ru;
    if (!json.ok) throw new Error(json.error || t.orderUnknown);

    showToast(t.orderSuccessPrefix + json.order_id);
    closeCheckoutModal();
    cart = {};
    updateCartBadge();
    
  } catch (err) {
    errEl.textContent = err.message;
    errEl.hidden = false;
  } finally {
    btn.disabled = false;
    {
      const t = i18n[currentLang] || i18n.ru;
      btn.textContent = t.submit;
    }
  }
}

// Intercept clicks on overlay to close modal (if using mobile slide-up cart)
elCartSidebar.addEventListener('click', (e) => {
  if (e.target === elCartSidebar && window.innerWidth <= 800) {
    closeCheckoutModal();
  }
});

if (elLangMenu) {
  const links = Array.from(elLangMenu.querySelectorAll('.lang-panel a'));
  links.forEach((a) => {
    a.addEventListener('click', (e) => {
      e.preventDefault();
      const href = String(a.getAttribute('href') || '');
      const m = href.match(/[?&]lang=([a-zA-Z-]+)/);
      const lang = m ? m[1].toLowerCase() : '';
      if (!lang || lang === currentLang) {
        elLangMenu.open = false;
        return;
      }
      elLangMenu.open = false;
      applyLang(lang);
      loadProducts();
      handleSearchInput();
    });
  });
}

applyLang(currentLang);
try {
  const raw = localStorage.getItem(lsProductsKey);
  if (raw) {
    const parsed = JSON.parse(raw);
    const p = parsed && Array.isArray(parsed.products) ? parsed.products : [];
    const sid = Number(parsed && parsed.spot_id ? parsed.spot_id : 1) || 1;
    if (p.length) {
      posterProducts = p;
      posterSpotId = sid;
      menuData = buildMenuFromPosterProducts(posterProducts);
      renderMenu();
    }
  }
} catch (e) {}

loadProducts();
