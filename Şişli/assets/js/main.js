(() => {
  'use strict';
  const activeOffers = window.Shishli.getActiveOffers();
  const products = window.Shishli.products.map(product => {
    const offer = activeOffers.find(item => item.productId === product.id);
    return offer ? { ...product, price: offer.price, originalPrice: product.price, label: 'عرض خاص' } : product;
  });
  const $ = (selector, root = document) => root.querySelector(selector);
  // Lucide icon paths, ISC license: https://lucide.dev/license
  const paths = {
    'shopping-bag': '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'user-round': '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    x: '<path d="m18 6-12 12M6 6l12 12"/>',
    'arrow-left': '<path d="M19 12H5m7 7-7-7 7-7"/>',
    'arrow-up-left': '<path d="M7 17V7h10M7 7l10 10"/>',
    utensils: '<path d="M4 3v7a2 2 0 0 0 4 0V3M6 3v19M20 22V3c-4 0-4 8-4 10h4"/>',
    flame: '<path d="M12 3c-1 4 4 6 4 9 0 1-1 2-2 2-2 0-3-2-3-4-3 2-5 4-5 7a6 6 0 0 0 12 0c0-5-3-9-6-14Z"/>',
    drumstick: '<path d="M15.4 15.4c3.3-1 5.6-4 5.6-7.2A5.2 5.2 0 0 0 15.8 3c-3.2 0-6.2 2.3-7.2 5.6a5.3 5.3 0 0 0 6.8 6.8Z"/><path d="m8.5 10.5-5 5a2 2 0 0 0 0 3 2 2 0 0 0 3 2l5-5"/>',
    'badge-check': '<path d="m9 12 2 2 4-4M12 2l3 2 4 .5.5 4L22 12l-2.5 3.5-.5 4-4 .5-3 2-3-2-4-.5-.5-4L2 12l2.5-3.5.5-4L9 4Z"/>',
    truck: '<path d="M1 3h14v13H1zM15 8h4l3 4v4h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'map-pin': '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    instagram: '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
    'music-2': '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    ghost: '<path d="M9 10h.01M15 10h.01M12 2a8 8 0 0 0-8 8v12l4-3 4 3 4-3 4 3V10a8 8 0 0 0-8-8Z"/>',
    'message-circle': '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/>'
  };
  const icon = name => `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths['shopping-bag']}</svg>`;
  document.querySelectorAll('[data-icon]').forEach(element => element.outerHTML = icon(element.dataset.icon));
  const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const money = value => `${new Intl.NumberFormat('ar-SA', { numberingSystem: 'latn' }).format(value)} ريال`;
  const photo = product => `<div class="product-photo photo-${escape(product.photo)}" role="img" aria-label="${escape(product.name)}">${product.image ? `<img src="${escape(product.image)}" alt="" loading="lazy">` : ''}</div>`;
  if ($('#product-detail')) {
    const id = Number(new URLSearchParams(location.search).get('id'));
    const product = products.find(item => item.id === id);
    if (!product) {
      $('#product-detail').innerHTML = '<section class="checkout-empty"><h1>المنتج غير موجود</h1><p>اختر أحد الأطباق المتاحة من المنيو.</p><a class="button button-primary" href="menu.html">تصفح المنيو</a></section>';
    } else {
      document.title = `${product.name} | مطعم شيشلي`;
      $('meta[name="description"]').content = product.description || product.name;
      const ingredients = Array.isArray(product.ingredients) ? product.ingredients.join('، ') : product.ingredients;
      const allergens = Array.isArray(product.allergens) ? product.allergens.join('، ') : product.allergens;
      const weight = Number.isFinite(product.weight) && product.weight > 0 ? `${product.weight} جرام` : 'غير محدد';
      const calories = Number.isFinite(product.calories) && product.calories >= 0 ? `${product.calories} كيلو سعرة` : 'غير محددة';
      $('#product-detail').innerHTML = `<article class="product-detail-layout"><div class="product-detail-image">${photo(product)}${product.label ? `<span class="detail-badge">${escape(product.label)}</span>` : ''}</div><div class="product-detail-copy"><span class="section-kicker">${escape(product.category)}</span><h1>${escape(product.name)}</h1><p class="detail-description">${escape(product.description || 'وصف المنتج سيُضاف قريبًا.')}</p><div class="detail-price">${product.originalPrice ? `<del>${money(product.originalPrice)}</del>` : ''}<strong>${money(product.price)}</strong></div><dl class="nutrition-details"><div><dt>الوزن لكل حصة</dt><dd>${weight}</dd></div><div><dt>السعرات لكل حصة</dt><dd>${calories}</dd></div></dl><div class="detail-section"><h2>المكونات</h2><p>${escape(ingredients || 'لم تُضف تفاصيل المكونات لهذا المنتج بعد.')}</p></div><div class="detail-section"><h2>مسببات الحساسية</h2><p>${escape(allergens || 'المعلومات غير متوفرة حاليًا. يُرجى التواصل مع المطعم للاستفسار.')}</p></div><div class="detail-order"><label class="detail-quantity" for="detail-quantity">الكمية<input id="detail-quantity" type="number" min="1" max="99" step="1" value="1" inputmode="numeric"></label><button class="button button-primary" type="button" data-add="${product.id}" data-detail-add>${icon('shopping-bag')} إضافة للسلة</button></div><button class="text-button" type="button" data-cart-open>مراجعة السلة وإكمال الطلب ${icon('arrow-left')}</button></div></article>`;
    }
  }
  if (activeOffers.length && $('#popular')) {
    const offersSection = document.createElement('section');
    offersSection.className = 'offers-section container';
    offersSection.id = 'offers';
    offersSection.setAttribute('aria-labelledby', 'offers-title');
    offersSection.innerHTML = `<div class="section-heading"><div><span class="section-kicker">طعمك المفضل بسعر أحلى</span><h2 id="offers-title">عروض شيشلي <span class="title-flourish">✦</span></h2><p>اختر عرضك وأضفه مباشرة إلى سلتك</p></div></div><div class="offers-grid">${activeOffers.map(offer => {
      const product = products.find(item => item.id === offer.productId);
      const discount = Math.round((1 - offer.price / offer.originalPrice) * 100);
      return `<article class="offer-card"><a class="product-image-link" href="product.html?id=${product.id}" aria-label="تفاصيل ${escape(product.name)}">${photo(product)}</a><div class="offer-content"><span class="offer-badge">${discount > 0 ? `خصم ${discount}٪` : 'سعر خاص'}</span><h3>${escape(offer.title)}</h3><p>${escape(product.name)}</p><div class="offer-prices"><del aria-label="السعر السابق">${money(offer.originalPrice)}</del><strong>${money(offer.price)}</strong></div><button class="button button-primary" type="button" data-add="${product.id}" aria-label="إضافة عرض ${escape(product.name)} للسلة">${icon('shopping-bag')} أضف العرض للسلة</button></div></article>`;
    }).join('')}</div>`;
    $('#popular').before(offersSection);
  }
  let toastTimeout;
  function toast(message) {
    $('#toast').textContent = message;
    $('#toast').classList.add('visible');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => $('#toast').classList.remove('visible'), 3200);
  }
  // Storage adapter: replace load/save with an API implementation when needed.
  const storage = {
    key: 'shishli.cart.v1',
    load() { try { return JSON.parse(localStorage.getItem(this.key) || '[]'); } catch { return []; } },
    save(items) { try { localStorage.setItem(this.key, JSON.stringify(items)); return true; } catch { return false; } }
  };
  function normalize(items) {
    const valid = new Map();
    if (!Array.isArray(items)) return [];
    items.forEach(item => {
      if (!item || !products.some(product => product.id === item.id) || !Number.isInteger(item.quantity) || item.quantity < 1) return;
      valid.set(item.id, { id: item.id, quantity: Math.min(99, item.quantity) });
    });
    return [...valid.values()];
  }
  let cart = normalize(storage.load());
  window.Shishli.getCart = () => cart.map(item => ({ ...products.find(product => product.id === item.id), quantity: item.quantity }));
  const isMenuPage = document.body.classList.contains('menu-page');
  let selectedCategory = 'الكل';
  const categories = $('#category-filters');
  if (categories) {
    categories.innerHTML = ['الكل', ...new Set(products.map(product => product.category))].map(category => `<button type="button" data-category="${escape(category)}" aria-pressed="${category === selectedCategory}">${escape(category)}</button>`).join('');
    categories.addEventListener('click', event => {
      const button = event.target.closest('[data-category]');
      if (!button) return;
      selectedCategory = button.dataset.category;
      categories.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      renderProducts($('#product-search').value);
    });
    $('#product-sort').addEventListener('change', () => renderProducts($('#product-search').value));
  }
  function renderProducts(query = '') {
    if (!$('#products-grid')) return;
    const filtered = products.filter(product => `${product.name} ${product.category}`.includes(query.trim()) && (selectedCategory === 'الكل' || product.category === selectedCategory));
    const sort = $('#product-sort')?.value;
    if (sort === 'price-asc') filtered.sort((a, b) => a.price - b.price);
    if (sort === 'price-desc') filtered.sort((a, b) => b.price - a.price);
    if ($('#product-results')) $('#product-results').textContent = `عرض ${filtered.length} من ${products.length} أطباق`;
    $('#products-grid').innerHTML = filtered.map(product => `<article class="product-card"><a class="product-image-link" href="product.html?id=${product.id}" aria-label="تفاصيل ${escape(product.name)}">${photo(product).replace('</div>', `${product.label ? `<span class="product-tag">${escape(product.label)}</span>` : ''}</div>`)}</a><div class="product-content"><h3><a href="product.html?id=${product.id}">${escape(product.name)}</a></h3><p>${escape(product.description)}</p><div class="product-bottom"><span class="price">${product.originalPrice ? `<del class="original-price">${money(product.originalPrice)}</del>` : ''}${product.price} <small>ريال</small></span><button class="add-button" type="button" data-add="${product.id}" aria-label="إضافة ${escape(product.name)} للسلة">${icon('shopping-bag')} إضافة للسلة</button></div></div></article>`).join('');
    $('#empty-search').hidden = filtered.length !== 0;
  }
  function renderCart() {
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    $('#cart-count').textContent = count;
    $('.cart-toggle').setAttribute('aria-label', `فتح السلة، ${count} منتجات`);
    $('#cart-items').innerHTML = cart.length ? cart.map(item => {
      const product = products.find(product => product.id === item.id);
      return `<article class="cart-row">${photo(product)}<div class="cart-row-info"><h3>${escape(product.name)}</h3><p>${money(product.price * item.quantity)}</p><div class="quantity-controls"><button type="button" data-quantity="${item.id}" data-delta="1" aria-label="زيادة كمية ${escape(product.name)}" ${item.quantity === 99 ? 'disabled' : ''}>+</button><span>${item.quantity}</span><button type="button" data-quantity="${item.id}" data-delta="-1" aria-label="تقليل كمية ${escape(product.name)}">−</button><button class="remove-item" type="button" data-remove="${item.id}" aria-label="حذف ${escape(product.name)}">حذف</button></div></div></article>`;
    }).join('') : `<div class="cart-empty">${icon('shopping-bag')}<p>سلتك بانتظار شيء لذيذ!</p><p>اختر أطباقك المفضلة من المنيو.</p></div>`;
    $('#cart-total').textContent = money(cart.reduce((sum, item) => sum + products.find(product => product.id === item.id).price * item.quantity, 0));
    document.querySelectorAll('[data-checkout-link]').forEach(link => { link.hidden = count === 0; });
    window.dispatchEvent(new Event('shishli:cart-updated'));
  }
  function commitCart() {
    if (!storage.save(cart)) toast('حُدّثت السلة مؤقتًا. التخزين المحلي غير متاح في متصفحك.');
    renderCart();
  }
  const menuButton = $('#menu-toggle');
  function closeMenu() { $('#main-nav').classList.remove('is-open'); menuButton.setAttribute('aria-expanded', 'false'); menuButton.setAttribute('aria-label', 'فتح القائمة'); }
  menuButton.addEventListener('click', () => {
    const open = $('#main-nav').classList.toggle('is-open');
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', open ? 'إغلاق القائمة' : 'فتح القائمة');
  });
  $('#main-nav').addEventListener('click', event => { if (event.target.closest('a,button')) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && $('#main-nav').classList.contains('is-open')) { closeMenu(); menuButton.focus(); } });
  document.addEventListener('click', event => { if (!event.target.closest('.site-header')) closeMenu(); });
  matchMedia('(min-width:1100px)').addEventListener('change', closeMenu);
  const header = $('#site-header');
  const updateHeader = () => header.classList.toggle('scrolled', scrollY > 12);
  addEventListener('scroll', updateHeader, { passive: true });
  updateHeader();
  function openDialog(dialog) { closeMenu(); dialog.showModal(); document.body.classList.add('dialog-open'); }
  document.querySelectorAll('dialog').forEach(dialog => {
    dialog.addEventListener('close', () => document.body.classList.remove('dialog-open'));
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
  });
  function showInfo(title, content) { $('#info-title').textContent = title; $('#info-content').innerHTML = content; openDialog($('#info-dialog')); }
  document.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button) return;
    if (button.hasAttribute('data-add')) {
      const id = Number(button.dataset.add), product = products.find(product => product.id === id);
      if (!product) return;
      const item = cart.find(item => item.id === id);
      const quantityInput = button.hasAttribute('data-detail-add') ? $('#detail-quantity') : null;
      const quantity = quantityInput ? Number(quantityInput.value) : 1;
      if (!Number.isInteger(quantity) || quantity < 1 || quantity > 99) { quantityInput?.reportValidity(); toast('اختر كمية صحيحة من 1 إلى 99.'); return; }
      if ((item?.quantity || 0) + quantity > 99) { toast('الحد الأقصى لكل طبق في السلة 99.'); return; }
      if (item) item.quantity += quantity; else cart.push({ id, quantity });
      toast(`أُضيف ${product.name} إلى سلتك`); commitCart();
    }
    if (button.hasAttribute('data-cart-open')) { renderCart(); openDialog($('#cart-dialog')); }
    if (button.hasAttribute('data-close')) button.closest('dialog').close();
    if (button.hasAttribute('data-quantity')) {
      const item = cart.find(item => item.id === Number(button.dataset.quantity));
      if (!item) return;
      const id = item.id, delta = button.dataset.delta;
      item.quantity = Math.min(99, item.quantity + Number(delta));
      cart = cart.filter(item => item.quantity > 0); commitCart();
      ($(`[data-quantity="${id}"][data-delta="${delta}"]`) || $('[data-quantity]') || $('#cart-dialog [data-close]')).focus();
    }
    if (button.hasAttribute('data-remove')) { cart = cart.filter(item => item.id !== Number(button.dataset.remove)); commitCart(); ($('[data-remove]') || $('#cart-dialog [data-close]')).focus(); }
    if (button.hasAttribute('data-social')) showInfo(`شيشلي على ${button.dataset.social}`, '<p>سيُضاف رابط الحساب الرسمي عند توفيره من المطعم. يمكنك الآن التواصل معنا على الهاتف.</p><a class="button button-primary" href="tel:0551040122">اتصل بنا</a>');
    if (button.hasAttribute('data-search-open')) { $('#search-bar').hidden = false; $('#popular').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); $('#product-search').focus({ preventScroll: true }); }
  });
  $('#product-search')?.addEventListener('input', event => renderProducts(event.target.value));
  $('#search-close')?.addEventListener('click', () => { $('#search-bar').hidden = !isMenuPage; $('#product-search').value = ''; renderProducts(); (isMenuPage ? $('#product-search') : $('[data-search-open]')).focus(); });
  if ($('#about-more')) {
  $('#about-more').setAttribute('aria-expanded', 'false');
  $('#about-more').setAttribute('aria-controls', 'about-details');
  $('#about-more').addEventListener('click', event => { const details = $('#about-details'); details.hidden = !details.hidden; event.currentTarget.setAttribute('aria-expanded', String(!details.hidden)); });
  }
  addEventListener('storage', event => { if (event.key === storage.key || event.key === null) { cart = normalize(storage.load()); renderCart(); } });
  const galleryPhotos = $('#gallery-photos');
  if (galleryPhotos) {
    galleryPhotos.innerHTML = products.map((product, index) => `<figure class="gallery-banner-item"><a class="gallery-menu-link" href="menu.html" aria-label="تصفح المنيو — ${escape(product.name)}">${photo(product)}</a><figcaption><div><h3>${escape(product.name)}</h3><p>${escape(product.category)}</p></div><span class="gallery-photo-number" aria-hidden="true">${String(index + 1).padStart(2, '0')}</span></figcaption></figure>`).join('');
    const previous = $('#gallery-prev'), next = $('#gallery-next');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    function updateGallery() {
      const max = Math.max(0, galleryPhotos.scrollWidth - galleryPhotos.clientWidth);
      const position = Math.min(max, Math.abs(galleryPhotos.scrollLeft));
      previous.disabled = position < 2;
      next.disabled = position >= max - 2;
      const bounds = galleryPhotos.getBoundingClientRect();
      const visible = [...galleryPhotos.children].map((card, index) => ({ rect: card.getBoundingClientRect(), index })).filter(({rect}) => rect.right > bounds.left + 10 && rect.left < bounds.right - 10);
      const first = visible[0]?.index + 1 || 0, last = visible.at(-1)?.index + 1 || 0;
      $('#gallery-count').textContent = `${first}–${last} / ${products.length}`;
      $('#gallery-progress').style.width = `${galleryPhotos.scrollWidth ? Math.min(100, (position + galleryPhotos.clientWidth) / galleryPhotos.scrollWidth * 100) : 100}%`;
    }
    function moveGallery(direction) {
      const card = galleryPhotos.firstElementChild;
      if (!card) return;
      const step = card.getBoundingClientRect().width + parseFloat(getComputedStyle(galleryPhotos).gap);
      galleryPhotos.scrollBy({ left: -direction * step, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
    }
    previous.addEventListener('click', () => moveGallery(-1));
    next.addEventListener('click', () => moveGallery(1));
    galleryPhotos.addEventListener('keydown', event => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault(); moveGallery(event.key === 'ArrowLeft' ? 1 : -1);
    });
    galleryPhotos.addEventListener('scroll', updateGallery, { passive: true });
    galleryPhotos.addEventListener('scrollend', () => {
      if (!autoplayTimer) $('#gallery-status').textContent = `الصور ${$('#gallery-count').textContent}`;
    });
    const carousel = $('#gallery-carousel');
    const playbackButton = document.createElement('button');
    playbackButton.type = 'button';
    playbackButton.className = 'button button-outline gallery-playback';
    carousel.querySelector('.gallery-controls').append(playbackButton);
    let autoplayTimer;
    let paused = reducedMotion.matches;
    let hovering = false;
    function syncAutoplay() {
      clearInterval(autoplayTimer);
      autoplayTimer = undefined;
      playbackButton.textContent = paused ? 'تشغيل' : 'إيقاف';
      playbackButton.setAttribute('aria-label', paused ? 'تشغيل التنقل التلقائي' : 'إيقاف التنقل التلقائي');
      if (paused || hovering || document.hidden || carousel.contains(document.activeElement)) return;
      autoplayTimer = setInterval(() => {
        const max = galleryPhotos.scrollWidth - galleryPhotos.clientWidth;
        if (max <= 2) return;
        if (Math.abs(galleryPhotos.scrollLeft) >= max - 2) {
          galleryPhotos.scrollTo({ left: 0, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
        } else {
          moveGallery(1);
        }
      }, 2000);
    }
    playbackButton.addEventListener('click', () => { paused = !paused; syncAutoplay(); });
    carousel.addEventListener('mouseenter', () => { hovering = true; syncAutoplay(); });
    carousel.addEventListener('mouseleave', () => { hovering = false; syncAutoplay(); });
    carousel.addEventListener('focusin', syncAutoplay);
    carousel.addEventListener('focusout', () => setTimeout(syncAutoplay, 0));
    carousel.addEventListener('touchstart', () => { hovering = true; syncAutoplay(); }, { passive: true });
    const resumeAfterTouch = () => { hovering = false; syncAutoplay(); };
    carousel.addEventListener('touchend', resumeAfterTouch, { passive: true });
    carousel.addEventListener('touchcancel', resumeAfterTouch, { passive: true });
    document.addEventListener('visibilitychange', syncAutoplay);
    reducedMotion.addEventListener('change', () => { paused = reducedMotion.matches; syncAutoplay(); });
    syncAutoplay();
    new ResizeObserver(updateGallery).observe(galleryPhotos);
    updateGallery();
  }
  renderProducts(); renderCart();
})();
