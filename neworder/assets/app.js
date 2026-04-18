const d = document;
let menuData = [];
let cart = {}; // product_id -> { item, count }

const elCategories = d.getElementById('categoriesSidebar');
const elMenu = d.getElementById('menuMain');
const elCartModal = d.getElementById('cartModal');
const elCheckoutModal = d.getElementById('checkoutModal');
const elCartBadge = d.getElementById('cartBadge');
const elCartItems = d.getElementById('cartItems');
const elCartTotal = d.getElementById('cartTotalSum');
const elCartFooter = d.getElementById('cartFooter');
const elEmptyCart = d.getElementById('emptyCart');
const elToast = d.getElementById('toast');

const fmtPrice = (val) => new Intl.NumberFormat('vi-VN').format(val) + ' đ';

async function loadMenu() {
  try {
    const res = await fetch('/neworder/api.php?ajax=get_menu');
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Ошибка загрузки меню');
    menuData = json.groups || [];
    renderMenu();
  } catch (err) {
    elMenu.innerHTML = `<div class="error-msg">Не удалось загрузить меню: ${err.message}</div>`;
  }
}

function renderMenu() {
  if (!menuData.length) {
    elMenu.innerHTML = '<div class="loading-state">Меню пусто</div>';
    return;
  }
  
  elCategories.innerHTML = '';
  elMenu.innerHTML = '';

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
    elMenu.appendChild(section);
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
  showToast(`Добавлено: ${item.name}`);
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
  elCartModal.hidden = !elCartModal.hidden;
  if (!elCartModal.hidden) renderCart();
}

function openCheckoutModal() {
  toggleCartModal();
  elCheckoutModal.hidden = false;
  d.getElementById('checkoutError').hidden = true;
}

function closeCheckoutModal() {
  elCheckoutModal.hidden = true;
}

function showToast(msg, isError = false) {
  elToast.textContent = msg;
  elToast.className = 'toast ' + (isError ? 'error' : '');
  elToast.hidden = false;
  setTimeout(() => elToast.hidden = true, 3000);
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
    errEl.textContent = 'Проверьте корректность номера телефона';
    errEl.hidden = false;
    return;
  }

  const phoneE164 = getPhoneE164(rawPhone);

  const products = Object.values(cart).map(c => ({
    product_id: Number(c.item.id),
    count: c.count
  }));

  btn.disabled = true;
  btn.textContent = 'Отправка...';

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
    if (!json.ok) throw new Error(json.error || 'Неизвестная ошибка');

    showToast('Заказ успешно создан! ID: ' + json.order_id);
    closeCheckoutModal();
    cart = {};
    updateCartBadge();
    
  } catch (err) {
    errEl.textContent = err.message;
    errEl.hidden = false;
  } finally {
    btn.disabled = false;
    btn.textContent = 'Подтвердить заказ';
  }
}

// Intercept clicks on overlay to close modal
d.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', (e) => {
    if (e.target === el) {
      el.hidden = true;
    }
  });
});

loadMenu();
