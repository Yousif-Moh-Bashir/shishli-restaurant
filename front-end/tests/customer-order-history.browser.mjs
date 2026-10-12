import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

const browser = await browserClient(9375, resolve('.performance-check/customer-history'))
const origin = 'http://127.0.0.1:5176'
const guestId = '11111111-1111-4111-8111-111111111111', accountId = '22222222-2222-4222-8222-222222222222'
const userId = '33333333-3333-4333-8333-333333333333', branchId = '44444444-4444-4444-8444-444444444444'
const guestToken = 'g'.repeat(64)
const branch = { id: branchId, name: 'فرع الاختبار', is_active: true, location: {} }
const pricing = { subtotal: '25.00', discount_total: '0.00', delivery_fee: '0.00', tax_total: '0.00', total: '25.00', currency: 'SAR' }
const guest = { id: guestId, order_number: 'SH-GUEST', status: 'pending', type: 'pickup', placed_at: new Date().toISOString(), branch, items: [], pricing, customer: {}, payment: { status: 'unpaid', method: 'cash' }, timeline: {}, notes: null }
const account = { ...guest, id: accountId, order_number: 'SH-ACCOUNT' }
const tracking = { order: { uuid: guestId, number: guest.order_number, status: { code: 'pending' }, type: 'pickup', placed_at: guest.placed_at }, timeline: [], items: [], branch, summary: pricing, payment: {}, estimates: {}, progress: {}, last_updated_at: guest.placed_at }
const failures = [], guestReads = []
try {
  const run = String(Date.now())
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `if(localStorage.getItem('history-fixture-run')!==${JSON.stringify(run)}){localStorage.clear();sessionStorage.clear();localStorage.setItem('history-fixture-run',${JSON.stringify(run)})}` })
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    try {
      const path = new URL(request.url).pathname
      let data = {}, responseCode = 200
      if (request.method === 'OPTIONS') data = null
      else if (path === '/api/user') data = { uuid: userId, name: 'عميل الاختبار' }
      else if (path.endsWith('/admin/me')) responseCode = 403
      else if (path.endsWith('/branches')) data = [branch]
      else if (path.endsWith('/settings/public')) data = { restaurant: { name: 'شيشلي' }, ordering: {} }
      else if (path.endsWith('/checkout')) { responseCode = 201; data = { ...guest, access_token: guestToken, tracking: { expires_at: new Date(Date.now() + 86400000).toISOString() } } }
      else if (path.endsWith('/orders/' + guestId + '/guest')) { data = guest; guestReads.push(request.headers) }
      else if (path.endsWith('/orders/track')) data = tracking
      else if (path.endsWith('/orders')) data = [account]
      else if (path.endsWith('/orders/' + accountId)) data = account
      else if (path.endsWith('/orders/' + accountId + '/tracking')) data = { ...tracking, order: { ...tracking.order, uuid: accountId, number: account.order_number } }
      else if (path.endsWith('/payments') || path.endsWith('/categories')) data = []
      else if (path.endsWith('/business-hours')) data = { days: [] }
      else if (path.endsWith('/status')) data = { is_open: true, accepts_orders: true }
      else responseCode = 404
      await browser.send('Fetch.fulfillRequest', { requestId, responseCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Cart-Token, X-Order-Token' }, { name: 'Access-Control-Allow-Methods', value: 'GET, POST, OPTIONS' }], body: Buffer.from(JSON.stringify({ success: responseCode < 400, data, meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 }, links: {} })).toString('base64') })
    } catch (error) { failures.push(error.message) }
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*://*/api/*', requestStage: 'Request' }] })
  await browser.go(origin, '/orders')
  await browser.wait('document.querySelector(".saved-orders")')
  assert.ok(await browser.evaluate('document.querySelector(".saved-orders").textContent.includes("لا توجد طلبات")'))
  const submitted = await browser.evaluate(`(async()=>{const cart=document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('cart');cart.cart={id:${JSON.stringify(branchId)},branch:${JSON.stringify(branch)},items:[],summary:{}};cart.cartToken='c'.repeat(64);cart.initialized=true;const result=await cart.submitCheckout({type:'pickup',payment_method:'cash',order_timing:'asap'});return result.ok})()`)
  assert.equal(submitted, true)
  await browser.wait('document.querySelector(".saved-orders").textContent.includes("SH-GUEST")')
  await browser.evaluate(`document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/order-success/${guestId}')`)
  await browser.wait('document.querySelector(".success-order-number")')
  await browser.click('.main-nav a', 'طلباتي')
  await browser.wait('location.pathname==="/orders" && document.querySelector(".saved-orders-grid")')
  await browser.evaluate('sessionStorage.clear()')
  await browser.go(origin, '/orders')
  await browser.wait('document.querySelector(".saved-orders-grid")')
  assert.ok(await browser.evaluate('document.querySelector(".saved-orders-grid").textContent.includes("SH-GUEST")'), 'Reopened tab retains checkout history')
  await browser.click('.saved-order-actions a', 'تتبع الطلب')
  await browser.wait('document.querySelector(".tracking-number")')
  assert.equal(await browser.evaluate('document.querySelector(".tracking-number").textContent'), 'SH-GUEST')
  assert.equal(await browser.evaluate(`location.href.includes(${JSON.stringify(guestToken)}) || document.body.innerText.includes(${JSON.stringify(guestToken)})`), false, 'Credentials remain out of URLs and rendered text')
  assert.ok(guestReads.every(headers => Object.entries(headers).some(([name, value]) => name.toLowerCase() === 'x-order-token' && value === guestToken)))
  await browser.click('.auth-back', 'طلباتي')
  await browser.wait('location.pathname==="/orders"')
  for (const width of [390, 768, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 })
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true)
  }
  await browser.evaluate(`localStorage.setItem('shishli_auth_token','fixture-customer-token')`)
  await browser.go(origin, '/orders')
  await browser.wait('document.querySelector(".customer-orders")?.textContent.includes("SH-ACCOUNT")')
  assert.ok(await browser.evaluate('document.querySelector(".saved-orders").textContent.includes("SH-GUEST")'), 'Signing in does not hide previous guest orders')
  await browser.click('.customer-orders a', 'تفاصيل الطلب')
  await browser.wait('location.pathname.includes("/account/orders/") && document.querySelector(".order-details-layout")')
  assert.deepEqual(browser.diagnostics, [])
  assert.deepEqual(failures, [])
  console.log(JSON.stringify({ passed: true, checks: ['guest checkout saved automatically', 'header orders navigation', 'reopen after session storage clears', 'tracking without re-entering credentials', 'no token in URL or text', 'responsive history', 'authenticated orders plus previous guest history', 'account order details'], transport: 'API fixtures' }))
} finally { browser.close() }
