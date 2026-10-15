/* Replace this catalog with a Laravel API response in a later phase.
   Set image to a standalone asset to replace the reference-image placeholder. */
window.Shishli = window.Shishli || {};
window.Shishli.products = [
  { id: 1, name: 'نصف دجاج تركي', description: 'نصف دجاجة بتتبيلتنا التركية، مشوية على الفحم', price: 17, category: 'الدجاج التركي', image: '', photo: 'chicken', label: 'اختيار شيشلي' },
  { id: 2, name: 'كباب لحم', description: 'كباب لحم طري، بنكهة الفحم الأصيلة', price: 25, category: 'المشويات', image: '', photo: 'kebab' },
  { id: 3, name: 'مشكل مشويات', description: 'تشكيلة تجمع لك ألذ مشوياتنا في طبق واحد', price: 45, category: 'المشويات', image: '', photo: 'mixed' },
  { id: 4, name: 'شيش طاووق', description: 'قطع دجاج طرية بتتبيلة تركية مميزة', price: 20, category: 'الشيش', image: '', photo: 'shish' },
  { id: 5, name: 'دجاج تركي مع أرز', description: 'دجاج مشوي مع أرز شهي… وجبتك المتكاملة', price: 25, category: 'الدجاج التركي', image: '', photo: 'rice' }
];

// أضف عروضك هنا. القائمة الفارغة تخفي قسم العروض بالكامل.
// مثال: { productId: 4, title: 'عرض الشيش', price: 16, active: true }
// productId هو رقم المنتج، وprice سعره بعد الخصم. لتعطيل العرض: active: false.
// عروض تجريبية للمعاينة — عدّل الأسعار أو أفرغ القائمة قبل النشر.
window.Shishli.offers = [
  { productId: 4, title: 'عرض الشيش — تجريبي', price: 16, active: true },
  { productId: 3, title: 'عرض المشويات — تجريبي', price: 39, active: true }
];

// Ignore disabled/invalid offers. If a product has multiple offers, use the lowest price.
window.Shishli.getActiveOffers = function () {
  const selected = new Map();
  const offers = Array.isArray(this.offers) ? this.offers : [];
  offers.forEach(offer => {
    if (!offer || offer.active !== true) return;
    const product = this.products.find(item => item.id === offer.productId);
    if (!product || !Number.isFinite(offer.price) || offer.price <= 0 || offer.price >= product.price) return;
    if (selected.has(product.id) && selected.get(product.id).price <= offer.price) return;
    selected.set(product.id, { ...offer, title: String(offer.title || product.name), originalPrice: product.price });
  });
  return [...selected.values()];
};
