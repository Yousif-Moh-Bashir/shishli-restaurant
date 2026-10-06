// Read-only real API/browser checks. Does not create, edit or delete business data.
import { spawn } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'
const output = fileURLToPath(new URL('../.reports-check/design/', import.meta.url))
await mkdir(output, { recursive: true })
const api = process.env.DESIGN_API_URL || 'http://127.0.0.1:8000/api/v1'
const origin = process.env.DESIGN_APP_URL || 'http://127.0.0.1:5173'
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
const report = { pages: [], responsive: [], dialogs: [], filters: [], diagnostics: [], failures: [] }
let token, browser, socket
async function http(path, options = {}) {
  const response = await fetch(api + path, { ...options, headers: { Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}), ...options.headers }, signal: AbortSignal.timeout(20000) })
  assert.ok(response.ok, `API ${path}: ${response.status}`)
  return response.json()
}
try {
  const login = await http('/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: process.env.DESIGN_TEST_EMAIL || 'super_admin@demo.shishli.local', password: process.env.DESIGN_TEST_PASSWORD || 'DemoTest123!' }) })
  token = login.data.token
  const ids = {}
  for (const [key, path] of Object.entries({ products: '/admin/products', branches: '/admin/branches', groups: '/admin/option-groups', orders: '/admin/orders', kitchen: '/admin/kitchen/orders', payments: '/admin/payments', notifications: '/admin/notifications' })) {
    const response = await http(path)
    ids[key] = response.data.data?.[0]?.id || response.data?.[0]?.id
  }
  browser = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--remote-debugging-port=9253', `--user-data-dir=${output}/profile`, 'about:blank'], { windowsHide: true, stdio: 'ignore' })
  let pages
  for (let attempt = 0; attempt < 100; attempt++) { try { pages = await (await fetch('http://127.0.0.1:9253/json')).json(); if (pages.length) break } catch {} await sleep(100) }
  assert.ok(pages?.length, 'Chrome debugging connection')
  socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl)
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }) })
  let sequence = 0
  const pending = new Map()
  const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })) })
  socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data)
    if (message.id) { const promise = pending.get(message.id); pending.delete(message.id); message.error ? promise.reject(message.error) : promise.resolve(message.result); return }
    if (message.method === 'Runtime.exceptionThrown') report.diagnostics.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text)
    if (message.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(message.params.type)) report.diagnostics.push(message.params.args.map(arg => arg.value ?? arg.description).join(' '))
    if (message.method === 'Network.responseReceived' && message.params.response.status >= 400) report.failures.push({ status: message.params.response.status, path: new URL(message.params.response.url).pathname })
  })
  const evaluate = async expression => { const result = await send('Runtime.evaluate', { expression, returnByValue: true }); if (result.exceptionDetails) throw Error(result.exceptionDetails.text); return result.result.value }
  const waitFor = async expression => { for (let attempt = 0; attempt < 160; attempt++) { if (await evaluate(expression)) return; await sleep(100) } throw Error('Timeout: ' + expression) }
  const navigate = async path => { await send('Page.navigate', { url: origin + path }); await waitFor('document.readyState === "complete" && document.querySelector("main")') }
  const clickText = async (selector, label) => { assert.ok(await evaluate(`(() => { const element = [...document.querySelectorAll(${JSON.stringify(selector)})].find(item => item.textContent.includes(${JSON.stringify(label)})); element?.click(); return Boolean(element) })()`), 'Control ' + label) }
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  // A previous completed run revoked its token. Clear that saved session before bootstrap.
  const init = await send('Page.addScriptToEvaluateOnNewDocument', { source: "localStorage.removeItem('shishli_auth_token')" })
  await navigate('/login'); await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: init.identifier })
  await evaluate(`localStorage.setItem('shishli_auth_token', ${JSON.stringify(token)})`)
  const paths = [
    ['/admin', '.overview-stats .stat', 'live'], ['/admin/products', '.products-table tbody tr', 'live'],
    ['/admin/categories', '.catalog-table tbody tr', 'live'], ['/admin/branches', '.catalog-table tbody tr', 'live'],
    ['/admin/product-options', '.catalog-table tbody tr', 'live'], ['/admin/orders', '.order-card', 'live'],
    ['/admin/kitchen', '.kitchen-card', 'live'], ['/admin/payments', '.payments-table tbody tr', 'live'],
    ['/admin/notifications', '.notifications-table tbody tr, .notifications-panel .empty', 'live'], ['/admin/delivery-zones', '.table-wrap tbody tr, .empty', 'live'],
    ['/admin/offers', '#content .table-wrap tbody tr, #content .empty', 'preview'], ['/admin/customers', '#content .table-wrap tbody tr, #content .empty', 'preview'],
    ['/admin/settings', '#settings-form', 'preview'], ['/admin/reports', '.report-table tbody tr, .report-metrics .stat, .report-table .empty', 'live'],
  ]
  for (const [key, path] of Object.entries({ products: 'products', branches: 'branches', groups: 'product-options', orders: 'orders', kitchen: 'kitchen', payments: 'payments', notifications: 'notifications' })) {
    if (ids[key]) paths.push(['/admin/' + path + '/' + ids[key], key === 'products' || key === 'branches' || key === 'groups' ? '.catalog-section' : key === 'orders' ? '.order-details-layout' : key === 'kitchen' ? '.kitchen-snapshot' : key === 'payments' ? '.payment-summary' : '.notification-detail', 'live'])
  }
  for (const [path, selector, source] of paths) {
    await navigate(path); await waitFor(`document.querySelector(${JSON.stringify(selector)})`)
    assert.equal(await evaluate('location.pathname'), path.split('?')[0], 'Route ' + path)
    const heading = await evaluate('document.querySelector("main h1").textContent.trim()')
    report.pages.push({ path, heading, source })
    for (const width of [1440, 768, 390]) {
      await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false }); await sleep(120)
      const overflow = await evaluate('document.documentElement.scrollWidth > innerWidth + 1')
      if (overflow) console.log('Overflow elements', path, width, await evaluate('[...document.querySelectorAll("main *")].filter(e=>{let r=e.getBoundingClientRect();return r.left< -1||r.right>innerWidth+1}).slice(0,8).map(e=>({tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width}))'))
      assert.equal(overflow, false, 'Page overflow: ' + path + ' at ' + width)
      report.responsive.push({ path, width })
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
    for (let index = 0; index < await evaluate('document.querySelectorAll(".admin-filter-extras").length'); index++) {
      await evaluate(`document.querySelectorAll('.admin-filter-extras')[${index}].querySelector('summary').click()`)
      assert.ok(await evaluate(`document.querySelectorAll('.admin-filter-extras')[${index}].open`))
      await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 1000, deviceScaleFactor: 1, mobile: false }); await sleep(100)
      assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth+1'), 'Open filters overflow: ' + path)
      await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
      report.filters.push({ path, controls: await evaluate(`document.querySelectorAll('.admin-filter-extras')[${index}].querySelectorAll('input,select').length`) })
    }
    await writeFile(output + path.split('/').slice(2).join('-').replace(/[?=]/g, '-') + (path === '/admin' ? 'overview' : '') + '.png', Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
    console.log('Checked', path, source)
  }
  // Read-only opening of CRUD modals and domain forms, including permission-bound fields.
  for (const [path, label, expected] of [['products', 'إضافة منتج', 10], ['categories', 'إضافة تصنيف', 5], ['branches', 'إضافة فرع', 10], ['product-options', 'إضافة مجموعة', 6]]) {
    await navigate('/admin/' + path); await waitFor('document.querySelector(".table-wrap tbody tr")'); await clickText('main button', label); await waitFor('document.querySelector("dialog[open]")')
    assert.ok(await evaluate('document.querySelectorAll("dialog[open] input,dialog[open] select,dialog[open] textarea").length') >= expected)
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: false }); await sleep(100)
    assert.ok(await evaluate('(() => { const r=document.querySelector("dialog[open]").getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && r.height <= innerHeight })()'))
    await clickText('dialog[open] button', 'حفظ البيانات') // Empty required fields must stop submission locally.
    assert.ok(await evaluate('Boolean(document.querySelector("dialog[open] :invalid"))'))
    await evaluate('document.querySelector("dialog[open]").close()'); report.dialogs.push({ path, requiredValidation: true })
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
  }
  // Apply real non-destructive filters on lists, then wait for backend responses.
  for (const [path, name] of [['orders', 'order_search'], ['payments', 'payment_search'], ['notifications', 'notification_search']]) {
    await navigate('/admin/' + path); await waitFor('document.querySelector(".admin-list-filters")')
    await evaluate(`(() => { const input=document.querySelector('[name=${name}]'); input.value='DESIGN-CHECK-NO-MATCH'; input.dispatchEvent(new Event('input',{bubbles:true})); document.querySelector('.admin-list-filters').requestSubmit() })()`)
    await waitFor('document.querySelector(".empty")?.textContent.includes("لا توجد")')
    report.filters.push({ path, liveEmptySearch: true })
  }
  assert.deepEqual(report.diagnostics, [], 'Browser diagnostics')
  assert.deepEqual(report.failures, [], 'HTTP failures')
  await writeFile(output + 'report.json', JSON.stringify(report, null, 2))
  console.log(JSON.stringify({ pages: report.pages.length, responsive: report.responsive.length, dialogs: report.dialogs.length, filters: report.filters.length, diagnostics: report.diagnostics.length, httpFailures: report.failures.length }))
} finally {
  await writeFile(output + 'last-run.json', JSON.stringify(report, null, 2))
  if (token) await fetch(api + '/logout', { method: 'POST', headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' }, signal: AbortSignal.timeout(10000) }).catch(() => {})
  socket?.close(); browser?.kill()
}
