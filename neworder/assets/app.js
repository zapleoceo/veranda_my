(() => {
  const d = document;

  const Dom = {
    categories: d.getElementById('categoriesSidebar'),
    menuSections: d.getElementById('menuSections'),
    cartBadge: d.getElementById('cartBadge'),
    cartItems: d.getElementById('cartItems'),
    cartTotal: d.getElementById('cartTotalSum'),
    cartFooter: d.getElementById('cartFooter'),
    emptyCart: d.getElementById('emptyCart'),
    toast: d.getElementById('toast'),
    searchInput: d.getElementById('productSearchInput'),
    searchClear: d.getElementById('productSearchClear'),
    pageTitle: d.getElementById('pageTitle'),
    cartTitle: d.getElementById('cartTitle'),
    cartTotalLabel: d.getElementById('cartTotalLabel'),
    checkoutTitle: d.getElementById('checkoutTitle'),
    labelName: d.getElementById('labelName'),
    orderName: d.getElementById('orderName'),
    submitBtn: d.getElementById('submitBtn'),
    loadingState: d.getElementById('loadingState'),
    checkoutForm: d.getElementById('checkoutForm'),
    checkoutError: d.getElementById('checkoutError'),
    langMenu: d.getElementById('langMenu'),
    spotSelect: d.getElementById('spotIdSelect'),
    tableSelect: d.getElementById('tableIdSelect'),
    labelSpot: d.getElementById('labelSpot'),
    labelTable: d.getElementById('labelTable'),
    hallSelect: d.getElementById('hallIdSelect'),
    labelHall: d.getElementById('labelHall'),
  };

  const fmtPrice = (val) => new Intl.NumberFormat('vi-VN').format(val) + ' đ';

  const lsProductsKey = 'neworder_poster_products_v1';
  const lsSpotKey = 'neworder_spot_id_v1';
  const lsHallKey = 'neworder_hall_id_v1';
  const supportedLangs = ['ru', 'en', 'vi', 'ko'];
  let currentLang = (d.documentElement.getAttribute('lang') || 'ru').toLowerCase();
  if (!supportedLangs.includes(currentLang)) currentLang = 'ru';

  const i18n = {
  ru: {
    title: 'Новый заказ',
    cart: 'Корзина',
    searchPlaceholder: 'Поиск блюд...',
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
    submit: 'Подтвердить заказ',
    sending: 'Отправка...',
    orderUnknown: 'Неизвестная ошибка',
    orderSuccessPrefix: 'Заказ успешно создан! ID: ',
  },
  en: {
    title: 'New order',
    cart: 'Cart',
    searchPlaceholder: 'Search dishes...',
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
    submit: 'Place order',
    sending: 'Sending...',
    orderUnknown: 'Unknown error',
    orderSuccessPrefix: 'Order created! ID: ',
  },
  vi: {
    title: 'Đơn mới',
    cart: 'Giỏ hàng',
    searchPlaceholder: 'Tìm món...',
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
    submit: 'Đặt hàng',
    sending: 'Đang gửi...',
    orderUnknown: 'Lỗi không xác định',
    orderSuccessPrefix: 'Đã tạo đơn! ID: ',
  },
  ko: {
    title: '새 주문',
    cart: '장바구니',
    searchPlaceholder: '메뉴 검색...',
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
    submit: '주문 확정',
    sending: '전송 중...',
    orderUnknown: '알 수 없는 오류',
    orderSuccessPrefix: '주문 생성됨! ID: ',
  }
  };

  const Config = {
    waiterId: 10,
    clientId: 71,
  };

  function viewSetActiveLangLink(lang) {
    if (!Dom.langMenu) return;
    const links = Array.from(Dom.langMenu.querySelectorAll('.lang-panel a'));
    links.forEach((a) => {
      const href = String(a.getAttribute('href') || '');
      const m = href.match(/[?&]lang=([a-zA-Z-]+)/);
      const code = m ? m[1].toLowerCase() : '';
      a.classList.toggle('active', code === lang);
    });
  }

  function viewApplyLang(t) {
    d.documentElement.setAttribute('lang', currentLang);
    d.title = t.title;
    if (Dom.pageTitle) Dom.pageTitle.textContent = t.title;
    if (Dom.cartTitle) Dom.cartTitle.textContent = t.cart;
    if (Dom.emptyCart) Dom.emptyCart.textContent = t.emptyCart;
    if (Dom.cartTotalLabel) Dom.cartTotalLabel.textContent = t.total;
    if (Dom.checkoutTitle) Dom.checkoutTitle.textContent = t.checkout;
    if (Dom.labelName) Dom.labelName.textContent = t.name;
    if (Dom.submitBtn && !Dom.submitBtn.disabled) Dom.submitBtn.textContent = t.submit;
    if (Dom.orderName) Dom.orderName.placeholder = t.namePh;
    if (Dom.loadingState) Dom.loadingState.textContent = t.menuLoading;
    if (Dom.searchInput) Dom.searchInput.placeholder = t.searchPlaceholder;
    if (Dom.labelSpot) Dom.labelSpot.textContent = 'Spot';
    if (Dom.labelTable) Dom.labelTable.textContent = 'Table';
    if (Dom.labelHall) Dom.labelHall.textContent = 'Hall';
    viewSetActiveLangLink(currentLang);
  }

  function viewShowToast(msg, isError) {
    if (!Dom.toast) return;
    Dom.toast.textContent = msg;
    Dom.toast.className = 'toast ' + (isError ? 'error' : '');
    Dom.toast.hidden = false;
    setTimeout(() => { Dom.toast.hidden = true; }, 3000);
  }

  function viewRenderEmptyMenu(text) {
    if (!Dom.menuSections) return;
    Dom.menuSections.innerHTML = `<div class="loading-state">${text}</div>`;
    if (Dom.categories) Dom.categories.innerHTML = '';
  }

  function viewRenderMenu(groups, handlers) {
    if (!Dom.menuSections || !Dom.categories) return { sections: [], linksById: new Map() };
    Dom.menuSections.innerHTML = '';
    Dom.categories.innerHTML = '';

    const sections = [];
    const linksById = new Map();

    (groups || []).forEach((group, idx) => {
      const link = d.createElement('a');
      link.className = 'category-link';
      link.textContent = group.title;
      link.href = `#cat-${group.id}`;
      link.dataset.catId = String(group.id);
      if (idx === 0) link.classList.add('active');
      link.addEventListener('click', (e) => {
        e.preventDefault();
        if (handlers && typeof handlers.onCategoryClick === 'function') {
          handlers.onCategoryClick(String(group.id));
        }
      });
      Dom.categories.appendChild(link);
      linksById.set(String(group.id), link);

      const section = d.createElement('section');
      section.className = 'menu-section';
      section.id = `cat-${group.id}`;
      section.dataset.catId = String(group.id);

      const title = d.createElement('div');
      title.className = 'menu-section-title';
      title.textContent = group.title;
      section.appendChild(title);

      const grid = d.createElement('div');
      grid.className = 'products-grid';

      (group.items || []).forEach((item) => {
        const priceVal = Number(item.price_cents || 0) / 100;
        const card = d.createElement('div');
        card.className = 'product-card';
        card.tabIndex = 0;
        card.setAttribute('role', 'button');
        card.addEventListener('click', () => {
          if (handlers && typeof handlers.onProductClick === 'function') {
            handlers.onProductClick(item);
          }
        });
        card.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (handlers && typeof handlers.onProductClick === 'function') {
              handlers.onProductClick(item);
            }
          }
        });

        const name = d.createElement('h3');
        name.className = 'product-name';
        name.textContent = String(item.name || '');

        const footer = d.createElement('div');
        footer.className = 'product-footer';

        const price = d.createElement('div');
        price.className = 'product-price';
        price.textContent = fmtPrice(priceVal);

        footer.appendChild(price);
        card.appendChild(name);
        card.appendChild(footer);
        grid.appendChild(card);
      });

      section.appendChild(grid);
      Dom.menuSections.appendChild(section);
      sections.push(section);
    });

    return { sections, linksById };
  }

  function viewSetActiveCategory(linksById, catId) {
    const id = String(catId || '');
    for (const a of linksById.values()) a.classList.remove('active');
    const el = linksById.get(id);
    if (el) el.classList.add('active');
    if (el && window.innerWidth <= 800) {
      el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }
  }

  function viewRenderCart(cart) {
    if (!Dom.cartItems || !Dom.cartFooter || !Dom.emptyCart || !Dom.cartTotal) return;
    Dom.cartItems.innerHTML = '';
    const items = Object.values(cart || {});
    if (!items.length) {
      Dom.emptyCart.hidden = false;
      Dom.cartFooter.hidden = true;
      return;
    }
    Dom.emptyCart.hidden = true;
    Dom.cartFooter.hidden = false;

    let sum = 0;
    items.forEach((c) => {
      const row = d.createElement('div');
      row.className = 'cart-item';

      const info = d.createElement('div');
      info.className = 'cart-item-info';

      const n = d.createElement('div');
      n.className = 'cart-item-name';
      n.textContent = String((c.item && c.item.name) ? c.item.name : '');

      const p = d.createElement('div');
      p.className = 'cart-item-price';
      p.textContent = fmtPrice((Number(c.price || 0) * Number(c.count || 0)) || 0);

      info.appendChild(n);
      info.appendChild(p);

      const controls = d.createElement('div');
      controls.className = 'cart-item-controls';

      const minus = d.createElement('button');
      minus.className = 'qty-btn';
      minus.type = 'button';
      minus.textContent = '−';
      minus.addEventListener('click', () => Controller.updateCart(String((c.item && c.item.id) ? c.item.id : ''), -1));

      const count = d.createElement('div');
      count.style.fontWeight = 'bold';
      count.textContent = String(c.count || 0);

      const plus = d.createElement('button');
      plus.className = 'qty-btn';
      plus.type = 'button';
      plus.textContent = '+';
      plus.addEventListener('click', () => Controller.updateCart(String((c.item && c.item.id) ? c.item.id : ''), 1));

      controls.appendChild(minus);
      controls.appendChild(count);
      controls.appendChild(plus);

      row.appendChild(info);
      row.appendChild(controls);
      Dom.cartItems.appendChild(row);

      sum += (Number(c.price || 0) * Number(c.count || 0)) || 0;
    });

    Dom.cartTotal.textContent = fmtPrice(sum);
  }

  function viewRenderCartBadge(cart) {
    if (!Dom.cartBadge) return;
    let totalCount = 0;
    Object.values(cart || {}).forEach((c) => { totalCount += Number(c.count || 0); });
    Dom.cartBadge.textContent = String(totalCount);
    Dom.cartBadge.hidden = totalCount <= 0;
  }

  function viewRenderSpotSelect(spots, selectedSpotId) {
    if (!Dom.spotSelect) return;
    Dom.spotSelect.innerHTML = '';
    (spots || []).forEach((s) => {
      const sid = Number(s && s.spot_id ? s.spot_id : 0) || 0;
      if (!sid) return;
      const name = String(s && s.name ? s.name : '').trim();
      const opt = d.createElement('option');
      opt.value = String(sid);
      opt.textContent = name ? name : String(sid);
      Dom.spotSelect.appendChild(opt);
    });
    Dom.spotSelect.value = String(Number(selectedSpotId || 0));
  }

  function viewRenderTableSelect(tables, selectedId) {
    if (!Dom.tableSelect) return;
    Dom.tableSelect.innerHTML = '';
    const empty = d.createElement('option');
    empty.value = '0';
    empty.textContent = '—';
    Dom.tableSelect.appendChild(empty);

    (tables || []).forEach((x) => {
      const id = Number(x.table_id || x.id || 0) || 0;
      if (!id) return;
      const num = String(x.table_num || x.num || '').trim();
      const title = String(x.table_title || x.title || '').trim();
      const label = title ? (num ? `${num}. ${title}` : title) : (num ? num : String(id));
      const opt = d.createElement('option');
      opt.value = String(id);
      opt.textContent = label;
      Dom.tableSelect.appendChild(opt);
    });

    Dom.tableSelect.value = String(Number(selectedId || 0));
  }

  function viewRenderHallSelect(halls, selectedId) {
    if (!Dom.hallSelect) return;
    Dom.hallSelect.innerHTML = '';
    const any = d.createElement('option');
    any.value = '0';
    any.textContent = '—';
    Dom.hallSelect.appendChild(any);
    (halls || []).forEach((h) => {
      const v = Number(h && h.hall_id ? h.hall_id : 0) || 0;
      if (!v) return;
      const name = String(h && h.hall_name ? h.hall_name : '').trim();
      const opt = d.createElement('option');
      opt.value = String(v);
      opt.textContent = name ? name : String(v);
      Dom.hallSelect.appendChild(opt);
    });
    Dom.hallSelect.value = String(Number(selectedId || 0));
  }

  const View = {
    applyLang: viewApplyLang,
    setActiveLangLink: viewSetActiveLangLink,
    showToast: viewShowToast,
    renderEmptyMenu: viewRenderEmptyMenu,
    renderMenu: viewRenderMenu,
    setActiveCategory: viewSetActiveCategory,
    renderCart: viewRenderCart,
    renderCartBadge: viewRenderCartBadge,
    renderSpotSelect: viewRenderSpotSelect,
    renderTableSelect: viewRenderTableSelect,
    renderHallSelect: viewRenderHallSelect,
  };

  function modelLoadSelection() {
    try {
      const sid = Number(localStorage.getItem(lsSpotKey) || 0) || 0;
      const hid = Number(localStorage.getItem(lsHallKey) || 0) || 0;
      if (sid > 0) Model.spotId = sid;
      if (hid > 0) Model.hallId = hid;
    } catch (e) {}
  }

  function modelSaveSelection() {
    try {
      localStorage.setItem(lsSpotKey, String(Number(Model.spotId || 0) || 0));
      localStorage.setItem(lsHallKey, String(Number(Model.hallId || 0) || 0));
    } catch (e) {}
  }

  function modelSetQuery(q) {
    Model.query = String(q || '').trim();
  }

  function modelSetCartItem(item, price, delta) {
    const id = String((item && item.id) ? item.id : '');
    if (!id) return;
    if (!Model.cart[id]) {
      if (delta <= 0) return;
      Model.cart[id] = { item, price, count: delta };
    } else {
      Model.cart[id].count = Number(Model.cart[id].count || 0) + delta;
      if (Model.cart[id].count <= 0) delete Model.cart[id];
    }
  }

  function modelClearCart() {
    Model.cart = {};
  }

  function modelFilterGroups() {
    const q = String(Model.query || '').toLowerCase();
    if (q.length < 2) return Model.groupsAll;
    const out = [];
    for (const g of Model.groupsAll || []) {
      const items = (g.items || []).filter((it) => String((it && it.name) ? it.name : '').toLowerCase().includes(q));
      if (!items.length) continue;
      out.push({ id: g.id, title: g.title, items });
    }
    return out;
  }

  function modelSaveCache() {
    try {
      localStorage.setItem(lsProductsKey, JSON.stringify({ products: Model.posterProducts, spot_id: Model.spotId }));
    } catch (e) {}
  }

  function modelLoadCache() {
    try {
      const raw = localStorage.getItem(lsProductsKey);
      if (!raw) return false;
      const parsed = JSON.parse(raw);
      const p = parsed && Array.isArray(parsed.products) ? parsed.products : [];
      const sid = Number(parsed && parsed.spot_id ? parsed.spot_id : 1) || 1;
      if (!p.length) return false;
      Model.posterProducts = p;
      if (!Model.spotId || Model.spotId <= 0) Model.spotId = sid;
      Model.groupsAll = Model.buildGroups(p);
      return true;
    } catch (e) {
      return false;
    }
  }

  async function modelFetchProducts() {
    const res = await fetch('/api/poster/neworder/index.php?ajax=get_products', { headers: { 'Accept': 'application/json' } });
    const json = await res.json();
    if (!json || !json.ok) {
      throw new Error(String((json && json.error) ? json.error : 'Failed'));
    }
    const products = Array.isArray(json.products) ? json.products : [];
    const spotId = Number(json.spot_id || 1) || 1;
    Model.posterProducts = products;
    if (!Model.spotId || Model.spotId <= 0) Model.spotId = spotId;
    Model.groupsAll = Model.buildGroups(products);
    Model.saveCache();
  }

  async function modelFetchSpots() {
    const res = await fetch('/api/poster/neworder/index.php?ajax=get_spots', { headers: { 'Accept': 'application/json' } });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(String((json && json.error) ? json.error : 'Failed'));
    const spots = Array.isArray(json.spots) ? json.spots : [];
    Model.spots = spots;
    return spots;
  }

  async function modelFetchHalls(spotId) {
    const sid = Number(spotId || Model.spotId || 1) || 1;
    const res = await fetch(`/api/poster/neworder/index.php?ajax=get_halls&spot_id=${encodeURIComponent(String(sid))}`, { headers: { 'Accept': 'application/json' } });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(String((json && json.error) ? json.error : 'Failed'));
    const halls = Array.isArray(json.halls) ? json.halls : [];
    Model.halls = halls;
    return halls;
  }

  async function modelFetchTables(spotId, hallId) {
    const sid = Number(spotId || Model.spotId || 1) || 1;
    const hid = Number(hallId || Model.hallId || 0) || 0;
    const url = hid > 0
      ? `/api/poster/neworder/index.php?ajax=get_tables&spot_id=${encodeURIComponent(String(sid))}&hall_id=${encodeURIComponent(String(hid))}`
      : `/api/poster/neworder/index.php?ajax=get_tables&spot_id=${encodeURIComponent(String(sid))}`;
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    const json = await res.json();
    if (!json || !json.ok) {
      throw new Error(String((json && json.error) ? json.error : 'Failed'));
    }
    const tables = Array.isArray(json.tables) ? json.tables : [];
    Model.tables = tables;
    if (Model.tableId > 0) {
      const ok = tables.some((t) => Number(t.table_id || 0) === Number(Model.tableId));
      if (!ok) Model.tableId = 0;
    }
  }

  function modelExtractPriceCents(p) {
    const spots = p && p.spots ? p.spots : null;
    if (Array.isArray(spots)) {
      for (const s of spots) {
        if (!s || typeof s !== 'object') continue;
        if (Number(s.spot_id || 0) !== Number(Model.spotId || 0)) continue;
        if (String(s.visible || '1') === '0') continue;
        const v = s.price;
        if (typeof v === 'number' && Number.isFinite(v)) return Math.trunc(v);
        if (typeof v === 'string' && /^\d+$/.test(v)) return Number(v);
      }
    }

    const price = p && p.price ? p.price : null;
    if (price && typeof price === 'object' && !Array.isArray(price)) {
      const key = String(Model.spotId);
      if (price[key] != null && /^\d+$/.test(String(price[key]))) return Number(price[key]);
      for (const k of Object.keys(price)) {
        if (price[k] != null && /^\d+$/.test(String(price[k]))) return Number(price[k]);
      }
    }

    return 0;
  }

  function modelBuildGroups(products) {
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
          id: categoryId || Math.abs(Model.hashString(categoryName)),
          title: categoryName,
          items: [],
        });
      }
      catMap.get(key).items.push({
        id: String(productId),
        name: productName,
        price_cents: Model.extractPriceCents(p),
      });
    });
    const groups = Array.from(catMap.values());
    groups.forEach((g) => g.items.sort((a, b) => String((a && a.name) ? a.name : '').localeCompare(String((b && b.name) ? b.name : ''), undefined, { sensitivity: 'base' })));
    groups.sort((a, b) => String((a && a.title) ? a.title : '').localeCompare(String((b && b.title) ? b.title : ''), undefined, { sensitivity: 'base' }));
    return groups;
  }

  function modelHashString(s) {
    let h = 0;
    const str = String(s || '');
    for (let i = 0; i < str.length; i++) {
      h = ((h << 5) - h) + str.charCodeAt(i);
      h |= 0;
    }
    return h || 1;
  }

  const Model = {
    spotId: 1,
    spots: [],
    halls: [],
    posterProducts: [],
    groupsAll: [],
    query: '',
    cart: {},
    tables: [],
    tableId: 0,
    hallId: 0,
    loadSelection: modelLoadSelection,
    saveSelection: modelSaveSelection,
    setQuery: modelSetQuery,
    setCartItem: modelSetCartItem,
    clearCart: modelClearCart,
    filterGroups: modelFilterGroups,
    saveCache: modelSaveCache,
    loadCache: modelLoadCache,
    fetchProducts: modelFetchProducts,
    fetchSpots: modelFetchSpots,
    fetchHalls: modelFetchHalls,
    fetchTables: modelFetchTables,
    extractPriceCents: modelExtractPriceCents,
    buildGroups: modelBuildGroups,
    hashString: modelHashString,
  };

  function controllerInit() {
    Controller.bindLangMenu();
    Controller.bindSearch();
    Controller.bindCheckout();
    Controller.bindSpotTable();

    const t = i18n[currentLang] || i18n.ru;
    View.applyLang(t);

    Model.loadSelection();
    if (Model.loadCache()) {
      Controller.refreshMenu();
    }

    Controller.loadProducts();
    View.renderCart(Model.cart, t);
    View.renderCartBadge(Model.cart);
  }

  async function controllerLoadProducts() {
    const t = i18n[currentLang] || i18n.ru;
    try {
      await Model.fetchProducts();

      await Model.fetchSpots();
      if (Array.isArray(Model.spots) && Model.spots.length) {
        const okSpot = Model.spots.some((s) => Number(s && s.spot_id ? s.spot_id : 0) === Number(Model.spotId || 0));
        if (!okSpot) Model.spotId = Number(Model.spots[0].spot_id || 1) || 1;
      }
      Model.saveSelection();
      View.renderSpotSelect(Model.spots, Model.spotId);

      await Model.fetchHalls(Model.spotId);
      if (Array.isArray(Model.halls) && Model.halls.length) {
        const okHall = Model.halls.some((h) => Number(h && h.hall_id ? h.hall_id : 0) === Number(Model.hallId || 0));
        if (!okHall) Model.hallId = Number(Model.halls[0].hall_id || 0) || 0;
      } else {
        Model.hallId = 0;
      }
      Model.saveSelection();
      View.renderHallSelect(Model.halls, Model.hallId);

      Model.groupsAll = Model.buildGroups(Model.posterProducts);
      await Model.fetchTables(Model.spotId, Model.hallId);
      View.renderTableSelect(Model.tables, Model.tableId);
      Controller.refreshMenu();
    } catch (err) {
      View.renderEmptyMenu(`${t.loadMenuFailPrefix}${String((err && err.message) ? err.message : err)}`);
    }
  }

  function controllerRefreshMenu() {
    const t = i18n[currentLang] || i18n.ru;
    const groups = Model.filterGroups();
    if (!groups.length) {
      const q = String(Model.query || '').trim();
      View.renderEmptyMenu(q.length >= 2 ? t.searchEmpty : t.menuEmpty);
      Controller.viewState.sections = [];
      Controller.viewState.linksById = new Map();
      return;
    }
    const rendered = View.renderMenu(groups, {
      onCategoryClick: (catId) => Controller.onCategoryClick(catId),
      onProductClick: (item) => Controller.addToCart(item),
    });
    Controller.viewState.sections = rendered.sections;
    Controller.viewState.linksById = rendered.linksById;
    Controller.viewState.lastActive = '';
    Controller.updateActiveByScroll();
  }

  function controllerOnCategoryClick(catId) {
    const id = String(catId || '');
    const section = d.getElementById(`cat-${id}`);
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    View.setActiveCategory(Controller.viewState.linksById, id);
  }

  function controllerUpdateActiveByScroll() {
    if (Controller.viewState.scrollRaf) return;
    Controller.viewState.scrollRaf = requestAnimationFrame(() => {
      Controller.viewState.scrollRaf = 0;
      const sections = Controller.viewState.sections || [];
      if (!sections.length) return;

      const offset = 90;
      let active = sections[0];
      for (const s of sections) {
        const top = s.getBoundingClientRect().top;
        if (top - offset <= 0) active = s;
        else break;
      }
      const id = String((active && active.dataset && active.dataset.catId) ? active.dataset.catId : '');
      if (id && id !== Controller.viewState.lastActive) {
        Controller.viewState.lastActive = id;
        View.setActiveCategory(Controller.viewState.linksById, id);
      }
    });
  }

  function controllerBindSearch() {
    if (!Dom.searchInput || !Dom.searchClear) return;
    Dom.searchInput.addEventListener('input', () => Controller.onSearchInput());
    Dom.searchClear.addEventListener('click', () => {
      Dom.searchInput.value = '';
      Controller.onSearchInput(true);
      Dom.searchInput.focus();
    });

    window.addEventListener('scroll', () => Controller.updateActiveByScroll(), { passive: true });
    window.addEventListener('resize', () => Controller.updateActiveByScroll(), { passive: true });
  }

  function controllerOnSearchInput(force) {
    const q = String(Dom.searchInput ? Dom.searchInput.value : '').trim();
    Dom.searchClear.hidden = q === '';
    if (Controller.searchTimer) clearTimeout(Controller.searchTimer);
    if (force || q.length < 2) {
      Model.setQuery('');
      Controller.refreshMenu();
      return;
    }
    Controller.searchTimer = setTimeout(() => {
      Model.setQuery(q);
      Controller.refreshMenu();
    }, 250);
  }

  function controllerAddToCart(item) {
    const priceVal = Number(item && item.price_cents ? item.price_cents : 0) / 100;
    Model.setCartItem(item, priceVal, 1);
    const t = i18n[currentLang] || i18n.ru;
    View.renderCart(Model.cart, t);
    View.renderCartBadge(Model.cart);
    View.showToast(`${t.addedPrefix}${String((item && item.name) ? item.name : '')}`);
  }

  function controllerUpdateCart(itemId, delta) {
    const id = String(itemId || '');
    const cur = Model.cart[id];
    if (!cur) return;
    Model.setCartItem(cur.item, cur.price, delta);
    const t = i18n[currentLang] || i18n.ru;
    View.renderCart(Model.cart, t);
    View.renderCartBadge(Model.cart);
  }

  function controllerBindLangMenu() {
    if (!Dom.langMenu) return;
    const links = Array.from(Dom.langMenu.querySelectorAll('.lang-panel a'));
    links.forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        const href = String(a.getAttribute('href') || '');
        const m = href.match(/[?&]lang=([a-zA-Z-]+)/);
        const lang = m ? m[1].toLowerCase() : '';
        if (!lang || lang === currentLang) {
          Dom.langMenu.open = false;
          return;
        }
        currentLang = lang;
        Dom.langMenu.open = false;
        const t = i18n[currentLang] || i18n.ru;
        View.applyLang(t);

        const url = new URL(window.location.href);
        url.searchParams.set('lang', currentLang);
        window.history.replaceState({}, '', url.toString());
        document.cookie = `links_lang=${encodeURIComponent(currentLang)}; Path=/; Max-Age=31536000; SameSite=Lax`;
      });
    });
  }

  function controllerBindCheckout() {
    if (!Dom.checkoutForm) return;
    Dom.checkoutForm.addEventListener('submit', (e) => Controller.submitOrder(e));
  }

  function controllerBindSpotTable() {
    if (Dom.spotSelect) {
      Dom.spotSelect.addEventListener('change', async () => {
        const sid = Number(Dom.spotSelect.value || 0) || Model.spotId || 1;
        Model.spotId = sid;
        Model.saveSelection();
        try {
          await Model.fetchHalls(sid);
          if (Array.isArray(Model.halls) && Model.halls.length) {
            Model.hallId = Number(Model.halls[0].hall_id || 0) || 0;
          } else {
            Model.hallId = 0;
          }
          Model.tableId = 0;
          Model.saveSelection();
          View.renderHallSelect(Model.halls, Model.hallId);

          Model.groupsAll = Model.buildGroups(Model.posterProducts);
          Controller.refreshMenu();

          await Model.fetchTables(sid, Model.hallId);
          View.renderTableSelect(Model.tables, 0);
        } catch (e) {
          Model.hallId = 0;
          Model.tableId = 0;
          Model.saveSelection();
          View.renderHallSelect([], 0);
          View.renderTableSelect([], 0);
        }
      });
    }
    if (Dom.hallSelect) {
      Dom.hallSelect.addEventListener('change', async () => {
        Model.hallId = Number(Dom.hallSelect.value || 0) || 0;
        Model.tableId = 0;
        Model.saveSelection();
        try {
          await Model.fetchTables(Model.spotId, Model.hallId);
          View.renderTableSelect(Model.tables, 0);
        } catch (e) {
          View.renderTableSelect([], 0);
        }
      });
    }
    if (Dom.tableSelect) {
      Dom.tableSelect.addEventListener('change', () => {
        Model.tableId = Number(Dom.tableSelect.value || 0) || 0;
      });
    }
  }

  async function controllerSubmitOrder(e) {
    e.preventDefault();
    const t = i18n[currentLang] || i18n.ru;
    if (Dom.checkoutError) Dom.checkoutError.hidden = true;
    if (!Dom.submitBtn) return;

    const name = String(Dom.orderName ? Dom.orderName.value : '').trim();
    const serviceMode = 1;
    const products = Object.values(Model.cart).map((c) => ({ product_id: Number((c.item && c.item.id) ? c.item.id : 0), count: Number(c.count || 0) }));
    if (!products.length) {
      if (Dom.checkoutError) {
        Dom.checkoutError.textContent = t.emptyCart;
        Dom.checkoutError.hidden = false;
      }
      return;
    }

    Dom.submitBtn.disabled = true;
    Dom.submitBtn.textContent = t.sending;

    try {
      const res = await fetch('/api/poster/neworder/index.php?ajax=create_order', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name,
          service_mode: serviceMode,
          products,
          waiter_id: Config.waiterId,
          client_id: Config.clientId,
          spot_id: Number(Model.spotId || 0),
          table_id: Number(Model.tableId || 0),
        }),
      });
      const json = await res.json();
      if (!json.ok) throw new Error(String(json.error || t.orderUnknown));
      View.showToast(t.orderSuccessPrefix + String(json.order_id));
      Model.clearCart();
      View.renderCart(Model.cart, t);
      View.renderCartBadge(Model.cart);
    } catch (err) {
      if (Dom.checkoutError) {
        Dom.checkoutError.textContent = String((err && err.message) ? err.message : err);
        Dom.checkoutError.hidden = false;
      }
    } finally {
      Dom.submitBtn.disabled = false;
      Dom.submitBtn.textContent = t.submit;
    }
  }

  const Controller = {
    viewState: { sections: [], linksById: new Map(), scrollRaf: 0, lastActive: '' },
    searchTimer: 0,
    init: controllerInit,
    loadProducts: controllerLoadProducts,
    refreshMenu: controllerRefreshMenu,
    onCategoryClick: controllerOnCategoryClick,
    updateActiveByScroll: controllerUpdateActiveByScroll,
    bindSearch: controllerBindSearch,
    onSearchInput: controllerOnSearchInput,
    addToCart: controllerAddToCart,
    updateCart: controllerUpdateCart,
    bindLangMenu: controllerBindLangMenu,
    bindCheckout: controllerBindCheckout,
    bindSpotTable: controllerBindSpotTable,
    submitOrder: controllerSubmitOrder,
  };

  Controller.init();
})();
