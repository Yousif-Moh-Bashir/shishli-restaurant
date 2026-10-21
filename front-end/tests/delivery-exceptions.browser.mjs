import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { resolve, extname } from 'node:path'
import { browserClient } from './browser-client.mjs'

// Real built Vue UI and Chrome; all API calls terminate at this fixture server.
const root = resolve('dist'), id = 'f1b0ea68-ec62-4dbe-818e-2567881cf3bd', target = '1e799076-7e60-4c86-ac37-419ff478a621'
let role = 'driver', stage = 'on_the_way', status = 'out_for_delivery', method = 'network', payment = 'pending', revision = 0, requested = false, assigned = 'مندوب اختبار'
const calls = [], errors = []
const permissions = ['orders.view', 'drivers.view', 'drivers.reassign', 'payments.view', 'payments.collect_cash', 'delivery_exceptions.view', 'delivery_exceptions.fail', 'delivery_exceptions.return', 'delivery_exceptions.release', 'delivery_exceptions.cancel', 'delivery_exceptions.resolve_custody']
const pagination = { meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 }, links: { first: null, last: null, prev: null, next: null } }
const closed = () => ['returned', 'released'].includes(stage)
function driverOrder() {
  return { id, order_number: 'SH-0002', preparation_status: status, delivery_status: stage,
    branch: { id: target, name: 'فرع الاختبار' }, delivery_revision: revision, estimated_ready_at: null,
    payment_method: method, payment_status: payment, total: '57.00', delivery_fee: '5.00', currency: 'SAR', cash_due: method === 'cash' && payment === 'pending' ? '57.00' : '0.00',
    available_actions: stage === 'on_the_way' ? payment === 'paid' ? ['delivered', 'failed'] : ['failed'] : [],
    delivery_block: method === 'network' && payment === 'pending' ? { code: 'NETWORK_CONFIRMATION_REQUIRED', responsible: 'cashier', message: 'دفع الشبكة يحتاج تأكيد الكاشير أو الموظف المخوّل بعد التحقق الفعلي.' } : null,
    can_request_intervention: !requested && !closed(), intervention_requested_at: requested ? '2026-10-21T10:00:00Z' : null,
    task_closed_at: closed() ? '2026-10-21T10:00:00Z' : null, customer: { name: 'عميل اختبار', phone: null }, address: null, history: [] }
}
function adminOrder() {
  return { id, order_number: 'SH-0002', type: 'delivery', status, placed_at: '2026-10-20T10:00:00Z',
    branch: { id: target, name: 'فرع الاختبار' }, payment: { method, status: payment }, pricing: { total: '57.00', currency: 'SAR' },
    delivery: { status: stage, driver: { id: target, name: assigned }, history: [] }, cash_collections: [],
    available_actions: ['cash', 'network'].includes(method) && payment === 'pending' ? ['collect_cash'] : [],
    delivery_exception: { revision, requested_at: requested ? '2026-10-21T10:00:00Z' : null, reason: 'طلب تدخل اختبار', block: driverOrder().delivery_block,
      task_closed_at: closed() ? '2026-10-21T10:00:00Z' : null, financial_review_required: closed() && payment === 'paid',
      actions: status === 'cancelled' ? [] : ({ on_the_way: ['fail', 'start_return', 'reassign'], failed: ['start_return', 'resolve_custody'], returning: ['confirm_return'], returned: ['cancel'], released: ['cancel'] }[stage] || []) } }
}
const server = createServer(async (req, res) => {
  try {
    const path = new URL(req.url, 'http://localhost').pathname
    if (path.startsWith('/api/')) {
      let raw = ''; for await (const part of req) raw += part
      const body = raw ? JSON.parse(raw) : {}, respond = (data, code = 200, extra = {}) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ success: code < 400, message: code < 400 ? 'Success' : 'غير مخوّل', data, ...extra })) }
      if (req.method === 'POST') calls.push({ path, body })
      if (path === '/api/user') return respond({ uuid: target, is_driver: role === 'driver', name: 'حساب اختبار' })
      if (path.endsWith('/admin/me')) return respond(role === 'admin' ? { id: target, is_active: true, roles: ['super_admin'], permissions, name: 'إدارة الاختبار' } : null, role === 'admin' ? 200 : 403)
      if (path.endsWith('/driver/me')) return respond({ id: target, name: 'مندوب اختبار', enabled: true, available: true, account_active: true, branch: { id: target, name: 'فرع الاختبار' } })
      if (path.endsWith('/settings/public')) return respond({ restaurant: { name: 'شيشلي', currency: 'SAR', timezone: 'Asia/Riyadh' } })
      if (path.endsWith('/intervention')) { requested = true; revision++; return respond(driverOrder()) }
      if (path.endsWith('/cash/collect')) { assert.ok(method !== 'network' || body.network_verified === true); payment = 'paid'; return respond({ status: payment, method, amount: '57.00' }) }
      if (path.endsWith('/delivery-exception')) {
        assert.equal(body.expected_revision, revision); assert.match(body.operation_key, /^[a-f0-9-]{36}$/)
        if (body.action === 'reassign') { assert.equal(body.driver_uuid, target); assigned = 'المندوب المستلم'; requested = false }
        else if (body.action === 'cancel') status = 'cancelled'
        else stage = { fail: 'failed', start_return: 'returning', confirm_return: 'returned', resolve_custody: 'released' }[body.action]
        revision++; return respond(adminOrder())
      }
      if (path.endsWith('/delivery-exceptions')) return respond(status === 'cancelled' ? [] : [adminOrder()], 200, pagination)
      if (path === '/api/v1/admin/drivers') return respond([{ id: target, name: 'المندوب المستلم', current_order: null }], 200, pagination)
      if (path === '/api/v1/driver/orders/' + id) return respond(driverOrder())
      if (path === '/api/v1/admin/orders/' + id) return respond(adminOrder())
      if (path.endsWith('/driver/orders')) return respond([], 200, { ...pagination, driver_context: { can_accept: false, blocking_reason: 'لديك طلب نشط', active_order: { id } } })
      if (path.endsWith('/orders/counts')) return respond({ pending: 0 })
      return respond([], 200, pagination)
    }
    const file = resolve(root, '.' + decodeURIComponent(path === '/' ? '/index.html' : path))
    if (!file.startsWith(root + '/') && !file.startsWith(root + '\\')) { res.writeHead(403); return res.end() }
    let content, served = file
    try { content = await readFile(file) } catch { served = resolve(root, 'index.html'); content = await readFile(served) }
    const mime = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.html': 'text/html' }
    res.writeHead(200, { 'Content-Type': mime[extname(served)] || 'application/octet-stream' }); res.end(content)
  } catch (error) { errors.push(error.message); res.writeHead(500); res.end('{}') }
})
await new Promise(done => server.listen(0, '127.0.0.1', done))
const origin = 'http://127.0.0.1:' + server.address().port
let browser
try {
  browser = await browserClient(9397, resolve('node_modules/.cache/delivery-exceptions-browser'))
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    const url = new URL(request.url)
    if (url.hostname === 'fonts.googleapis.com') {
      await browser.send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/css' }], body: '' })
    } else if (url.origin !== origin) {
      errors.push('Blocked non-fixture request: ' + url.origin);
      await browser.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' })
    } else await browser.send('Fetch.continueRequest', { requestId })
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: 'http*' }] })
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: "localStorage.setItem('shishli_auth_token','fixture-only');localStorage.setItem('shishli.driverOrderSoundMuted','1');localStorage.setItem('shishli.orderSoundMuted','1')" })
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await browser.go(origin, '/driver/orders/' + id)
  await browser.wait('document.body.innerText.includes("دفع الشبكة يحتاج تأكيد")')
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true)
  assert.equal(await browser.evaluate('[...document.querySelectorAll("button")].some(e=>e.disabled&&e.textContent.includes("تأكيد التسليم غير متاح"))'), true)
  assert.equal(await browser.evaluate('document.body.innerText.includes("تسجيل التحصيل النقدي")'), false)
  await browser.click('button', 'طلب تدخل الإدارة'); await browser.input('textarea', 'لم يؤكد الكاشير دفع الشبكة')
  await browser.click('button', 'إرسال طلب التدخل'); await browser.wait('document.body.innerText.includes("تم تسجيل طلب تدخل الإدارة")')
  assert.equal(calls.filter(c => c.path.endsWith('/intervention')).length, 1)

  role = 'admin'; await browser.go(origin, '/admin/delivery-exceptions')
  await browser.wait('document.querySelector(".cash-collect")')
  await browser.click('.cash-collect', 'تأكيد دفع الشبكة')
  await browser.wait('document.querySelector("dialog[open] input[type=checkbox]")')
  await browser.click('dialog button[type=submit]', 'تسجيل التحصيل')
  assert.equal(calls.filter(c => c.path.endsWith('/cash/collect')).length, 0, 'Unchecked network confirmation cannot submit')
  await browser.evaluate('document.querySelector("dialog[open] input[type=checkbox]").click()')
  await browser.click('dialog button[type=submit]', 'تسجيل التحصيل')
  await browser.wait('!document.querySelector("dialog[open]") && !document.querySelector(".cash-collect")')
  assert.equal(payment, 'paid')
  async function operation(action, label) {
    await browser.input('.delivery-exception-actions select', action)
    await browser.input('.delivery-exception-actions textarea', 'تم التحقق من الحالة الفعلية للاختبار')
    if (action === 'reassign') { await browser.wait('document.querySelectorAll(".delivery-exception-actions select")[1]?.options.length>1'); await browser.input('.delivery-exception-actions select:nth-of-type(1)', action); await browser.evaluate(`(()=>{const e=document.querySelectorAll('.delivery-exception-actions select')[1];e.value=${JSON.stringify(target)};e.dispatchEvent(new Event('change',{bubbles:true}))})()`) }
    await browser.evaluate('document.querySelector(".delivery-exception-actions input[type=checkbox]").click()')
    await browser.click('.delivery-exception-actions button', label)
    await browser.wait('document.querySelector(".admin-confirm-dialog[open],dialog[open]")')
    await browser.click('dialog[open] button', 'تأكيد')
    await browser.wait('!document.querySelector("dialog[open]") && !document.querySelector(".delivery-exception-actions textarea")')
  }
  await operation('start_return', 'بدء إرجاع'); assert.equal(stage, 'returning')
  await operation('confirm_return', 'تأكيد استلام المطعم'); assert.equal(stage, 'returned')
  assert.equal(await browser.evaluate('document.body.innerText.includes("انتهت عهدة المهمة")'), true)
  await operation('cancel', 'إلغاء استثنائي'); assert.equal(status, 'cancelled')
  assert.equal(payment, 'paid', 'Returning/cancelling does not invent a refund')
  stage = 'on_the_way'; status = 'out_for_delivery'; method = 'cash'; payment = 'pending'; requested = false
  await browser.go(origin, '/admin/delivery-exceptions'); await browser.wait('document.querySelector(".cash-collect")')
  await browser.click('.cash-collect', 'تسجيل التحصيل'); await browser.wait('document.querySelector("dialog[open]")')
  await browser.click('dialog[open] button[type=submit]', 'تسجيل التحصيل'); await browser.wait('!document.querySelector(".cash-collect")')
  assert.equal(payment, 'paid')
  await operation('reassign', 'نقل المهمة'); assert.equal(assigned, 'المندوب المستلم')
  method = 'card'; payment = 'pending'; await browser.go(origin, '/admin/delivery-exceptions')
  await browser.wait('document.querySelector(".delivery-exception-actions")')
  assert.equal(await browser.evaluate('Boolean(document.querySelector(".cash-collect"))'), false)
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false })
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true)
  assert.deepEqual(errors, [])
  assert.deepEqual(browser.diagnostics, [])
  console.log('Chrome delivery exceptions passed: mobile/desktop, blocked network delivery, intervention, verified network/cash collection, return, cancellation, reassignment and card restrictions. Local API fixtures only.')
} finally { browser?.close(); await new Promise(done => server.close(done)) }
