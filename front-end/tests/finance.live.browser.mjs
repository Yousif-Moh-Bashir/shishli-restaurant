import assert from 'node:assert/strict'
import { createServer, request as httpRequest } from 'node:http'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

// Only the dedicated, disposable Compose stack is permitted. No API fixtures or production URLs.
const upstream = 'http://127.0.0.1:18099', customer = 'http://127.0.0.1:18100'
const output = resolve('.finance-check/live'), failures = [], calls = []
const proxy = createServer((req, res) => {
  const target = httpRequest(upstream + req.url, { method: req.method, headers: { ...req.headers, host: 'finance.shishlifresh.com' } }, response => {
    calls.push({ method: req.method, path: req.url, status: response.statusCode })
    res.writeHead(response.statusCode, response.headers); response.pipe(res)
  })
  target.on('error', () => { failures.push('Isolated Docker upstream unavailable'); res.writeHead(502); res.end() })
  req.pipe(target)
})
await mkdir(output, { recursive: true })
await new Promise(done => proxy.listen(0, '127.0.0.1', done))
const origin = `http://127.0.0.1:${proxy.address().port}`
let browser
try {
  const denied = await fetch(upstream + '/api/v1/finance/me', { headers: { Host: 'shishlifresh.com' } })
  assert.equal(denied.status, 404)
  assert.equal(denied.headers.get('x-content-type-options'), 'nosniff')
  const customerDenied = await fetch(customer + '/api/v1/finance/me', { headers: { Host: 'shishlifresh.com' } })
  assert.equal(customerDenied.status, 404)
  assert.match(customerDenied.headers.get('cache-control'), /no-store/)
  assert.equal((await fetch(customer + '/', { headers: { Host: 'shishlifresh.com' } })).status, 200)
  const guest = await fetch(origin + '/api/v1/finance/me', { headers: { Accept: 'application/json' } })
  assert.equal(guest.status, 401); assert.match(guest.headers.get('cache-control'), /no-store/)
  browser = await browserClient(9403, output)
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    const url = new URL(request.url)
    if (url.hostname === 'fonts.googleapis.com') await browser.send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/css' }], body: '' })
    else if (url.origin !== origin) { failures.push('Unexpected non-isolated browser request'); await browser.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }) }
    else await browser.send('Fetch.continueRequest', { requestId })
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: 'http*' }] })
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
  await browser.go(origin, '/login')
  await browser.input('input[type=email]', 'finance-runtime@example.test')
  await browser.input('input[type=password]', 'RuntimeFixturePassword123')
  await browser.click('button', 'الدخول إلى')
  await browser.wait('document.querySelector(".stats-grid")')
  const token = await browser.evaluate('sessionStorage.getItem("shishli_finance_token")')
  assert.ok(token)
  const api = async (path, method = 'GET', data) => {
    const r = await fetch(origin + '/api/v1/finance/' + path, { method, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(data ? { 'Content-Type': 'application/json' } : {}) }, body: data ? JSON.stringify(data) : undefined })
    return { status: r.status, body: await r.json() }
  }
  const me = await api('me')
  assert.equal(me.body.data.user.email, 'finance-runtime@example.test')
  assert.equal(me.body.data.branches[0].name, 'فرع اختبار مالي معزول')
  assert.equal((await api('revenues')).body.data.length, 0, 'A fresh isolated runtime database is required')
  await browser.click('nav a', 'الإيرادات اليومية'); await browser.wait('document.querySelector(".empty")')
  await browser.click('button', 'تسجيل يوم جديد'); await browser.wait('document.querySelector("dialog[open]")')
  await browser.evaluate('[...document.querySelectorAll("dialog .amount-field input")].forEach(e=>{e.value="0";e.dispatchEvent(new Event("input",{bubbles:true}))})')
  await browser.input('dialog .amount-field input', '0.10')
  await browser.evaluate('(()=>{const e=document.querySelectorAll("dialog .amount-field input")[1];e.value="0.20";e.dispatchEvent(new Event("input",{bubbles:true}))})()')
  await browser.click('dialog button', 'حفظ المسودة'); await browser.wait('document.querySelector("tbody tr") && !document.querySelector("dialog")')
  let row = (await api('revenues')).body.data[0]
  assert.equal(row.total, '0.30'); assert.equal(row.status, 'draft')
  await browser.click('tbody button', 'اعتماد'); await browser.wait('document.querySelector("dialog[open]")'); await browser.click('dialog button', 'تأكيد الاعتماد')
  await browser.wait('document.querySelector("tbody .approved") && !document.querySelector("dialog")')
  row = (await api('revenues')).body.data[0]
  const payload = { branch_uuid: row.branch.uuid, date: row.date, version: row.version, lines: row.lines.map(l => ({ source_uuid: l.source.uuid, amount: l.amount })) }
  assert.equal((await api('revenues/' + row.uuid, 'PUT', payload)).status, 409)
  assert.equal((await api('revenues', 'POST', payload)).status, 422)
  await browser.click('tbody button', 'تصحيح موثق'); await browser.wait('document.querySelector("dialog[open]")')
  await browser.input('dialog .amount-field input', '100.10'); await browser.input('dialog textarea[minlength]', 'تصحيح اختبار تشغيل معزول')
  await browser.click('dialog button', 'حفظ التصحيح الموثق'); await browser.wait('!document.querySelector("dialog")')
  row = (await api('revenues')).body.data[0]
  assert.equal(row.total, '100.30'); assert.equal(row.version, 3)
  await browser.click('nav a', 'المصروفات'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'إضافة مصروف'); await browser.wait('document.querySelector("dialog[open]")')
  await browser.input('dialog input[inputmode=decimal]', '20.25'); await browser.input('dialog textarea', 'مصروف اختبار معزول')
  await browser.evaluate('(()=>{const e=document.querySelectorAll("dialog select")[1];e.value=[...e.options].find(o=>o.value).value;e.dispatchEvent(new Event("change",{bubbles:true}))})()')
  await browser.click('dialog button', 'حفظ السجل'); await browser.wait('!document.querySelector("dialog")')
  assert.equal((await api('expenses')).body.data[0].amount, '20.25')
  await browser.click('nav a', 'الموردون'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'إضافة مورد'); await browser.wait('document.querySelector("dialog[open]")')
  await browser.input('dialog input', 'مورد اختبار تشغيل'); await browser.click('dialog button', 'حفظ'); await browser.wait('!document.querySelector("dialog")')
  await browser.click('nav a', 'المشتريات'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'فاتورة مشتريات'); await browser.wait('document.querySelector("dialog[open]")')
  await browser.input('dialog input[inputmode=decimal]', '30.10'); await browser.input('dialog input[maxlength="100"]', 'LIVE-TEST-1'); await browser.input('dialog textarea', 'شراء اختبار معزول')
  await browser.wait('document.querySelectorAll("dialog select")[1].options.length > 1')
  await browser.evaluate('(()=>{const e=document.querySelectorAll("dialog select")[1];e.value=[...e.options].find(o=>o.value).value;e.dispatchEvent(new Event("change",{bubbles:true}))})()')
  await browser.click('dialog button', 'حفظ السجل'); await browser.wait('!document.querySelector("dialog")')
  assert.equal((await api('reports')).body.data.totals.net_flow, '49.95')
  for (const format of ['pdf', 'xlsx']) {
    const r = await fetch(origin + '/api/v1/finance/reports/export/' + format, { headers: { Authorization: `Bearer ${token}` } })
    assert.equal(r.status, 200)
    const binary = Buffer.from(await r.arrayBuffer())
    assert.ok(binary.subarray(0, 5).toString().startsWith(format === 'pdf' ? '%PDF-' : 'PK'))
  }
  assert.equal((await fetch(customer + '/api/v1/finance/me', { headers: { Host: 'shishlifresh.com', Authorization: `Bearer ${token}` } })).status, 404)
  const audit = (await api('audit')).body.data.data
  assert.ok(audit.some(a => a.action === 'revenues.correct' && a.reason === 'تصحيح اختبار تشغيل معزول'))
  await browser.click('nav a', 'لوحة التحكم'); await browser.wait('document.querySelector(".stats-grid")')
  await writeFile(resolve(output, 'dashboard.png'), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data, 'base64'))
  await browser.click('button', 'خروج'); await browser.wait('location.pathname === "/login"')
  assert.equal((await api('me')).status, 401)
  assert.equal(failures.length, 0, JSON.stringify(failures)); assert.equal(browser.diagnostics.length, 0, JSON.stringify(browser.diagnostics))
  const evidence = { passed: true, stack: 'Chrome → local proxy → Docker Nginx → PHP-FPM/Laravel → isolated MySQL 8.4', mockedApi: false, checks: ['finance login', 'server total', 'draft', 'approval', 'duplicate rejection', 'approved edit rejection', 'correction audit', 'expense', 'supplier', 'purchase', 'net flow', 'real PDF/XLSX', 'customer host blocked', 'logout revocation'], browserApiCalls: calls.filter(c => c.path.startsWith('/api/')).length }
  await writeFile(resolve(output, 'result.json'), JSON.stringify(evidence, null, 2)); console.log(JSON.stringify(evidence))
} finally { browser?.close(); await new Promise(done => proxy.close(done)) }
