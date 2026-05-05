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
    labelPhone: d.getElementById('labelPhone'),
    labelServiceMode: d.getElementById('labelServiceMode'),
    serviceInPlaceLabel: d.getElementById('serviceInPlaceLabel'),
    serviceTakeawayLabel: d.getElementById('serviceTakeawayLabel'),
    orderName: d.getElementById('orderName'),
    orderPhone: d.getElementById('orderPhone'),
    submitBtn: d.getElementById('submitBtn'),
    loadingState: d.getElementById('loadingState'),
    checkoutForm: d.getElementById('checkoutForm'),
    checkoutError: d.getElementById('checkoutError'),
    langMenu: d.getElementById('langMenu'),
  };

  const fmtPrice = (val) => new Intl.NumberFormat('vi-VN').format(val) + ' đ';

  const lsProductsKey = 'neworder_poster_products_v1';
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
  }
  };

  const View = {
    applyLang(t) {
      d.documentElement.setAttribute('lang', currentLang);
      d.title = t.title;
      if (Dom.pageTitle) Dom.pageTitle.textContent = t.title;
      if (Dom.cartTitle) Dom.cartTitle.textContent = t.cart;
      if (Dom.emptyCart) Dom.emptyCart.textContent = t.emptyCart;
      if (Dom.cartTotalLabel) Dom.cartTotalLabel.textContent = t.total;
      if (Dom.checkoutTitle) Dom.checkoutTitle.textContent = t.checkout;
      if (Dom.labelName) Dom.labelName.textContent = t.name;
      if (Dom.labelPhone) Dom.labelPhone.textContent = t.phone;
      if (Dom.labelServiceMode) Dom.labelServiceMode.textContent = t.orderType;
      if (Dom.serviceInPlaceLabel) Dom.serviceInPlaceLabel.textContent = t.inPlace;
      if (Dom.serviceTakeawayLabel) Dom.serviceTakeawayLabel.textContent = t.takeaway;
      if (Dom.submitBtn && !Dom.submitBtn.disabled) Dom.submitBtn.textContent = t.submit;
      if (Dom.orderName) Dom.orderName.placeholder = t.namePh;
      if (Dom.orderPhone) Dom.orderPhone.placeholder = t.phonePh;
      if (Dom.loadingState) Dom.loadingState.textContent = t.menuLoading;
      if (Dom.searchInput) Dom.searchInput.placeholder = t.searchPlaceholder;
      View.setActiveLangLink(currentLang);
    },

    setActiveLangLink(lang) {
      if (!Dom.langMenu) return;
      const links = Array.from(Dom.langMenu.querySelectorAll('.lang-panel a'));
      links.forEach((a) => {
        const href = String(a.getAttribute('href') || '');
        const m = href.match(/[?&]lang=([a-zA-Z-]+)/);
        const code = m ? m[1].toLowerCase() : '';
        a.classList.toggle('active', code === lang);
      });
    },

    showToast(msg, isError = false) {
      if (!Dom.toast) return;
      Dom.toast.textContent = msg;
      Dom.toast.className = 'toast ' + (isError ? 'error' : '');
      Dom.toast.hidden = false;
      setTimeout(() => { Dom.toast.hidden = true; }, 3000);
    },

    renderEmptyMenu(text) {
      if (!Dom.menuSections) return;
      Dom.menuSections.innerHTML = `<div class="loading-state">${text}</div>`;
      if (Dom.categories) Dom.categories.innerHTML = '';
    },

    renderMenu(groups, handlers) {
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
          handlers?.onCategoryClick?.(String(group.id));
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
          card.addEventListener('click', () => handlers?.onProductClick?.(item));
          card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              handlers?.onProductClick?.(item);
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
    },

    setActiveCategory(linksById, catId) {
      const id = String(catId || '');
      for (const a of linksById.values()) a.classList.remove('active');
      const el = linksById.get(id);
      if (el) el.classList.add('active');
      if (el && window.innerWidth <= 800) {
        el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      }
    },

    renderCart(cart, t) {
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
        n.textContent = String(c.item?.name || '');

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
        minus.addEventListener('click', () => Controller.updateCart(String(c.item?.id || ''), -1));

        const count = d.createElement('div');
        count.style.fontWeight = 'bold';
        count.textContent = String(c.count || 0);

        const plus = d.createElement('button');
        plus.className = 'qty-btn';
        plus.type = 'button';
        plus.textContent = '+';
        plus.addEventListener('click', () => Controller.updateCart(String(c.item?.id || ''), 1));

        controls.appendChild(minus);
        controls.appendChild(count);
        controls.appendChild(plus);

        row.appendChild(info);
        row.appendChild(controls);
        Dom.cartItems.appendChild(row);

        sum += (Number(c.price || 0) * Number(c.count || 0)) || 0;
      });

      Dom.cartTotal.textContent = fmtPrice(sum);
    },

    renderCartBadge(cart) {
      if (!Dom.cartBadge) return;
      let totalCount = 0;
      Object.values(cart || {}).forEach((c) => { totalCount += Number(c.count || 0); });
      Dom.cartBadge.textContent = String(totalCount);
      Dom.cartBadge.hidden = totalCount <= 0;
    },
  };

  const Model = {
    spotId: 1,
    posterProducts: [],
    groupsAll: [],
    query: '',
    cart: {},

    setQuery(q) {
      Model.query = String(q || '').trim();
    },

    setCartItem(item, price, delta) {
      const id = String(item?.id || '');
      if (!id) return;
      if (!Model.cart[id]) {
        if (delta <= 0) return;
        Model.cart[id] = { item, price, count: delta };
      } else {
        Model.cart[id].count = Number(Model.cart[id].count || 0) + delta;
        if (Model.cart[id].count <= 0) delete Model.cart[id];
      }
    },

    clearCart() {
      Model.cart = {};
    },

    filterGroups() {
      const q = String(Model.query || '').toLowerCase();
      if (q.length < 2) return Model.groupsAll;
      const out = [];
      for (const g of Model.groupsAll || []) {
        const items = (g.items || []).filter((it) => String(it?.name || '').toLowerCase().includes(q));
        if (!items.length) continue;
        out.push({ id: g.id, title: g.title, items });
      }
      return out;
    },

    saveCache() {
      try {
        localStorage.setItem(lsProductsKey, JSON.stringify({ products: Model.posterProducts, spot_id: Model.spotId }));
      } catch (e) {}
    },

    loadCache() {
      try {
        const raw = localStorage.getItem(lsProductsKey);
        if (!raw) return false;
        const parsed = JSON.parse(raw);
        const p = parsed && Array.isArray(parsed.products) ? parsed.products : [];
        const sid = Number(parsed && parsed.spot_id ? parsed.spot_id : 1) || 1;
        if (!p.length) return false;
        Model.posterProducts = p;
        Model.spotId = sid;
        Model.groupsAll = Model.buildGroups(p);
        return true;
      } catch (e) {
        return false;
      }
    },

    async fetchProducts() {
      const res = await fetch('/api/poster/neworder/index.php?ajax=get_products', { headers: { 'Accept': 'application/json' } });
      const json = await res.json();
      if (!json || !json.ok) {
        throw new Error(String(json?.error || 'Failed'));
      }
      const products = Array.isArray(json.products) ? json.products : [];
      const spotId = Number(json.spot_id || 1) || 1;
      Model.posterProducts = products;
      Model.spotId = spotId;
      Model.groupsAll = Model.buildGroups(products);
      Model.saveCache();
    },

    extractPriceCents(p) {
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
    },

    buildGroups(products) {
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
      groups.forEach((g) => g.items.sort((a, b) => String(a?.name || '').localeCompare(String(b?.name || ''), undefined, { sensitivity: 'base' })));
      groups.sort((a, b) => String(a?.title || '').localeCompare(String(b?.title || ''), undefined, { sensitivity: 'base' }));
      return groups;
    },

    hashString(s) {
      let h = 0;
      const str = String(s || '');
      for (let i = 0; i < str.length; i++) {
        h = ((h << 5) - h) + str.charCodeAt(i);
        h |= 0;
      }
      return h || 1;
    },
  };

  const Controller = {
    viewState: { sections: [], linksById: new Map(), scrollRaf: 0, lastActive: '' },
    searchTimer: 0,

    init() {
      Controller.bindLangMenu();
      Controller.bindSearch();
      Controller.bindCheckout();

      const t = i18n[currentLang] || i18n.ru;
      View.applyLang(t);

      if (Model.loadCache()) {
        Controller.refreshMenu();
      }

      Controller.loadProducts();
      View.renderCart(Model.cart, t);
      View.renderCartBadge(Model.cart);
    },

    async loadProducts() {
      const t = i18n[currentLang] || i18n.ru;
      try {
        await Model.fetchProducts();
        Controller.refreshMenu();
      } catch (err) {
        View.renderEmptyMenu(`${t.loadMenuFailPrefix}${String(err?.message || err)}`);
      }
    },

    refreshMenu() {
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
    },

    onCategoryClick(catId) {
      const id = String(catId || '');
      const section = d.getElementById(`cat-${id}`);
      if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      View.setActiveCategory(Controller.viewState.linksById, id);
    },

    updateActiveByScroll() {
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
        const id = String(active?.dataset?.catId || '');
        if (id && id !== Controller.viewState.lastActive) {
          Controller.viewState.lastActive = id;
          View.setActiveCategory(Controller.viewState.linksById, id);
        }
      });
    },

    bindSearch() {
      if (!Dom.searchInput || !Dom.searchClear) return;
      Dom.searchInput.addEventListener('input', () => Controller.onSearchInput());
      Dom.searchClear.addEventListener('click', () => {
        Dom.searchInput.value = '';
        Controller.onSearchInput(true);
        Dom.searchInput.focus();
      });

      window.addEventListener('scroll', () => Controller.updateActiveByScroll(), { passive: true });
      window.addEventListener('resize', () => Controller.updateActiveByScroll(), { passive: true });
    },

    onSearchInput(force = false) {
      const q = String(Dom.searchInput?.value || '').trim();
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
    },

    addToCart(item) {
      const priceVal = Number(item?.price_cents || 0) / 100;
      Model.setCartItem(item, priceVal, 1);
      const t = i18n[currentLang] || i18n.ru;
      View.renderCart(Model.cart, t);
      View.renderCartBadge(Model.cart);
      View.showToast(`${t.addedPrefix}${String(item?.name || '')}`);
    },

    updateCart(itemId, delta) {
      const id = String(itemId || '');
      const cur = Model.cart[id];
      if (!cur) return;
      Model.setCartItem(cur.item, cur.price, delta);
      const t = i18n[currentLang] || i18n.ru;
      View.renderCart(Model.cart, t);
      View.renderCartBadge(Model.cart);
    },

    bindLangMenu() {
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
    },

    bindCheckout() {
      if (!Dom.checkoutForm) return;
      Dom.checkoutForm.addEventListener('submit', (e) => Controller.submitOrder(e));
    },

    phoneDigits(raw) {
      return String(raw || '').replace(/\D+/g, '').slice(0, 15);
    },

    isPhoneValid(raw) {
      try {
        if (typeof libphonenumber === 'undefined') return /^[1-9]\d{6,15}$/.test(Controller.phoneDigits(raw));
        const parsed = libphonenumber.parsePhoneNumber(String(raw).trim());
        return parsed && parsed.isValid();
      } catch (e) {
        return false;
      }
    },

    getPhoneE164(raw) {
      try {
        if (typeof libphonenumber !== 'undefined') {
          const parsed = libphonenumber.parsePhoneNumber(String(raw).trim());
          if (parsed && parsed.isValid()) return parsed.format('E.164');
        }
      } catch (e) {}
      const digits = Controller.phoneDigits(raw);
      return digits ? ('+' + digits) : '';
    },

    async submitOrder(e) {
      e.preventDefault();
      const t = i18n[currentLang] || i18n.ru;
      if (Dom.checkoutError) Dom.checkoutError.hidden = true;
      if (!Dom.submitBtn) return;

      const name = String(Dom.orderName?.value || '').trim();
      const rawPhone = String(Dom.orderPhone?.value || '');
      const smEl = d.querySelector('input[name="service_mode"]:checked');
      const serviceMode = Number(smEl?.value || 2);

      if (!Controller.isPhoneValid(rawPhone)) {
        if (Dom.checkoutError) {
          Dom.checkoutError.textContent = t.phoneInvalid;
          Dom.checkoutError.hidden = false;
        }
        return;
      }

      const phoneE164 = Controller.getPhoneE164(rawPhone);
      const products = Object.values(Model.cart).map((c) => ({ product_id: Number(c.item?.id || 0), count: Number(c.count || 0) }));
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
          body: JSON.stringify({ name, phone: phoneE164, service_mode: serviceMode, products }),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(String(json.error || t.orderUnknown));
        View.showToast(t.orderSuccessPrefix + String(json.order_id));
        Model.clearCart();
        View.renderCart(Model.cart, t);
        View.renderCartBadge(Model.cart);
      } catch (err) {
        if (Dom.checkoutError) {
          Dom.checkoutError.textContent = String(err?.message || err);
          Dom.checkoutError.hidden = false;
        }
      } finally {
        Dom.submitBtn.disabled = false;
        Dom.submitBtn.textContent = t.submit;
      }
    },
  };

  Controller.init();
})();
