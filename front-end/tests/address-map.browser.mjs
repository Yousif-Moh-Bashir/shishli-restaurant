import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

const browser = await browserClient(9381, resolve('.performance-check/address-map'))
const origin = 'http://127.0.0.1:5176'
const id = n => `${String(n).padStart(8, '0')}-1111-4111-8111-111111111111`
const branch = { id: id(1), name: 'فرع المصيف', is_active: true, accepts_orders: true, supports_pickup: true, supports_delivery: true, location: { city: 'تبوك', latitude: '28.3835', longitude: '36.5662' } }
const status = { is_open: true, accepts_orders: true, pickup: { available: true }, delivery: { available: true } }
const cart = { id: id(2), branch, status: 'active', items: [{ id: id(3), product: { id: id(4), name: 'وجبة اختبار' }, quantity: 1, options: [], pricing: { unit_price: '80.00', line_total: '80.00', line_total_after_discount: '80.00' }, issues: [] }], summary: { items_count: 1, quantity_total: 1, subtotal: '80.00', discount_total: '0.00', total: '80.00' }, availability: status, can_checkout: true, issues: [] }
const settings = { restaurant: { name: 'شيشلي', timezone: 'Asia/Riyadh', currency: 'SAR' }, orders: { enabled: true, maintenance_mode: false, allow_guest_checkout: true, allow_scheduled_orders: true }, pickup: { enabled: true }, delivery: { enabled: true } }
const quote = outside => ({ available: !outside, delivery_fee: '5.00', estimated_total: '85.00', minimum_order_met: true, zone: outside ? null : { id: id(8), name: 'منطقة التوصيل' }, issue: outside ? { code: 'ADDRESS_OUTSIDE_DELIVERY_AREA', message: 'عذرًا، هذا الموقع خارج نطاق التوصيل الحالي.' } : null })
let outside = false, geocodeFailure = false, quoteFailure = false, validationFailure = false, addresses = [], checkoutBody, heldGeocode, holdGeocode = false
const requests = [], failures = []
const fulfill = (requestId, responseCode, data, raw = false, errors = {}) => browser.send('Fetch.fulfillRequest', { requestId, responseCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: '*' }, { name: 'Access-Control-Allow-Headers', value: 'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID' }, { name: 'Access-Control-Allow-Methods', value: 'GET,POST,PATCH,DELETE,OPTIONS' }], body: Buffer.from(JSON.stringify(raw ? data : { success: responseCode < 400, data, message: responseCode < 400 ? 'Success' : 'تعذر تنفيذ العملية', errors, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1 }, links: {} })).toString('base64') })
const reverseResult = { display_name: 'حي المصيف، تبوك', address: { city: 'تبوك', suburb: 'المصيف', road: 'شارع الاختبار', postcode: '47311', house_number: '999' } }
async function reset(auth = false, geo = 'success') {
  await browser.evaluate(`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});localStorage.setItem('shishli_cart_token','g'.repeat(64));${auth ? "localStorage.setItem('shishli_auth_token','fixture-token')" : ''}`)
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `window.__geoCalls=0;Object.defineProperty(navigator,'geolocation',{configurable:true,value:{getCurrentPosition(ok,fail){window.__geoCalls++;${geo === 'success' ? 'setTimeout(()=>ok({coords:{latitude:28.41,longitude:36.59,accuracy:30}}),100)' : geo === 'denied' ? 'setTimeout(()=>fail({code:1}),100)' : 'throw Error("unavailable")'}}}})` })
  await browser.go(origin, '/checkout')
  await browser.wait('document.querySelector("[value=delivery]")')
}
async function delivery() { await browser.evaluate('document.querySelector("[value=delivery]").click()'); await browser.wait('document.querySelector(".checkout-address-section")') }
async function contact() { await browser.input('[name=customer_name]', 'عميل اختبار'); await browser.input('[name=customer_phone]', '0551234567') }
async function confirm() { await browser.click('.location-confirm', 'تأكيد هذا الموقع'); await browser.wait('document.querySelector(".delivery-quote")') }
try {
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    try {
      const path = new URL(request.url).pathname, body = request.postData ? JSON.parse(request.postData) : {}, method = request.method
      if (request.url.includes('tile.openstreetmap.org')) { await browser.send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'image/png' }], body: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jG1kAAAAASUVORK5CYII=' }); return }
      if (request.url.includes('nominatim.openstreetmap.org')) {
        if (holdGeocode) { heldGeocode = requestId; return }
        await fulfill(requestId, 200, geocodeFailure ? { error: 'Unavailable' } : reverseResult, true); return
      }
      if (method === 'OPTIONS') { await fulfill(requestId, 200, null); return }
      requests.push({ path, method, body })
      let data = [], code = 200, errors = {}
      if (path === '/api/user') data = { uuid: id(20), name: 'عميل اختبار', phone: '0551234567', is_active: true }
      else if (path.endsWith('/admin/me')) code = 403
      else if (path.endsWith('/settings/public')) data = settings
      else if (path.endsWith('/branches')) data = [branch]
      else if (path.endsWith('/branches/' + branch.id)) data = branch
      else if (path.endsWith('/status')) data = status
      else if (path.endsWith('/cart')) data = cart
      else if (path.endsWith('/cart/delivery/quote')) {
        if (quoteFailure) { await browser.send('Fetch.failRequest', { requestId, errorReason: 'ConnectionFailed' }); return }
        if (validationFailure) { code = 422; errors = { 'address.district': ['راجع اسم الحي.'] } }
        data = quote(outside)
      } else if (path.includes('/addresses')) {
        const match = path.match(/\/addresses\/([^/]+)(\/default)?$/), item = match ? addresses.find(item => item.id === match[1]) : null
        if (method === 'POST') { data = { ...body, id: id(30 + addresses.length), location: { latitude: body.latitude, longitude: body.longitude }, is_default: !addresses.length || body.is_default }; if (data.is_default) addresses.forEach(item => item.is_default = false); addresses.push(data); code = 201 }
        else if (match && !item) code = 404
        else if (method === 'DELETE') { addresses = addresses.filter(address => address !== item); data = null }
        else if (method === 'PATCH') { if (match[2]) { addresses.forEach(address => address.is_default = false); item.is_default = true } else Object.assign(item, body, { location: { latitude: body.latitude, longitude: body.longitude } }); data = item }
        else data = item || addresses
      } else if (path.endsWith('/checkout')) {
        checkoutBody = body; code = 201
        data = { id: id(50), order_number: 'SH-MAP', type: body.type, status: 'pending', customer: body.customer, address: body.address, items: cart.items, branch, placed_at: new Date().toISOString(), pricing: { subtotal: '80.00', total: '85.00', delivery_fee: '5.00' }, payment: { method: 'cash', status: 'unpaid' }, access_token: 'o'.repeat(64) }
      } else if (path.endsWith('/orders/' + id(50) + '/guest')) data = { id: id(50), order_number: 'SH-MAP', type: 'delivery', status: 'pending', customer: checkoutBody.customer, address: checkoutBody.address, items: cart.items, branch, pricing: { total: '85.00' }, payment: {} }
      await fulfill(requestId, code, data, false, errors)
    } catch (error) { failures.push(error.message) }
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*://*/api/v1/*', requestStage: 'Request' }, { urlPattern: '*://*/api/user', requestStage: 'Request' }, { urlPattern: '*://nominatim.openstreetmap.org/*', requestStage: 'Request' }, { urlPattern: '*://tile.openstreetmap.org/*', requestStage: 'Request' }] })
  await browser.go(origin, '/orders')
  await reset(); await delivery(); await contact()
  assert.equal(await browser.evaluate('window.__geoCalls'), 0)
  assert.equal(await browser.evaluate('Boolean(document.querySelector(".leaflet-container"))'), false)
  await browser.click('.location-actions button', 'تحديد موقعي الحالي')
  await browser.wait('document.querySelector(".leaflet-marker-icon") && document.querySelector(".selected-address")')
  assert.equal(await browser.evaluate('document.querySelector("[name=city]").value'), 'تبوك')
  assert.equal(await browser.evaluate('document.querySelector("[name=district]").value'), 'المصيف')
  assert.equal(await browser.evaluate('document.querySelector("[name=building_number]").value'), '')
  assert.equal(await browser.evaluate('Boolean(document.querySelector("[name=latitude],[name=longitude]"))'), false)
  await confirm()
  assert.ok(await browser.evaluate('document.querySelector(".checkout-summary").textContent.includes("85")'))
  assert.equal(typeof requests.find(request => request.path.endsWith('/cart/delivery/quote')).body.address.latitude, 'number')
  await browser.evaluate(`(()=>{const map=document.querySelector('.leaflet-container'),rect=map.getBoundingClientRect();map.dispatchEvent(new MouseEvent('click',{bubbles:true,clientX:rect.left+rect.width*.65,clientY:rect.top+rect.height*.55}))})()`)
  assert.equal(await browser.evaluate('Boolean(document.querySelector(".delivery-quote"))'), false, 'Map clicks invalidate the old quote')
  await browser.wait('document.querySelector("[name=city]").value && ![...document.querySelectorAll(".checkout-location>p")].some(p=>p.textContent.includes("جارٍ جلب"))')
  await confirm()
  assert.notEqual(requests.filter(request => request.path.endsWith('/cart/delivery/quote')).at(-1).body.address.longitude, 36.59)
  await browser.input('[name=district]', 'حي آخر')
  assert.equal(await browser.evaluate('Boolean(document.querySelector(".delivery-quote"))'), false)
  outside = true; await confirm()
  assert.ok(await browser.evaluate('document.querySelector(".delivery-quote").textContent.includes("خارج منطقة توصيل")'))
  assert.equal(await browser.evaluate('document.querySelector(".auth-submit").disabled'), true)
  outside = false; quoteFailure = true
  await browser.click('.location-confirm', 'تأكيد هذا الموقع')
  await browser.wait('!document.querySelector(".delivery-quote") && document.querySelector(".location-confirm")?.disabled===false')
  quoteFailure = false; validationFailure = true
  await browser.click('.location-confirm', 'تأكيد هذا الموقع')
  await browser.wait('document.querySelector("[name=district]").getAttribute("aria-invalid")==="true"')
  validationFailure = false
  for (const width of [320, 360, 375, 390, 412, 430, 1280]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 500 })
    assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), 'Overflow at ' + width)
  }
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false })
  await confirm()
  await browser.click('.auth-submit', 'تأكيد الطلب')
  await browser.wait('location.pathname.includes("order-success")')
  assert.equal(typeof checkoutBody.address.longitude, 'number')
  assert.equal(checkoutBody.address.recipient_name, checkoutBody.customer.name)
  assert.equal('delivery_fee' in checkoutBody, false)
  assert.equal('postal_code' in checkoutBody.address, false)
  await reset(false, 'denied'); await delivery(); await contact()
  await browser.click('.location-actions button', 'تحديد موقعي الحالي')
  await browser.wait('document.querySelector(".location-status").textContent.includes("لم نتمكن")')
  await browser.click('.location-actions button', 'اختيار الموقع على الخريطة')
  await browser.wait('document.querySelector(".leaflet-container")')
  geocodeFailure = true
  await browser.click('.location-map-picker button', 'اختيار منتصف الخريطة')
  await browser.wait('document.querySelector(".checkout-location").textContent.includes("تعذر جلب وصف")')
  await browser.input('[name=city]', 'تبوك'); await browser.input('[name=district]', 'المصيف'); await confirm()
  geocodeFailure = false
  await browser.click('.location-actions button', 'كتابة العنوان يدويًا')
  assert.equal(await browser.evaluate('Boolean(document.querySelector(".leaflet-container"))'), false)
  await confirm()
  assert.equal(requests.filter(request => request.path.endsWith('/cart/delivery/quote')).at(-1).body.address.latitude, null)
  await browser.evaluate('document.querySelector("[value=pickup]").click()')
  assert.equal(await browser.evaluate('Boolean(document.querySelector(".checkout-address-section"))'), false)
  await reset(false, 'unavailable'); await delivery()
  await browser.click('.location-actions button', 'تحديد موقعي الحالي')
  await browser.wait('document.querySelector("[name=city]")')
  await reset(true); await delivery()
  await browser.wait('document.querySelector(".address-book")')
  await browser.click('.address-book button', 'إضافة عنوان')
  await browser.wait('document.querySelector(".address-editor")')
  await browser.input('[name=recipient_name]', 'مستلم اختبار'); await browser.input('[name=phone]', '0551234567')
  await browser.click('.address-editor .location-actions button', 'اختيار الموقع على الخريطة')
  await browser.wait('document.querySelector(".leaflet-container")')
  await browser.click('.location-map-picker button', 'اختيار منتصف الخريطة')
  await browser.wait('document.querySelector(".selected-address")')
  await browser.click('.address-editor [type=submit]', 'حفظ العنوان')
  await browser.wait('document.querySelector(".address-card") && !document.querySelector(".address-editor")')
  await browser.wait('document.querySelector(".delivery-quote")')
  assert.ok(requests.filter(request => request.path.endsWith('/cart/delivery/quote')).at(-1).body.address_uuid)
  await browser.click('.address-card button', 'تعديل')
  await browser.wait('document.querySelector(".address-editor [name=city]")')
  await browser.input('.address-editor [name=street]', 'شارع جديد')
  await browser.click('.address-editor [type=submit]', 'حفظ العنوان')
  await browser.wait('!document.querySelector(".address-editor")')
  assert.equal(addresses[0].street, 'شارع جديد')
  await browser.click('.address-book button', 'إضافة عنوان')
  await browser.wait('document.querySelector(".address-editor")')
  await browser.input('[name=recipient_name]', 'مستلم ثان'); await browser.input('[name=phone]', '0551234567')
  await browser.click('.address-editor .location-actions button', 'كتابة العنوان يدويًا')
  await browser.input('.address-editor [name=city]', 'تبوك'); await browser.input('.address-editor [name=district]', 'المصيف')
  await browser.click('.address-editor [type=submit]', 'حفظ العنوان'); await browser.wait('document.querySelectorAll(".address-card").length===2')
  await browser.wait('!document.querySelector(".checkout-fields").disabled && !document.querySelector(".address-card button").disabled')
  await browser.click('.address-card button', 'تعيين افتراضي')
  await browser.wait('!document.querySelector(".checkout-fields").disabled && !document.querySelector(".address-card button").disabled && document.querySelectorAll(".address-card .badge").length===1')
  await browser.click('.address-card button', 'حذف'); await browser.click('dialog button', 'حذف')
  await browser.wait('document.querySelectorAll(".address-card").length===1')
  assert.equal(failures.length, 0, failures.join('\n'))
  const unexpected = browser.diagnostics.filter(message => !message.includes('Failed to load resource') && !message.includes('ERR_CONNECTION_FAILED'))
  assert.deepEqual(unexpected, [])
  console.log('Address map browser checks passed: guest GPS/map/manual, denial/unavailable, geocoding fallback, quote/fee/outside/network/422, checkout, saved address CRUD/default, pickup and seven viewport sizes')
} finally { browser.close() }
