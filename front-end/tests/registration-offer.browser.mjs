import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

const origin = process.env.SITE_ORIGIN || 'http://127.0.0.1:5178'
const browser = await browserClient(9258, resolve('.performance-check/registration-offer'))
const id = number => `${String(number).padStart(8, '0')}-1111-4111-8111-111111111111`
const branch = { id: id(1), name: 'فرع الاختبار', is_active: true, accepts_orders: true, supports_pickup: true, supports_delivery: true, location: { city: 'تبوك' } }
const campaign = { id: id(5), name: 'عرض الترحيب', description: 'أنشئ حسابك واستفد من خصم أول طلب.', type: 'automatic', application_type: 'cart', is_registration_offer: true, first_order_only: true, discount: { type: 'percentage', value: '15.00', maximum: '20.00' }, minimum_subtotal: '40.00', starts_at: null, ends_at: null }
let mode = 'active', loggedIn = false, registered = false, loginFailed = false
let registeredPhone
const envelope = data => ({ success: true, message: 'Success', data, errors: null })
const page = data => ({ ...envelope(data), meta: { current_page: 1, last_page: 1, total: data.length }, links: { prev: null, next: null } })
const cart = () => ({ id: id(2), status: 'active', branch, items: [{ id: id(3), product: { id: id(4), name: 'طبق الاختبار', primary_image: null }, quantity: 1, options: [], pricing: { base_price: '100.00', options_total: '0.00', unit_price: '100.00', line_total: '100.00', line_total_after_discount: loggedIn ? '85.00' : '100.00', discount_total: loggedIn ? '15.00' : '0.00' }, is_available: true, issues: [] }], summary: { items_count: 1, quantity_total: 1, subtotal: '100.00', discount_total: loggedIn ? '15.00' : '0.00', total: loggedIn ? '85.00' : '100.00' }, promotions: [], can_checkout: true, issues: [], availability: { accepts_orders: true, pickup: { available: true }, delivery: { available: true } } })
const fulfill = (requestId, responseCode, data) => browser.send('Fetch.fulfillRequest', { requestId, responseCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: '*' }, { name: 'Access-Control-Allow-Headers', value: '*' }], body: Buffer.from(JSON.stringify(data)).toString('base64') })
const stop = browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
  try {
    const url = new URL(request.url), path = url.pathname
    if (request.method === 'OPTIONS') return await fulfill(requestId, 200, {})
    if (path.endsWith('/promotions')) return await fulfill(requestId, mode === 'error' ? 500 : 200, page(mode === 'empty' ? [] : [{ ...campaign, ends_at: mode === 'expired' ? '2000-01-01T00:00:00Z' : null }]))
    if (path.endsWith('/branches')) return await fulfill(requestId, 200, envelope([branch]))
    if (path.endsWith('/settings')) return await fulfill(requestId, 200, envelope({ restaurant: { name: 'شيشلي' } }))
    if (path.endsWith('/menu')) return await fulfill(requestId, 200, envelope({ categories: [] }))
    if (path.endsWith('/register')) { registered = true; registeredPhone = JSON.parse(request.postData).phone; return await fulfill(requestId, 201, envelope({ uuid: id(6) })) }
    if (path.endsWith('/login')) {
      if (loginFailed) return await fulfill(requestId, 503, { success: false, message: 'Login temporarily unavailable', data: null, errors: null })
      loggedIn = true; return await fulfill(requestId, 200, envelope({ token_type: 'Bearer', token: 'browser-fixture-token' }))
    }
    if (path === '/api/user') return await fulfill(requestId, 200, envelope({ uuid: id(6), name: 'عميل الاختبار', is_driver: false }))
    if (path.endsWith('/admin/me')) return await fulfill(requestId, 403, { success: false, message: 'Forbidden', data: null, errors: null })
    if (path.includes('/cart')) return await fulfill(requestId, 200, envelope(cart()))
    if (path.endsWith('/addresses')) return await fulfill(requestId, 200, envelope([]))
    if (path.endsWith('/profile')) return await fulfill(requestId, 200, envelope({ name: 'عميل الاختبار', email: 'qa@example.test', phone: null }))
    return await fulfill(requestId, 200, envelope([]))
  } catch (error) { if (error.message !== 'Invalid InterceptionId.') browser.diagnostics.push(error.message) }
})
try {
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*/api/v1/*' }, { urlPattern: '*/api/user' }] })
  const resetKey = 'registration-fixture-reset-' + Date.now()
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `if(!sessionStorage.getItem(${JSON.stringify(resetKey)})){localStorage.removeItem('shishli_auth_token');localStorage.removeItem(${JSON.stringify('shishli.registrationPopup.'+campaign.id)});sessionStorage.setItem(${JSON.stringify(resetKey)},'done')}localStorage.setItem('shishli.selectedBranch', ${JSON.stringify(branch.id)});` })
  await browser.go(origin, '/cart')
  await browser.wait('document.querySelector(".registration-offer--bar") && document.querySelector("main .registration-offer--cart")')
  await browser.sleep(1000)
  assert.equal(await browser.evaluate('!!document.querySelector(".welcome-popup[open]")'), false)
  await browser.wait('document.querySelector(".welcome-popup[open]")')
  assert.match(await browser.evaluate('document.querySelector(".welcome-popup").textContent'), /١٥٪/)
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 320, height: 700, deviceScaleFactor: 1, mobile: true })
  assert.ok(await browser.evaluate('document.querySelector(".welcome-popup").getBoundingClientRect().width<=innerWidth-20'))
  await browser.click('.welcome-popup-later', 'لاحقًا')
  await browser.wait('!document.querySelector(".welcome-popup[open]")')
  assert.equal(await browser.evaluate('document.body.style.overflow'), '')
  const dismissalUntil = await browser.evaluate(`Number(localStorage.getItem(${JSON.stringify('shishli.registrationPopup.'+campaign.id)}))`)
  assert.ok(dismissalUntil > Date.now() + 6.99 * 86400000 && dismissalUntil <= Date.now() + 7 * 86400000)
  await browser.go(origin, '/cart')
  await browser.sleep(8300)
  assert.equal(await browser.evaluate('!!document.querySelector(".welcome-popup[open]")'), false)
  assert.match(await browser.evaluate('document.querySelector(".registration-offer--bar").textContent'), /١٥٪/)
  assert.equal(await browser.evaluate('document.querySelectorAll(".registration-offer details").length'), 0)
  for (const width of [320, 360, 390, 430, 768, 1024, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 })
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true, `Overflow at ${width}`)
    assert.ok(await browser.evaluate('document.querySelector(".registration-offer--bar").getBoundingClientRect().height<=44'), `Announcement too tall at ${width}`)
  }
  await browser.click('main .registration-offer-action', 'سجّل الآن')
  await browser.wait('location.pathname==="/register" && document.querySelector(".auth-form-panel .registration-offer")')
  assert.equal(await browser.evaluate('new URLSearchParams(location.search).get("redirect")'), '/checkout')
  assert.equal(await browser.evaluate('document.querySelector(".registration-offer--bar").getBoundingClientRect().height'), 0)
  await browser.click('.auth-back', 'العودة للرئيسية')
  await browser.wait('location.pathname==="/" && document.querySelector(".registration-offer--bar").getBoundingClientRect().height>0')
  assert.match(await browser.evaluate('document.querySelector(".registration-offer--bar").textContent'), /١٥٪/)
  await browser.evaluate('window.scrollTo({top:600,behavior:"instant"})')
  await browser.click('header nav a', 'الرئيسية')
  await browser.wait('location.hash==="#home" && window.scrollY===0')
  assert.ok(await browser.evaluate('document.querySelector(".registration-offer--bar").getBoundingClientRect().top>=0'))
  await browser.click('.registration-offer--bar .registration-offer-action', 'سجّل الآن')
  await browser.wait('location.pathname==="/register" && document.querySelector("#register-form")')
  await browser.go(origin, '/register?redirect=/checkout')
  await browser.wait('document.querySelector("#register-form button[type=submit]") && !document.querySelector("#register-form button[type=submit]").disabled')
  assert.equal(await browser.evaluate('document.querySelector("#login-phone").placeholder'), '05xxxxxxxx')
  await browser.input('#login-phone', '+966551234567')
  assert.equal(await browser.evaluate('document.querySelector("#login-phone").value'), '0551234567')
  await browser.input('#login-phone', '٠٥٥١٢٣٤٥٦٧')
  assert.equal(await browser.evaluate('document.querySelector("#login-phone").value'), '0551234567')
  for (const [selector, value] of [['#register-name', 'QA customer'], ['#register-email', 'qa@example.test'], ['#login-password', 'FixturePass123!'], ['#confirm-password', 'FixturePass123!']]) await browser.input(selector, value)
  await browser.click('#register-form button[type="submit"]', 'إنشاء حساب')
  await browser.wait('location.pathname==="/checkout"')
  assert.equal(registered, true)
  assert.equal(registeredPhone, '+966551234567')
  assert.equal(await browser.evaluate('document.querySelectorAll(".registration-offer").length'), 0)
  await browser.go(origin, '/cart')
  await browser.wait('document.querySelector("main .cart-discount")')
  assert.equal(await browser.evaluate('document.querySelectorAll(".registration-offer").length'), 0)
  await browser.evaluate('localStorage.removeItem("shishli_auth_token")')
  loggedIn = false
  loginFailed = true
  await browser.go(origin, '/register?redirect=/checkout')
  await browser.wait('document.querySelector("#register-form button[type=submit]") && !document.querySelector("#register-form button[type=submit]").disabled')
  for (const [selector, value] of [['#register-name', 'QA customer'], ['#register-email', 'qa@example.test'], ['#login-password', 'FixturePass123!'], ['#confirm-password', 'FixturePass123!']]) await browser.input(selector, value)
  await browser.click('#register-form button[type="submit"]', 'إنشاء حساب')
  await browser.wait('document.querySelector("#auth-status a")')
  assert.match(await browser.evaluate('document.querySelector("#auth-status").textContent'), /تم إنشاء حسابك/)
  assert.equal(await browser.evaluate('location.pathname'), '/register')
  loginFailed = false
  for (const state of ['empty', 'error', 'expired']) {
    mode = state
    await browser.go(origin, '/register')
    await browser.wait('document.querySelector("#register-form")')
    await browser.sleep(500)
    assert.equal(await browser.evaluate('document.querySelectorAll(".registration-offer").length'), 0)
  }
  assert.equal(browser.diagnostics.length, 0, JSON.stringify(browser.diagnostics))
  console.log('PASS: welcome popup, persistent dismissal, mobile dialog, 7 widths, signup with automatic login, authenticated discount, login failure fallback, empty/error/expired states (HTTP fixtures).')
} finally { stop(); browser.close() }
