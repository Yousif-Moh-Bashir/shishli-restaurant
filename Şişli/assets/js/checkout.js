(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const form = $('checkout-form');
  const money = value => `${new Intl.NumberFormat('ar-SA', { numberingSystem: 'latn' }).format(value)} ريال`;
  let confirmed = false;
  let locationRequest = 0;
  let locating = false;
  let automaticAddress = '';
  const isDelivery = () => form.querySelector('[name="fulfillment"]:checked').value === 'delivery';
  function clearLocation() {
    locationRequest++;
    locating = false;
    $('locate-delivery').disabled = false;
    ['delivery-latitude','delivery-longitude','delivery-accuracy'].forEach(id => $(id).value = '');
    $('delivery-location-map').hidden = true;
    $('delivery-location-map').removeAttribute('href');
    $('clear-delivery-location').hidden = true;
    if ($('order-address').value === automaticAddress) $('order-address').value = '';
    automaticAddress = '';
    $('delivery-location-status').textContent = 'يمكنك تحديد موقعك أو كتابة عنوان التوصيل يدويًا.';
  }
  function locateDelivery() {
    if (!isDelivery() || locating) return;
    if (!window.isSecureContext || !navigator.geolocation) {
      $('delivery-location-status').textContent = 'تحديد الموقع غير متاح هنا. استخدم HTTPS أو localhost، أو اكتب العنوان يدويًا.';
      return;
    }
    const request = ++locationRequest;
    locating = true;
    $('locate-delivery').disabled = true;
    $('delivery-location-status').textContent = 'جارٍ تحديد موقعك… اسمح بالوصول للموقع عند طلب المتصفح.';
    navigator.geolocation.getCurrentPosition(position => {
      if (request !== locationRequest || !isDelivery()) return;
      locating = false; $('locate-delivery').disabled = false;
      const { latitude, longitude, accuracy } = position.coords;
      $('delivery-latitude').value = latitude;
      $('delivery-longitude').value = longitude;
      $('delivery-accuracy').value = accuracy;
      const map = $('delivery-location-map');
      map.href = `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`;
      map.hidden = false;
      $('clear-delivery-location').hidden = false;
      const previousAddress = automaticAddress;
      automaticAddress = `موقعي الحالي: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
      if (!$('order-address').value.trim() || $('order-address').value === previousAddress) $('order-address').value = automaticAddress;
      $('delivery-location-status').textContent = `تم تحديد الموقع بدقة تقريبية ${Math.round(accuracy)} متر. راجع الخريطة وأضف تفاصيل المبنى والشقة${accuracy > 100 ? '؛ دقة الموقع منخفضة وقد تحتاج لتعديل العنوان' : ''}.`;
    }, error => {
      if (request !== locationRequest || !isDelivery()) return;
      locating = false; $('locate-delivery').disabled = false;
      $('delivery-location-status').textContent = ({1:'لم يُسمح بالوصول للموقع. يمكنك تعديل إذن المتصفح أو كتابة العنوان يدويًا.',2:'تعذر تحديد الموقع. حاول مجددًا أو اكتب العنوان يدويًا.',3:'انتهت مهلة تحديد الموقع. حاول مجددًا أو اكتب العنوان يدويًا.'})[error.code] || 'تعذر تحديد الموقع. اكتب العنوان يدويًا.';
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
  }
  function updateSummary() {
    const items = window.Shishli.getCart();
    $('checkout-empty').hidden = items.length > 0 || confirmed;
    $('checkout-content').hidden = items.length === 0 || confirmed;
    $('checkout-items').replaceChildren();
    items.forEach(item => {
      const row = document.createElement('div'); row.className = 'checkout-item';
      const label = document.createElement('span'); label.textContent = `${item.name} × ${item.quantity}`;
      const price = document.createElement('strong'); price.textContent = money(item.price * item.quantity);
      row.append(label, price); $('checkout-items').append(row);
    });
    $('checkout-total').textContent = money(items.reduce((sum, item) => sum + item.price * item.quantity, 0));
  }
  function updateDelivery(requestLocation = false) {
    const delivery = form.querySelector('[name="fulfillment"]:checked').value === 'delivery';
    $('address-field').hidden = !delivery;
    $('order-address').disabled = !delivery;
    $('order-address').required = delivery;
    $('pickup-location').hidden = delivery;
    $('delivery-note').textContent = delivery ? 'رسوم التوصيل وتغطية العنوان تُحدد عند الربط بالمطعم؛ الإجمالي المعروض للمنتجات فقط.' : 'الاستلام من المطعم — لا توجد رسوم توصيل.';
    ['delivery-latitude','delivery-longitude','delivery-accuracy'].forEach(id => $(id).disabled = !delivery);
    if (!delivery) clearLocation();
    else if (requestLocation && !$('delivery-latitude').value) locateDelivery();
  }
  form.querySelectorAll('[name="fulfillment"]').forEach(input => input.addEventListener('change', () => updateDelivery(true)));
  $('locate-delivery').addEventListener('click', locateDelivery);
  $('clear-delivery-location').addEventListener('click', clearLocation);
  $('order-phone').addEventListener('input', () => { $('order-phone-error').hidden = true; $('order-phone').removeAttribute('aria-invalid'); });
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!window.Shishli.getCart().length) { updateSummary(); return; }
    const phone = $('order-phone').value.replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[\s()-]/g, '');
    if (!/^(?:05\d{8}|(?:\+966|00966|966)5\d{8})$/.test(phone)) {
      $('order-phone-error').textContent = 'أدخل رقم جوال سعودي صحيحًا.';
      $('order-phone-error').hidden = false; $('order-phone').setAttribute('aria-invalid', 'true'); $('order-phone').focus(); return;
    }
    if ($('order-name').value.trim().length < 2) { $('order-name').focus(); return; }
    if ($('order-address').required && $('order-address').value.trim().length < 10) { $('order-address').focus(); return; }
    updateSummary();
    $('confirmation-total').textContent = `إجمالي المنتجات في المعاينة: ${$('checkout-total').textContent}`;
    confirmed = true;
    form.reset(); updateDelivery(); updateSummary();
    $('checkout-confirmation').hidden = false; $('checkout-confirmation').focus();
  });
  window.addEventListener('shishli:cart-updated', updateSummary);
  updateDelivery(); updateSummary();
})();
