(() => {
  const d = document;

  const Dom = {
    pageTitleText: d.querySelector('#pageTitle span'),
    menuToggleBtn: d.getElementById('menuToggleBtn'),
    contentWrapper: d.getElementById('contentWrapper'),
    searchBar: d.getElementById('searchBar'),
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
    labelComment: d.getElementById('labelComment'),
    orderComment: d.getElementById('orderComment'),
    submitBtn: d.getElementById('submitBtn'),
    loadingState: d.getElementById('loadingState'),
    checkoutForm: d.getElementById('checkoutForm'),
    checkoutError: d.getElementById('checkoutError'),
    langMenu: d.getElementById('langMenu'),
    tableSelect: d.getElementById('tableIdSelect'),
    labelTable: d.getElementById('labelTable'),
    hallSelect: d.getElementById('hallIdSelect'),
    labelHall: d.getElementById('labelHall'),
    openChecksBtn: d.getElementById('openChecksBtn'),
    openChecksModal: d.getElementById('openChecksModal'),
    openChecksList: d.getElementById('openChecksList'),
    openChecksClose: d.getElementById('openChecksClose'),
    openChecksTitle: d.getElementById('openChecksTitle'),
    modifierModal: d.getElementById('modifierModal'),
    modifierList: d.getElementById('modifierList'),
    modifierTitle: d.getElementById('modifierTitle'),
    modifierClose: d.getElementById('modifierClose'),
  };

  const fmtPrice = (val) => new Intl.NumberFormat('vi-VN').format(val) + ' đ';

  const lsProductsKey = 'neworder_poster_products_v1';
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
    hall: 'Зал',
    spot: 'Заведение',
    table: 'Стол',
    name: 'Имя',
    namePh: 'Как к вам обращаться?',
    comment: 'Комментарий',
    commentPh: 'Комментарий к заказу',
    dishCommentPh: 'Комм',
    noModifier: 'Без модификатора',
    save: 'Сохранить',
    chooseModsErrorPrefix: 'Нужно выбрать модификаторы: ',
    openChecksTitle: 'Открытые чеки на столе',
    openChecksBtn: 'Открытые чеки',
    openChecksBtnCount: 'Открытые чеки: ',
    openChecksBtnSelected: 'Чек #',
    usePrevCheck: 'Использовать',
    newCheck: 'Новый чек',
    sum: 'Сумма: ',
    collapseAll: 'Свернуть меню',
    expandAll: 'Развернуть меню',
    submit: 'Подтвердить заказ',
    sending: 'Отправка...',
    orderUnknown: 'Неизвестная ошибка',
    orderSuccessPrefix: 'Заказ успешно создан! ID: ',
    addedToCheckPrefix: 'Добавлено в чек #',
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
    hall: 'Hall',
    spot: 'Spot',
    table: 'Table',
    name: 'Name',
    namePh: 'Your name',
    comment: 'Comment',
    commentPh: 'Order comment',
    dishCommentPh: 'Cmt',
    noModifier: 'No modifier',
    save: 'Save',
    chooseModsErrorPrefix: 'Select modifiers: ',
    openChecksTitle: 'Open checks for table',
    openChecksBtn: 'Open checks',
    openChecksBtnCount: 'Open checks: ',
    openChecksBtnSelected: 'Check #',
    usePrevCheck: 'Use',
    newCheck: 'New check',
    sum: 'Sum: ',
    collapseAll: 'Collapse menu',
    expandAll: 'Expand menu',
    submit: 'Place order',
    sending: 'Sending...',
    orderUnknown: 'Unknown error',
    orderSuccessPrefix: 'Order created! ID: ',
    addedToCheckPrefix: 'Added to check #',
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
    hall: 'Sảnh',
    spot: 'Cơ sở',
    table: 'Bàn',
    name: 'Tên',
    namePh: 'Bạn tên gì?',
    comment: 'Ghi chú',
    commentPh: 'Ghi chú cho đơn',
    noModifier: 'Không chọn',
    save: 'Lưu',
    chooseModsErrorPrefix: 'Chọn modifier: ',
    openChecksTitle: 'Hóa đơn đang mở theo bàn',
    openChecksBtn: 'Hóa đơn mở',
    openChecksBtnCount: 'Hóa đơn mở: ',
    openChecksBtnSelected: 'Hóa đơn #',
    usePrevCheck: 'Dùng',
    newCheck: 'Hóa đơn mới',
    sum: 'Tổng: ',
    collapseAll: 'Thu gọn menu',
    expandAll: 'Mở rộng menu',
    submit: 'Đặt hàng',
    sending: 'Đang gửi...',
    orderUnknown: 'Lỗi không xác định',
    orderSuccessPrefix: 'Đã tạo đơn! ID: ',
    addedToCheckPrefix: 'Đã thêm vào hóa đơn #',
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
    hall: '홀',
    spot: '매장',
    table: '테이블',
    name: '이름',
    namePh: '이름을 입력하세요',
    comment: '메모',
    commentPh: '주문 메모',
    noModifier: '없음',
    save: '저장',
    chooseModsErrorPrefix: '모디파이어 선택: ',
    openChecksTitle: '테이블의 열린 체크',
    openChecksBtn: '열린 체크',
    openChecksBtnCount: '열린 체크: ',
    openChecksBtnSelected: '체크 #',
    usePrevCheck: '사용',
    newCheck: '새 체크',
    sum: '합계: ',
    collapseAll: '메뉴 접기',
    expandAll: '메뉴 펼치기',
    submit: '주문 확정',
    sending: '전송 중...',
    orderUnknown: '알 수 없는 오류',
    orderSuccessPrefix: '주문 생성됨! ID: ',
    addedToCheckPrefix: '체크에 추가됨 #',
  }
  };

  const Config = {
    waiterId: 10,
    clientId: 71,
    spotId: 1,
    spotTabletId: 1,
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
    if (Dom.pageTitleText) Dom.pageTitleText.textContent = t.title;
    if (Dom.cartTitle) Dom.cartTitle.textContent = t.cart;
    if (Dom.emptyCart) Dom.emptyCart.textContent = t.emptyCart;
    if (Dom.cartTotalLabel) Dom.cartTotalLabel.textContent = t.total;
    if (Dom.checkoutTitle) Dom.checkoutTitle.textContent = t.checkout;
    if (Dom.labelName) Dom.labelName.textContent = t.name;
    if (Dom.labelComment) Dom.labelComment.textContent = t.comment;
    if (Dom.openChecksTitle) Dom.openChecksTitle.textContent = t.openChecksTitle;
    if (Dom.submitBtn && !Dom.submitBtn.disabled) Dom.submitBtn.textContent = t.submit;
    if (Dom.orderName) Dom.orderName.placeholder = t.namePh;
    if (Dom.orderComment) Dom.orderComment.placeholder = t.commentPh;
    if (Dom.loadingState) Dom.loadingState.textContent = t.menuLoading;
    if (Dom.searchInput) Dom.searchInput.placeholder = t.searchPlaceholder;
    if (Dom.labelTable) Dom.labelTable.textContent = t.table;
    if (Dom.labelHall) Dom.labelHall.textContent = t.hall;
    if (Dom.menuToggleBtn) Dom.menuToggleBtn.textContent = Model.menuCollapsed ? t.expandAll : t.collapseAll;
    viewSetActiveLangLink(currentLang);
  }

  function viewSetMenuCollapsed(isCollapsed) {
    const on = !!isCollapsed;
    d.body.classList.toggle('menu-only-cart', on);
    if (Dom.menuSections) Dom.menuSections.classList.toggle('is-collapsed', on);
    if (Dom.searchBar) Dom.searchBar.hidden = on;
    if (Dom.contentWrapper) Dom.contentWrapper.hidden = on;

    const t = i18n[currentLang] || i18n.ru;
    if (Dom.menuToggleBtn) {
      Dom.menuToggleBtn.hidden = false;
      Dom.menuToggleBtn.textContent = on ? t.expandAll : t.collapseAll;
    }
  }

  function viewShowToast(msg, isError) {
    if (!Dom.toast) return;
    Dom.toast.textContent = msg;
    Dom.toast.className = 'toast ' + (isError ? 'error' : '');
    Dom.toast.hidden = false;
    setTimeout(() => { Dom.toast.hidden = true; }, 3000);
  }

  function viewHideOpenChecksModal() {
    if (Dom.openChecksModal) Dom.openChecksModal.hidden = true;
    if (Dom.openChecksList) Dom.openChecksList.innerHTML = '';
  }

  function viewHideModifierModal() {
    if (Dom.modifierModal) Dom.modifierModal.hidden = true;
    if (Dom.modifierList) Dom.modifierList.innerHTML = '';
    if (Dom.modifierTitle) Dom.modifierTitle.textContent = '';
    if (Dom.modifierModal) {
      const header = Dom.modifierModal.querySelector('.modal-header');
      if (header) {
        const saveBtn = header.querySelector('#modifierSaveBtn');
        if (saveBtn) saveBtn.remove();
      }
    }
  }

  function viewSetModifierHeaderActions(handlers) {
    if (!Dom.modifierModal) return;
    const header = Dom.modifierModal.querySelector('.modal-header');
    if (!header) return;
    const existing = header.querySelector('#modifierSaveBtn');
    if (existing) existing.remove();
    if (!handlers || typeof handlers.onConfirm !== 'function') return;
    const t = i18n[currentLang] || i18n.ru;
    const btn = d.createElement('button');
    btn.type = 'button';
    btn.id = 'modifierSaveBtn';
    btn.className = 'btn btn-primary modal-save';
    btn.textContent = t.save || 'Save';
    btn.addEventListener('click', () => handlers.onConfirm());
    if (Dom.modifierClose && Dom.modifierClose.parentElement === header) {
      header.insertBefore(btn, Dom.modifierClose);
    } else {
      header.appendChild(btn);
    }
  }

  function viewShowModifierModal(item, modifications, handlers) {
    if (!Dom.modifierModal || !Dom.modifierList || !Dom.modifierTitle) return;
    const t = i18n[currentLang] || i18n.ru;
    viewSetModifierHeaderActions(handlers);
    Dom.modifierTitle.textContent = String((item && item.name) ? item.name : '');
    Dom.modifierList.innerHTML = '';

    const baseBtn = d.createElement('button');
    baseBtn.type = 'button';
    baseBtn.className = 'modifier-item';
    baseBtn.addEventListener('click', () => {
      if (handlers && typeof handlers.onSelect === 'function') handlers.onSelect({ id: 0, name: t.noModifier, price: null });
      const all = Array.from(Dom.modifierList.querySelectorAll('.modifier-item'));
      all.forEach((x) => x.classList.remove('is-selected'));
      baseBtn.classList.add('is-selected');
    });
    const baseName = d.createElement('div');
    baseName.className = 'modifier-name';
    baseName.textContent = t.noModifier;
    baseBtn.appendChild(baseName);
    if (handlers && typeof handlers.getSelectedId === 'function' && Number(handlers.getSelectedId() || 0) === 0) {
      baseBtn.classList.add('is-selected');
    }
    Dom.modifierList.appendChild(baseBtn);

    (modifications || []).forEach((m) => {
      const btn = d.createElement('button');
      btn.type = 'button';
      btn.className = 'modifier-item';
      btn.addEventListener('click', () => {
        if (handlers && typeof handlers.onSelect === 'function') handlers.onSelect(m);
        const all = Array.from(Dom.modifierList.querySelectorAll('.modifier-item'));
        all.forEach((x) => x.classList.remove('is-selected'));
        btn.classList.add('is-selected');
      });

      const name = d.createElement('div');
      name.className = 'modifier-name';
      name.textContent = String(m && m.name ? m.name : '');

      btn.appendChild(name);

      const priceVal = Number(m && m.price != null ? m.price : NaN);
      if (Number.isFinite(priceVal)) {
        const price = d.createElement('div');
        price.className = 'modifier-price';
        price.textContent = fmtPrice(priceVal);
        btn.appendChild(price);
      }

      const mid = Number(m && m.id ? m.id : 0) || 0;
      if (handlers && typeof handlers.getSelectedId === 'function' && Number(handlers.getSelectedId() || 0) === mid) {
        btn.classList.add('is-selected');
      }
      Dom.modifierList.appendChild(btn);
    });

    Dom.modifierModal.hidden = false;
  }

  function viewShowDishModsModal(item, groups, handlers) {
    if (!Dom.modifierModal || !Dom.modifierList || !Dom.modifierTitle) return;
    const t = i18n[currentLang] || i18n.ru;
    viewSetModifierHeaderActions(handlers);
    Dom.modifierTitle.textContent = String((item && item.name) ? item.name : '');
    Dom.modifierList.innerHTML = '';

    const uiByModId = new Map();
    const groupModIds = new Map();

    (groups || []).forEach((g) => {
      const group = g && typeof g === 'object' ? g : null;
      if (!group) return;
      const gid = Number(group.id || 0) || 0;
      if (!gid) return;

      const block = d.createElement('div');
      block.className = 'dishmod-group';

      const header = d.createElement('div');
      header.className = 'dishmod-group-header';
      const title = d.createElement('div');
      title.className = 'dishmod-group-title';
      const min = Number(group.min || 0) || 0;
      const max = Number(group.max || 0) || 0;
      title.textContent = `${String(group.name || '')}${max > 0 ? ` · ${String(min)}–${String(max)}` : ''}`;
      header.appendChild(title);
      block.appendChild(header);

      const mods = Array.isArray(group.mods) ? group.mods : [];
      const ids = [];
      mods.forEach((m) => {
        const mod = m && typeof m === 'object' ? m : null;
        if (!mod) return;
        const mid = Number(mod.id || 0) || 0;
        if (!mid) return;
        ids.push(mid);

        const row = d.createElement('div');
        row.className = 'dishmod-row';

        const name = d.createElement('div');
        name.className = 'dishmod-name';
        name.textContent = String(mod.name || '');
        row.appendChild(name);

        const priceVal = Number(mod.price != null ? mod.price : NaN);
        if (Number.isFinite(priceVal) && priceVal !== 0) {
          const price = d.createElement('div');
          price.className = 'dishmod-price';
          price.textContent = fmtPrice(priceVal);
          row.appendChild(price);
        }

        const controls = d.createElement('div');
        controls.className = 'dishmod-controls';

        const minus = d.createElement('button');
        minus.type = 'button';
        minus.className = 'dishmod-qty-btn';
        minus.textContent = '−';

        const count = d.createElement('div');
        count.className = 'dishmod-count';
        count.textContent = String(handlers && typeof handlers.getCount === 'function' ? handlers.getCount(gid, mid) : 0);

        const plus = d.createElement('button');
        plus.type = 'button';
        plus.className = 'dishmod-qty-btn';
        plus.textContent = '+';

        const updateGroupUi = () => {
          const groupTotal = handlers && typeof handlers.getGroupTotal === 'function' ? handlers.getGroupTotal(gid) : 0;
          const groupMax = handlers && typeof handlers.getGroupMax === 'function' ? handlers.getGroupMax(gid) : 0;
          const mids = groupModIds.get(gid) || [];
          mids.forEach((xid) => {
            const ui = uiByModId.get(xid);
            if (!ui) return;
            const c = handlers && typeof handlers.getCount === 'function' ? handlers.getCount(gid, xid) : 0;
            ui.count.textContent = String(c);
            ui.minus.disabled = c <= 0;
            ui.plus.disabled = groupMax > 0 && groupTotal >= groupMax;
          });
        };

        minus.addEventListener('click', () => {
          if (handlers && typeof handlers.onDelta === 'function') handlers.onDelta(gid, mid, -1);
          updateGroupUi();
        });
        plus.addEventListener('click', () => {
          if (handlers && typeof handlers.onDelta === 'function') handlers.onDelta(gid, mid, 1);
          updateGroupUi();
        });

        controls.appendChild(minus);
        controls.appendChild(count);
        controls.appendChild(plus);
        row.appendChild(controls);
        block.appendChild(row);

        uiByModId.set(mid, { minus, plus, count });
      });
      groupModIds.set(gid, ids);
      Dom.modifierList.appendChild(block);

      const groupTotal = handlers && typeof handlers.getGroupTotal === 'function' ? handlers.getGroupTotal(gid) : 0;
      const groupMax = handlers && typeof handlers.getGroupMax === 'function' ? handlers.getGroupMax(gid) : 0;
      ids.forEach((mid) => {
        const ui = uiByModId.get(mid);
        if (!ui) return;
        const c = handlers && typeof handlers.getCount === 'function' ? handlers.getCount(gid, mid) : 0;
        ui.minus.disabled = c <= 0;
        ui.plus.disabled = groupMax > 0 && groupTotal >= groupMax;
      });
    });

    Dom.modifierModal.hidden = false;
  }

  function viewSetOpenChecksButton(openCount, selectedTransactionId) {
    if (!Dom.openChecksBtn) return;
    const n = Number(openCount || 0) || 0;
    const selId = Number(selectedTransactionId || 0) || 0;
    const t = i18n[currentLang] || i18n.ru;
    Dom.openChecksBtn.hidden = n <= 0 && selId <= 0;
    if (selId > 0) {
      Dom.openChecksBtn.textContent = `${t.openChecksBtnSelected}${String(selId)}`;
      return;
    }
    if (n > 0) Dom.openChecksBtn.textContent = `${t.openChecksBtnCount}${String(n)}`;
  }

  function viewShowOpenChecksModal(transactions, handlers) {
    if (!Dom.openChecksModal || !Dom.openChecksList) return;
    const t = i18n[currentLang] || i18n.ru;
    Dom.openChecksList.innerHTML = '';
    (transactions || []).forEach((tr) => {
      const card = d.createElement('div');
      card.className = 'check-card';
      const h = d.createElement('h4');
      h.textContent = `#${String(tr.transaction_id || '')}`;
      card.appendChild(h);

      const meta = d.createElement('div');
      meta.className = 'check-meta';
      meta.textContent = `${t.sum}${String(tr.sum || '')}`;
      card.appendChild(meta);

      const comment = String(tr.comment || '').trim();
      if (comment !== '') {
        const cmt = d.createElement('div');
        cmt.className = 'check-comment';
        cmt.textContent = `${t.comment}: ${comment}`;
        card.appendChild(cmt);
      }

      const items = d.createElement('div');
      items.className = 'check-items';
      (tr.items || []).forEach((it) => {
        const row = d.createElement('div');
        row.className = 'check-item';
        const n = d.createElement('span');
        n.textContent = String(it.product_name || '');
        const c = d.createElement('span');
        c.textContent = String(it.num || '1');
        row.appendChild(n);
        row.appendChild(c);
        items.appendChild(row);
      });
      card.appendChild(items);

      const actions = d.createElement('div');
      actions.className = 'check-actions';
      const useBtn = d.createElement('button');
      useBtn.type = 'button';
      useBtn.className = 'btn btn-primary';
      useBtn.textContent = t.usePrevCheck;
      useBtn.addEventListener('click', () => {
        if (handlers && typeof handlers.onUseTransaction === 'function') {
          handlers.onUseTransaction(Number(tr.transaction_id || 0) || 0);
        }
      });
      const newBtn = d.createElement('button');
      newBtn.type = 'button';
      newBtn.className = 'btn';
      newBtn.textContent = t.newCheck;
      newBtn.addEventListener('click', () => {
        if (handlers && typeof handlers.onCreateNew === 'function') {
          handlers.onCreateNew();
        }
      });
      actions.appendChild(useBtn);
      actions.appendChild(newBtn);
      card.appendChild(actions);

      Dom.openChecksList.appendChild(card);
    });
    Dom.openChecksModal.hidden = false;
  }

  function viewRenderEmptyMenu(text) {
    if (!Dom.menuSections) return;
    Dom.menuSections.innerHTML = `<div class="loading-state">${text}</div>`;
  }

  function viewRenderMenu(groups, handlers) {
    if (!Dom.menuSections) return;
    Dom.menuSections.innerHTML = '';

    (groups || []).forEach((group, idx) => {
      const section = d.createElement('section');
      section.className = 'menu-section';
      section.id = `cat-${group.id}`;
      section.dataset.catId = String(group.id);

      const title = d.createElement('div');
      title.className = 'menu-section-title';
      title.textContent = group.title;
      title.tabIndex = 0;
      title.setAttribute('role', 'button');
      const isExpanded = handlers && typeof handlers.isCategoryExpanded === 'function'
        ? !!handlers.isCategoryExpanded(group.id)
        : false;
      section.classList.toggle('is-folded', !isExpanded);
      title.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      const toggle = () => {
        const foldedNow = section.classList.toggle('is-folded');
        title.setAttribute('aria-expanded', foldedNow ? 'false' : 'true');
        if (handlers && typeof handlers.onCategoryToggle === 'function') {
          handlers.onCategoryToggle(group.id, !foldedNow);
        }
      };
      title.addEventListener('click', toggle);
      title.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggle();
        }
      });
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
    });

    Dom.menuSections.classList.toggle('is-collapsed', !!Model.menuCollapsed);
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
      const cartKey = String((c && c.key) ? c.key : ((c.item && c.item.id) ? c.item.id : ''));
      if (!cartKey) return;
      const row = d.createElement('div');
      row.className = 'cart-item';

      const info = d.createElement('div');
      info.className = 'cart-item-info';

      const n = d.createElement('div');
      n.className = 'cart-item-name';
      const baseName = String((c.item && c.item.name) ? c.item.name : '');
      const modName = String(c.modificator_name || '').trim();
      const dishMods = Array.isArray(c.dish_mods) ? c.dish_mods : [];
      const dishLabel = dishMods
        .map((m) => {
          const name = String(m && m.name ? m.name : '').trim();
          const cnt = Number(m && m.count != null ? m.count : 0) || 0;
          if (!name) return '';
          return cnt > 1 ? `${name}×${String(cnt)}` : name;
        })
        .filter((x) => x !== '')
        .join(', ');
      let label = baseName;
      if (modName) label += ` (${modName})`;
      if (dishLabel) label += ` + ${dishLabel}`;
      n.textContent = label;

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
      minus.addEventListener('click', () => Controller.updateCart(cartKey, -1));

      const count = d.createElement('div');
      count.style.fontWeight = 'bold';
      count.textContent = String(c.count || 0);

      const plus = d.createElement('button');
      plus.className = 'qty-btn';
      plus.type = 'button';
      plus.textContent = '+';
      plus.addEventListener('click', () => Controller.updateCart(cartKey, 1));

      const commentInput = d.createElement('input');
      commentInput.type = 'text';
      commentInput.inputMode = 'text';
      commentInput.autocomplete = 'off';
      commentInput.spellcheck = false;
      commentInput.className = 'cart-item-comment';
      const t = i18n[currentLang] || i18n.ru;
      commentInput.placeholder = t.dishCommentPh || t.comment;
      commentInput.value = String(c.comment || '');
      commentInput.addEventListener('input', () => Controller.setCartComment(cartKey, commentInput.value));

      controls.appendChild(minus);
      controls.appendChild(count);
      controls.appendChild(commentInput);
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

  function viewRenderTableSelect(tables, selectedId) {
    if (!Dom.tableSelect) return;
    Dom.tableSelect.innerHTML = '';
    const t = i18n[currentLang] || i18n.ru;
    const empty = d.createElement('option');
    empty.value = '0';
    empty.textContent = t.table;
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
    const t = i18n[currentLang] || i18n.ru;
    const any = d.createElement('option');
    any.value = '0';
    any.textContent = t.hall;
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
    showOpenChecksModal: viewShowOpenChecksModal,
    hideOpenChecksModal: viewHideOpenChecksModal,
    showModifierModal: viewShowModifierModal,
    showDishModsModal: viewShowDishModsModal,
    hideModifierModal: viewHideModifierModal,
    setOpenChecksButton: viewSetOpenChecksButton,
    setMenuCollapsed: viewSetMenuCollapsed,
    renderEmptyMenu: viewRenderEmptyMenu,
    renderMenu: viewRenderMenu,
    renderCart: viewRenderCart,
    renderCartBadge: viewRenderCartBadge,
    renderTableSelect: viewRenderTableSelect,
    renderHallSelect: viewRenderHallSelect,
  };

  function modelLoadSelection() {
    try {
      const hid = Number(localStorage.getItem(lsHallKey) || 0) || 0;
      if (hid > 0) Model.hallId = hid;
    } catch (e) {}
  }

  function modelSaveSelection() {
    try {
      localStorage.setItem(lsHallKey, String(Number(Model.hallId || 0) || 0));
    } catch (e) {}
  }

  function modelSetQuery(q) {
    Model.query = String(q || '').trim();
  }

  function modelMakeCartKey(productId, modificatorId) {
    const pid = Number(productId || 0) || 0;
    const mid = Number(modificatorId || 0) || 0;
    if (!pid) return '';
    return mid > 0 ? `${String(pid)}:${String(mid)}` : String(pid);
  }

  function modelMakeDishModsKey(dishMods) {
    const arr = Array.isArray(dishMods) ? dishMods : [];
    const parts = [];
    arr.forEach((m) => {
      if (!m || typeof m !== 'object') return;
      const id = Number(m.id || 0) || 0;
      const cnt = Number(m.count || 0) || 0;
      if (!id || cnt <= 0) return;
      parts.push([id, cnt]);
    });
    parts.sort((a, b) => (Number(a[0] || 0) - Number(b[0] || 0)));
    return parts.map((x) => `${String(x[0])}x${String(x[1])}`).join(',');
  }

  function modelMakeFullCartKey(productId, modificatorId, dishModsKey) {
    const base = Model.makeCartKey(productId, modificatorId);
    const dm = String(dishModsKey || '').trim();
    if (!dm) return base;
    return `${base}|${dm}`;
  }

  function modelGetDishGroupMax(group) {
    const min = Number(group && group.min != null ? group.min : 0) || 0;
    let max = Number(group && group.max != null ? group.max : 0) || 0;
    if (max <= 0) max = 999;
    if (max < min) max = min;
    return max;
  }

  function modelInitDishModsState(groups) {
    const counts = {};
    const totals = {};
    (groups || []).forEach((g) => {
      const gid = Number(g && g.id ? g.id : 0) || 0;
      if (!gid) return;
      totals[String(gid)] = 0;
      (g.mods || []).forEach((m) => {
        const mid = Number(m && m.id ? m.id : 0) || 0;
        if (!mid) return;
        counts[String(gid) + ':' + String(mid)] = 0;
      });
    });
    return { counts, totals };
  }

  function modelDishModsGetCount(state, groupId, modId) {
    const key = String(groupId) + ':' + String(modId);
    return Number(state && state.counts && state.counts[key] != null ? state.counts[key] : 0) || 0;
  }

  function modelDishModsGetGroupTotal(state, groupId) {
    const key = String(groupId);
    return Number(state && state.totals && state.totals[key] != null ? state.totals[key] : 0) || 0;
  }

  function modelDishModsApplyDelta(groupsById, state, groupId, modId, delta) {
    const gid = Number(groupId || 0) || 0;
    const mid = Number(modId || 0) || 0;
    const dlt = Number(delta || 0) || 0;
    if (!gid || !mid || !dlt) return false;
    const group = groupsById.get(gid);
    if (!group) return false;
    const max = Model.getDishGroupMax(group);
    const cur = Model.dishModsGetCount(state, gid, mid);
    const next = Math.max(0, cur + (dlt > 0 ? 1 : -1));
    const curTotal = Model.dishModsGetGroupTotal(state, gid);
    const nextTotal = curTotal + (next - cur);
    if (nextTotal > max) return false;
    state.counts[String(gid) + ':' + String(mid)] = next;
    state.totals[String(gid)] = nextTotal;
    return true;
  }

  function modelDishModsValidate(groups, state) {
    const missing = [];
    (groups || []).forEach((g) => {
      const gid = Number(g && g.id ? g.id : 0) || 0;
      if (!gid) return;
      const min = Number(g && g.min != null ? g.min : 0) || 0;
      const total = Model.dishModsGetGroupTotal(state, gid);
      if (min > 0 && total < min) missing.push(String(g.name || '').trim() || String(gid));
    });
    return missing;
  }

  function modelDishModsBuildSelected(groups, state) {
    const out = [];
    (groups || []).forEach((g) => {
      const gid = Number(g && g.id ? g.id : 0) || 0;
      if (!gid) return;
      (g.mods || []).forEach((m) => {
        const mid = Number(m && m.id ? m.id : 0) || 0;
        if (!mid) return;
        const cnt = Model.dishModsGetCount(state, gid, mid);
        if (cnt <= 0) return;
        out.push({
          id: mid,
          count: cnt,
          name: String(m && m.name ? m.name : ''),
          price: Number(m && m.price != null ? m.price : 0) || 0,
        });
      });
    });
    return out;
  }

  function modelSetCartItem(item, price, delta, modificator, dishMods) {
    const pid = String((item && item.id) ? item.id : '');
    if (!pid) return;
    const mid = Number(modificator && modificator.id ? modificator.id : 0) || 0;
    const dishModsKey = Model.makeDishModsKey(dishMods);
    const key = Model.makeFullCartKey(pid, mid, dishModsKey);
    if (!key) return;
    if (!Model.cart[key]) {
      if (delta <= 0) return;
      const dishModsArr = Array.isArray(dishMods) ? dishMods : [];
      Model.cart[key] = {
        key,
        item,
        price,
        count: delta,
        comment: '',
        modificator_id: mid > 0 ? mid : 0,
        modificator_name: mid > 0 ? String(modificator && modificator.name ? modificator.name : '') : '',
        dish_mods: dishModsArr,
        modification: dishModsArr.map((m) => ({ id: Number(m.id || 0) || 0, count: Number(m.count || 0) || 0 })).filter((m) => m.id > 0 && m.count > 0),
      };
    } else {
      Model.cart[key].count = Number(Model.cart[key].count || 0) + delta;
      if (Model.cart[key].count <= 0) delete Model.cart[key];
    }
  }

  function modelSetCartComment(itemId, comment) {
    const id = String(itemId || '');
    if (!id) return;
    const cur = Model.cart[id];
    if (!cur) return;
    cur.comment = String(comment || '').slice(0, 200);
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
      const rawMods = Array.isArray(p.modifications) ? p.modifications : [];
      const mods = rawMods
        .filter((m) => m && typeof m === 'object')
        .map((m) => {
          const id = Number(m.modificator_id || m.id || 0) || 0;
          if (!id) return null;
          const name = String(m.modificator_name || m.name || '').trim();
          const priceCents = Model.extractPriceCents(m);
          const price = Number(priceCents || 0) / 100;
          return { id, name, price };
        })
        .filter(Boolean);

      const rawDishGroups = Array.isArray(p.group_modifications) ? p.group_modifications : [];
      const dishGroups = rawDishGroups
        .filter((g) => g && typeof g === 'object')
        .filter((g) => String(g.is_deleted || g.delete || '0') !== '1')
        .map((g) => {
          const id = Number(g.dish_modification_group_id || g.id || 0) || 0;
          if (!id) return null;
          const name = String(g.name || '').trim() || String(id);
          const min = Number(g.num_min != null ? g.num_min : 0) || 0;
          const maxRaw = Number(g.num_max != null ? g.num_max : 0) || 0;
          const max = maxRaw > 0 ? maxRaw : 999;
          const raw = Array.isArray(g.modifications) ? g.modifications : [];
          const mods = raw
            .filter((m) => m && typeof m === 'object')
            .map((m) => {
              const mid = Number(m.dish_modification_id || m.id || 0) || 0;
              if (!mid) return null;
              const mname = String(m.name || '').trim() || String(mid);
              const price = Number(m.price != null ? m.price : 0);
              return { id: mid, name: mname, price: Number.isFinite(price) ? price : 0 };
            })
            .filter(Boolean);
          return { id, name, min, max, mods };
        })
        .filter(Boolean)
        .filter((g) => Array.isArray(g.mods) && g.mods.length);
      catMap.get(key).items.push({
        id: String(productId),
        name: productName,
        price_cents: Model.extractPriceCents(p),
        modifications: mods,
        dish_modification_groups: dishGroups,
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
    halls: [],
    posterProducts: [],
    groupsAll: [],
    query: '',
    cart: {},
    tables: [],
    tableId: 0,
    hallId: 0,
    menuCollapsed: false,
    categoryExpanded: {},
    selectedTransactionId: 0,
    openTransactions: [],
    loadSelection: modelLoadSelection,
    saveSelection: modelSaveSelection,
    setQuery: modelSetQuery,
    setCartItem: modelSetCartItem,
    setCartComment: modelSetCartComment,
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
    makeCartKey: modelMakeCartKey,
    makeDishModsKey: modelMakeDishModsKey,
    makeFullCartKey: modelMakeFullCartKey,
    getDishGroupMax: modelGetDishGroupMax,
    initDishModsState: modelInitDishModsState,
    dishModsGetCount: modelDishModsGetCount,
    dishModsGetGroupTotal: modelDishModsGetGroupTotal,
    dishModsApplyDelta: modelDishModsApplyDelta,
    dishModsValidate: modelDishModsValidate,
    dishModsBuildSelected: modelDishModsBuildSelected,
  };

  function modelIsCategoryExpanded(catId) {
    const key = String(Number(catId || 0) || catId || '');
    if (!key) return false;
    return !!Model.categoryExpanded[key];
  }

  function modelSetCategoryExpanded(catId, expanded) {
    const key = String(Number(catId || 0) || catId || '');
    if (!key) return;
    if (expanded) Model.categoryExpanded[key] = true;
    else delete Model.categoryExpanded[key];
  }

  Model.isCategoryExpanded = modelIsCategoryExpanded;
  Model.setCategoryExpanded = modelSetCategoryExpanded;

  async function modelFetchOpenTransactions(spotId, tableId) {
    const sid = Number(spotId || Model.spotId || 1) || 1;
    const tid = Number(tableId || 0) || 0;
    if (!tid) return [];
    const res = await fetch(`/api/poster/neworder/index.php?ajax=get_open_transactions&spot_id=${encodeURIComponent(String(sid))}&table_id=${encodeURIComponent(String(tid))}`, {
      headers: { 'Accept': 'application/json' }
    });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(String((json && json.error) ? json.error : 'Failed'));
    return Array.isArray(json.transactions) ? json.transactions : [];
  }

  async function modelAddToTransaction(transactionId, products, comment) {
    const res = await fetch('/api/poster/neworder/index.php?ajax=add_to_transaction', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        spot_id: Number(Config.spotId || 1) || 1,
        spot_tablet_id: Config.spotTabletId,
        transaction_id: Number(transactionId || 0),
        products: products || [],
        comment: String(comment || '').trim(),
      }),
    });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(String((json && json.error) ? json.error : 'Failed'));
    return Number(json.added || 0) || 0;
  }

  Model.fetchOpenTransactions = modelFetchOpenTransactions;
  Model.addToTransaction = modelAddToTransaction;

  async function modelFetchTransaction(transactionId) {
    const txId = Number(transactionId || 0) || 0;
    if (!txId) throw new Error('transaction_id required');
    const res = await fetch(`/api/poster/neworder/index.php?ajax=get_transaction&transaction_id=${encodeURIComponent(String(txId))}`, {
      headers: { 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(String((json && json.error) ? json.error : 'Failed'));
    const tx = json.transaction && typeof json.transaction === 'object' ? json.transaction : null;
    if (!tx) throw new Error('Bad response');
    return tx;
  }

  Model.fetchTransaction = modelFetchTransaction;

  function controllerInit() {
    Controller.bindLangMenu();
    Controller.bindSearch();
    Controller.bindCheckout();
    Controller.bindSpotTable();
    Controller.bindOpenChecksModal();
    Controller.bindModifierModal();
    Controller.bindMenuToggle();

    const t = i18n[currentLang] || i18n.ru;
    View.applyLang(t);
    View.hideOpenChecksModal();
    View.setMenuCollapsed(Model.menuCollapsed);

    Model.loadSelection();
    Model.spotId = Number(Config.spotId || 1) || 1;
    View.setOpenChecksButton(0, 0);
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
      Model.spotId = Number(Config.spotId || 1) || 1;

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
      return;
    }
    View.renderMenu(groups, {
      onProductClick: (item) => Controller.addToCart(item),
      isCategoryExpanded: (catId) => Model.isCategoryExpanded(catId),
      onCategoryToggle: (catId, expanded) => Model.setCategoryExpanded(catId, expanded),
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
    const t = i18n[currentLang] || i18n.ru;
    const dishGroups = Array.isArray(item && item.dish_modification_groups ? item.dish_modification_groups : null) ? item.dish_modification_groups : [];
    if (dishGroups.length) {
      const state = Model.initDishModsState(dishGroups);
      const groupsById = new Map();
      dishGroups.forEach((g) => {
        const gid = Number(g && g.id ? g.id : 0) || 0;
        if (gid) groupsById.set(gid, g);
      });
      const handlers = {
        getCount: (groupId, modId) => Model.dishModsGetCount(state, groupId, modId),
        getGroupTotal: (groupId) => Model.dishModsGetGroupTotal(state, groupId),
        getGroupMax: (groupId) => Model.getDishGroupMax(groupsById.get(Number(groupId || 0) || 0)),
        onDelta: (groupId, modId, delta) => Model.dishModsApplyDelta(groupsById, state, groupId, modId, delta),
        onConfirm: () => {
          const missing = Model.dishModsValidate(dishGroups, state);
          if (missing.length) {
            View.showToast(t.chooseModsErrorPrefix + missing.join(', '), true);
            return;
          }
          const selected = Model.dishModsBuildSelected(dishGroups, state);
          const basePrice = Number(item && item.price_cents ? item.price_cents : 0) / 100;
          const addPrice = selected.reduce((acc, m) => acc + ((Number(m.price || 0) || 0) * (Number(m.count || 0) || 0)), 0);
          const priceVal = basePrice + addPrice;
          Model.setCartItem(item, priceVal, 1, null, selected);
          View.hideModifierModal();
          View.renderCart(Model.cart, t);
          View.renderCartBadge(Model.cart);
          const selLabel = selected.map((m) => {
            const name = String(m && m.name ? m.name : '').trim();
            const cnt = Number(m && m.count != null ? m.count : 0) || 0;
            if (!name) return '';
            return cnt > 1 ? `${name}×${String(cnt)}` : name;
          }).filter((x) => x !== '').join(', ');
          const label = selLabel ? `${String(item.name || '')} + ${selLabel}` : String(item.name || '');
          View.showToast(`${t.addedPrefix}${label}`);
        }
      };
      View.showDishModsModal(item, dishGroups, handlers);
      return;
    }
    const mods = Array.isArray(item && item.modifications ? item.modifications : null) ? item.modifications : [];
    if (mods.length) {
      const selected = { id: 0, name: t.noModifier, price: null };
      const handlers = {
        getSelectedId: () => Number(selected.id || 0) || 0,
        onSelect: (m) => {
          selected.id = Number(m && m.id ? m.id : 0) || 0;
          selected.name = String(m && m.name ? m.name : '');
          selected.price = (m && m.price != null) ? m.price : null;
        },
        onConfirm: () => {
          const modId = Number(selected.id || 0) || 0;
          const basePrice = Number(item && item.price_cents ? item.price_cents : 0) / 100;
          const modPrice = Number(selected.price != null ? selected.price : NaN);
          const priceVal = modId > 0 && Number.isFinite(modPrice) ? modPrice : basePrice;
          Model.setCartItem(item, priceVal, 1, { id: modId, name: String(selected.name || '') }, null);
          View.hideModifierModal();
          View.renderCart(Model.cart, t);
          View.renderCartBadge(Model.cart);
          const label = modId > 0 ? `${String(item.name || '')} (${String(selected.name || '')})` : String(item.name || '');
          View.showToast(`${t.addedPrefix}${label}`);
        },
      };
      View.showModifierModal(item, mods, handlers);
      return;
    }
    const priceVal = Number(item && item.price_cents ? item.price_cents : 0) / 100;
    Model.setCartItem(item, priceVal, 1, null, null);
    View.renderCart(Model.cart, t);
    View.renderCartBadge(Model.cart);
    View.showToast(`${t.addedPrefix}${String((item && item.name) ? item.name : '')}`);
  }

  function controllerUpdateCart(itemId, delta) {
    const id = String(itemId || '');
    const cur = Model.cart[id];
    if (!cur) return;
    Model.setCartItem(
      cur.item,
      cur.price,
      delta,
      { id: Number(cur.modificator_id || 0) || 0, name: String(cur.modificator_name || '') },
      Array.isArray(cur.dish_mods) ? cur.dish_mods : null
    );
    const t = i18n[currentLang] || i18n.ru;
    View.renderCart(Model.cart, t);
    View.renderCartBadge(Model.cart);
  }

  function controllerSetCartComment(itemId, comment) {
    Model.setCartComment(itemId, comment);
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
    if (Dom.hallSelect) {
      Dom.hallSelect.addEventListener('change', async () => {
        Controller.resetOpenTransactions();
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
      Dom.tableSelect.addEventListener('change', async () => {
        Controller.resetOpenTransactions();
        Model.tableId = Number(Dom.tableSelect.value || 0) || 0;
        Controller.checkOpenTransactions().catch(() => {});
      });
    }
  }

  function controllerResetOpenTransactions() {
    Model.selectedTransactionId = 0;
    Model.openTransactions = [];
    View.hideOpenChecksModal();
    View.setOpenChecksButton(0, 0);
  }

  async function controllerCheckOpenTransactions() {
    const reqId = (Controller.openTransactionsReqId = (Number(Controller.openTransactionsReqId || 0) || 0) + 1);
    const tableId = Number(Model.tableId || 0) || 0;
    const spotId = Number(Model.spotId || 0) || 0;

    if (!Model.tableId) return;
    try {
      const list = await Model.fetchOpenTransactions(spotId, tableId);
      if (reqId !== Controller.openTransactionsReqId) return;
      if (tableId !== (Number(Model.tableId || 0) || 0)) return;
      if (!Array.isArray(list) || !list.length) return;
      Model.openTransactions = list;
      View.setOpenChecksButton(list.length, Model.selectedTransactionId);
    } catch (e) {
      if (reqId !== Controller.openTransactionsReqId) return;
      Controller.resetOpenTransactions();
    }
  }

  function controllerBindOpenChecksModal() {
    if (Dom.openChecksBtn) {
      Dom.openChecksBtn.addEventListener('click', () => {
        if (!Array.isArray(Model.openTransactions) || !Model.openTransactions.length) return;
        const handlers = {
          onUseTransaction: (transactionId) => {
            Model.selectedTransactionId = Number(transactionId || 0) || 0;
            View.setOpenChecksButton(Model.openTransactions.length, Model.selectedTransactionId);
            View.hideOpenChecksModal();
          },
          onCreateNew: () => {
            Model.selectedTransactionId = 0;
            View.setOpenChecksButton(Model.openTransactions.length, 0);
            View.hideOpenChecksModal();
          },
        };

        View.showOpenChecksModal(Model.openTransactions, handlers);
        Controller.prefetchOpenChecksComments(handlers).catch(() => {});
      });
    }
    if (Dom.openChecksClose) {
      Dom.openChecksClose.addEventListener('click', () => View.hideOpenChecksModal());
    }
    if (Dom.openChecksModal) {
      Dom.openChecksModal.addEventListener('click', (e) => {
        if (e.target === Dom.openChecksModal) View.hideOpenChecksModal();
      });
    }
  }

  function controllerBindMenuToggle() {
    if (Dom.menuToggleBtn) {
      Dom.menuToggleBtn.addEventListener('click', () => {
        Model.menuCollapsed = !Model.menuCollapsed;
        View.setMenuCollapsed(Model.menuCollapsed);
      });
    }
  }

  async function controllerSubmitOrder(e) {
    e.preventDefault();
    const t = i18n[currentLang] || i18n.ru;
    if (Dom.checkoutError) Dom.checkoutError.hidden = true;
    if (!Dom.submitBtn) return;

    const name = String(Dom.orderName ? Dom.orderName.value : '').trim();
    const comment = String(Dom.orderComment ? Dom.orderComment.value : '').trim();
    const serviceMode = 1;
    const products = Object.values(Model.cart).map((c) => {
      const row = {
        product_id: Number((c.item && c.item.id) ? c.item.id : 0),
        modificator_id: Number(c.modificator_id || 0) || 0,
        count: Number(c.count || 0),
        comment: String(c.comment || '').trim(),
      };
      const mods = Array.isArray(c.modification) ? c.modification : [];
      if (mods.length) row.modification = mods;
      return row;
    });
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
      if (Number(Model.selectedTransactionId || 0) > 0) {
        const added = await Model.addToTransaction(Model.selectedTransactionId, products, comment);
        View.showToast(`${t.addedToCheckPrefix}${String(Model.selectedTransactionId)}: ${String(added)}`);
      } else {
        const res = await fetch('/api/poster/neworder/index.php?ajax=create_order', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name,
            comment,
            service_mode: serviceMode,
            products,
            waiter_id: Config.waiterId,
            client_id: Config.clientId,
            spot_id: Number(Config.spotId || 1) || 1,
            table_id: Number(Model.tableId || 0),
          }),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(String(json.error || t.orderUnknown));
        View.showToast(t.orderSuccessPrefix + String(json.order_id));
      }
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
    searchTimer: 0,
    openTransactionsReqId: 0,
    init: controllerInit,
    loadProducts: controllerLoadProducts,
    refreshMenu: controllerRefreshMenu,
    bindSearch: controllerBindSearch,
    onSearchInput: controllerOnSearchInput,
    addToCart: controllerAddToCart,
    updateCart: controllerUpdateCart,
    setCartComment: controllerSetCartComment,
    bindLangMenu: controllerBindLangMenu,
    bindCheckout: controllerBindCheckout,
    bindSpotTable: controllerBindSpotTable,
    resetOpenTransactions: controllerResetOpenTransactions,
    checkOpenTransactions: controllerCheckOpenTransactions,
    bindOpenChecksModal: controllerBindOpenChecksModal,
    prefetchOpenChecksComments: controllerPrefetchOpenChecksComments,
    bindModifierModal: controllerBindModifierModal,
    bindMenuToggle: controllerBindMenuToggle,
    submitOrder: controllerSubmitOrder,
  };

  function controllerBindModifierModal() {
    if (Dom.modifierClose) {
      Dom.modifierClose.addEventListener('click', () => View.hideModifierModal());
    }
    if (Dom.modifierModal) {
      Dom.modifierModal.addEventListener('click', (e) => {
        if (e.target === Dom.modifierModal) View.hideModifierModal();
      });
    }
  }

  async function controllerPrefetchOpenChecksComments(handlers) {
    const modal = Dom.openChecksModal;
    if (!modal || modal.hidden) return;
    if (!Array.isArray(Model.openTransactions) || !Model.openTransactions.length) return;

    const reqId = (Controller.openChecksDetailsReqId = (Number(Controller.openChecksDetailsReqId || 0) || 0) + 1);
    const list = Model.openTransactions.slice(0);
    const out = [];
    for (const tr of list) {
      const txId = Number(tr && tr.transaction_id ? tr.transaction_id : 0) || 0;
      if (!txId) {
        out.push(tr);
        continue;
      }
      if (String(tr.comment || '').trim() !== '') {
        out.push(tr);
        continue;
      }
      try {
        const tx = await Model.fetchTransaction(txId);
        if (reqId !== Controller.openChecksDetailsReqId) return;
        out.push(Object.assign({}, tr, { comment: String(tx.comment || '').trim() }));
      } catch (e) {
        if (reqId !== Controller.openChecksDetailsReqId) return;
        out.push(tr);
      }
    }
    if (reqId !== Controller.openChecksDetailsReqId) return;
    if (!Dom.openChecksModal || Dom.openChecksModal.hidden) return;

    Model.openTransactions = out;
    View.showOpenChecksModal(Model.openTransactions, handlers);
  }

  Controller.init();
})();
