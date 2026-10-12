import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

const origin = 'http://127.0.0.1:5176'
const browser = await browserClient(9374, resolve('.performance-check/orders-sync'))
const userId = '11111111-1111-4111-8111-111111111111'
const order = (id, minute) => ({ id, order_number: id, placed_at: `2026-10-07T10:${minute}:00Z`, status: 'pending', type: 'pickup', branch: { name: 'الفرع' }, pricing: { total: '25.00', currency: 'SAR' }, available_actions: [] })
let rows = [order('old-order', '00')], recentFailure = false
const failures = []
const orderQueries = []
const requests = []
try {
  await browser.send('Network.enable')
  await browser.send('Network.setCacheDisabled', { cacheDisabled: true })
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();localStorage.setItem('shishli_auth_token','fixture-sync-token');window.played=0;window.AudioContext=class {state='running';currentTime=0;destination={};resume(){this.state='running';return Promise.resolve()}close(){return Promise.resolve()}createOscillator(){return {frequency:{value:0},connect(){},start(){played++},stop(){}}}createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}}}}` })
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    try {
      const url = new URL(request.url), path = url.pathname
      if (!path.startsWith('/api/')) { await browser.send('Fetch.continueRequest', {requestId}); return }
      requests.push({path,method:request.method})
      if(path.endsWith('/admin/orders'))orderQueries.push(Object.fromEntries(url.searchParams))
      const selectedRows=['history','range'].includes(url.searchParams.get('period'))?[order('archived-order','02')]:rows
      let data = {}, responseCode = 200
      if (path === '/api/user') data = { uuid: userId, name: 'Test manager' }
      else if (path.endsWith('/admin/me')) data = { id: userId, is_active: true, roles: ['manager'], permissions: ['orders.view', 'dashboard.view'] }
      else if (path.endsWith('/admin/dashboard')) data = { period: { from: '2026-10-07', to: '2026-10-07', timezone: 'UTC' }, summary: { orders_count: rows.length }, order_statuses: { pending: rows.length }, order_types: { pickup: rows.length } }
      else if (path.endsWith('/admin/bootstrap')) data = { restaurant: { currency: 'SAR', timezone: 'UTC' } }
      else if (path.endsWith('/orders/counts')) data = { pending: selectedRows.length }
      else if (path.endsWith('/admin/orders')) { data = selectedRows; if (recentFailure && url.searchParams.get('per_page') === '50') responseCode = 503 }
      else if (path.endsWith('/branches') || path.endsWith('/categories')) data = []
      else responseCode = 404
      await browser.send('Fetch.fulfillRequest', { requestId, responseCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type' }, { name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS' }], body: Buffer.from(JSON.stringify({ success: responseCode === 200, data, meta: { current_page: 1, last_page: 1, per_page: 15, total: rows.length }, links: {} })).toString('base64') })
    } catch (error) { failures.push(error.message) }
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*://*/api/*', requestStage: 'Request' }] })
  try { await browser.go(origin, '/admin') } catch(error) { throw Error(error.message+' '+JSON.stringify({requests,failures})) }
  await browser.wait('document.querySelector(".overview-orders").textContent.includes("old-order") && document.querySelector(".sidebar-orders-count")')
  assert.equal(await browser.evaluate('played'), 0, 'Initial order snapshot is silent')
  await browser.click('.sidebar-sound-toggle', 'تفعيل صوت الطلبات')
  await browser.wait('played===2')
  rows = [order('new-order', '01'), ...rows]
  await browser.wait('document.querySelector(".overview-orders").textContent.includes("new-order")')
  await browser.wait('document.querySelector(".sidebar-orders-count").textContent==="2" && played===4')
  recentFailure = true
  await browser.evaluate('document.dispatchEvent(new Event("visibilitychange"))')
  await browser.wait('document.querySelector(".sidebar-sync-error")')
  assert.equal(await browser.evaluate('played'), 4)
  recentFailure = false
  await browser.click('.sidebar-sync-error button', 'إعادة المحاولة')
  await browser.wait('!document.querySelector(".sidebar-sync-error")')
  assert.equal(await browser.evaluate('played'), 4, 'Recovery does not replay an already seen order')
  await browser.go(origin,'/admin/orders');await browser.wait('document.querySelector(".orders-board")?.innerText.includes("new-order")')
  assert.equal(orderQueries.some(query=>query.per_page==='15'&&query.period==='current'),true)
  await browser.click('.admin-order-period button','الطلبات السابقة');await browser.wait('document.querySelector(".orders-board")?.innerText.includes("archived-order")')
  assert.equal(await browser.evaluate('document.querySelector(".orders-board").innerText.includes("new-order")'),false)
  await browser.click('.admin-order-period button','فترة محددة');await browser.wait('document.querySelector(".order-period-dates")')
  await browser.input('.order-period-dates label:first-child input','2026-10-06');await browser.input('.order-period-dates label:last-child input','2026-10-06');await browser.click('.admin-filter-primary button','تطبيق الفلاتر')
  await browser.wait('document.querySelector(".orders-board")?.innerText.includes("archived-order")')
  assert.equal(orderQueries.some(query=>query.period==='range'&&query.date_from==='2026-10-06'&&query.date_to==='2026-10-06'),true)
  for(const width of [390,768,1440]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth'),true)}
  await browser.click('.admin-order-period button','يوم العمل الحالي');await browser.wait('document.querySelector(".orders-board")?.innerText.includes("new-order")')
  assert.deepEqual(browser.diagnostics, [])
  assert.deepEqual(failures, [])
  console.log(JSON.stringify({ passed: true, checks: ['automatic dashboard order refresh', 'sidebar counts and arrival sound', 'visible notification sync failure', 'retry recovery without duplicate sound','admin current workday, history, explicit range, responsive filters and return to current day'], transport: 'API and audio fixtures' }))
} finally { browser.close() }
