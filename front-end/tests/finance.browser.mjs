import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { resolve, extname } from 'node:path'
import { browserClient } from './browser-client.mjs'
import { paths as adminIconPaths } from '../src/data/adminIcons.js'

// Real built Vue in Chrome with an isolated HTTP transport fixture. Laravel is tested separately.
const root = resolve('dist-finance'), output = resolve('.finance-check')
const uuid = n => `11111111-1111-4111-8111-${String(n).padStart(12, '0')}`
const branch = { uuid: uuid(1), name: 'فرع الاختبار' }, user = { uuid: uuid(2), name: 'المدير المالي', email: 'finance@example.test' }
const sources = ['شبكة', 'كاش', 'تحويل', 'كيتا', 'جاهز', 'هنقرستيشن'].map((name, i) => ({ uuid: uuid(10 + i), name, is_active: true }))
const categories = [{ uuid: uuid(30), name: 'الكهرباء', is_active: true }]
const permissions = ['access', 'revenues.view', 'revenues.create', 'revenues.edit', 'revenues.approve', 'revenues.correct', 'expenses.view', 'expenses.create', 'expenses.edit', 'purchases.view', 'purchases.create', 'purchases.edit', 'suppliers.view', 'suppliers.create', 'suppliers.edit', 'settings.manage', 'users.manage', 'audit.view', 'reports.view', 'reports.export', 'branches.all'].map(p => `finance.${p}`)
let entry = false, rejectExpense = true, reportMode = 'normal'
let exportMode = 'normal'
const revenues = [], expenses = [], purchases = [], suppliers = [], users = [], calls = [], errors = []
const totals = { revenues: '100.30', expenses: '20.25', purchases: '20.25', net_flow: '59.80' }
const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost'), path = url.pathname
    if (path.startsWith('/api/')) {
      let raw = Buffer.alloc(0); for await (const chunk of req) raw = Buffer.concat([raw, chunk])
      const multipart = req.headers['content-type']?.startsWith('multipart/')
      const body = raw.length ? multipart ? Object.fromEntries(await new Response(raw, { headers: { 'Content-Type': req.headers['content-type'] } }).formData()) : JSON.parse(raw.toString()) : {}
      calls.push({ path, method: req.method, body, query: Object.fromEntries(url.searchParams) })
      const send = (data, status = 200, extra = {}) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ success: status < 400, data, ...extra })) }
      if (path === '/api/v1/finance/login') return send({ token: 'fixture-finance-token', user })
      assert.equal(req.headers.authorization, 'Bearer fixture-finance-token')
      if (path.endsWith('/logout')) return send(null)
      if (path.endsWith('/finance/me')) return send({ user, permissions: entry ? permissions.filter(p => !/approve|correct|manage|export|reports|branches/.test(p)) : permissions, branches: [branch], sources, categories, timezone: 'Asia/Riyadh', currency: 'SAR' })
      if (path.endsWith('/finance/reports')) {
        const values = reportMode === 'empty' ? { revenues: '0.00', expenses: '0.00', purchases: '0.00', net_flow: '0.00' } : reportMode === 'negative' ? { ...totals, expenses: '120.25', net_flow: '-40.20' } : totals
        const periods = reportMode === 'empty' ? [] : reportMode === 'long' ? Array.from({ length: 10 }, (_, i) => ({ period: `2026-11-${String(i + 1).padStart(2, '0')}`, revenues: '10.03', expenses: i === 9 ? '20.25' : '0.00', purchases: i === 9 ? '20.25' : '0.00', net_flow: i === 9 ? '-30.47' : '10.03' })) : [{ period: '2026-11-01', ...values }]
        return send({ filters: { from: '2026-11-01', to: reportMode === 'long' ? '2026-11-10' : '2026-11-02' }, totals: values, today: values, month: values, previous: { ...totals, revenues: reportMode === 'empty' ? '0.00' : '50.15', from: '2026-10-30', to: '2026-10-31' }, periods, sources: reportMode === 'empty' ? [] : [{ ...sources[0], amount: '100.30' }], basis: 'الإيرادات المعتمدة فقط. صافي التدفق المسجل ليس صافي ربح محاسبيًا.' })
      }
      if (path.includes('/reports/export/')) {
        const pdf = path.endsWith('pdf')
        res.writeHead(200, { 'Content-Type': pdf && exportMode !== 'wrong-type' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
        return res.end(pdf && exportMode === 'normal' ? '%PDF-fixture' : 'PK-fixture')
      }
      if (path.endsWith('/finance/audit')) return send({ data: [
        { uuid: uuid(70), action: 'revenues.correct', actor: user, subject_uuid: uuid(50), created_at: '2026-11-02T10:00:00Z', reason: 'تصحيح الاختبار', old_values: { total: '100.30' }, new_values: { total: '150.30' } },
        { uuid: uuid(71), action: 'expenses.create', actor: user, subject_uuid: uuid(60), created_at: '2026-11-03T15:05:09Z', reason: null, old_values: [], new_values: { uuid: uuid(60), date: '2026-11-03', amount: '74.00', description: 'شراء غاز للمحل', payment_method: 'cash', version: 1, created_at: '2026-11-03T15:05:09Z', attachment_changed: false } },
      ], current_page: 1, last_page: 1, total: 2 })
      if (path.includes('/finance/settings/')) {
        const kind = path.split('/')[5], rows = kind === 'sources' ? sources : kind === 'categories' ? categories : suppliers
        if (req.method === 'GET') return send(rows)
        const id = path.split('/')[6]
        if (id) Object.assign(rows.find(r => r.uuid === id), body)
        else rows.push({ uuid: uuid(80 + rows.length), ...body })
        return send(rows.at(-1), id ? 200 : 201)
      }
      if (path.includes('/finance/users')) {
        if (req.method === 'GET') return send({ users, roles: ['finance_entry', 'finance_manager', 'finance_accountant'] })
        users.push({ uuid: uuid(90), ...body }); return send(users.at(-1), 201)
      }
      const kind = path.split('/')[4], rows = { revenues, expenses, purchases }[kind]
      if (rows) {
        if (req.method === 'GET') return send(rows, 200, { meta: { current_page: 1, last_page: 1, total: rows.length } })
        const id = path.split('/')[5], action = path.split('/')[6]
        if (kind === 'revenues') {
          if (action === 'approve') { Object.assign(rows.find(r => r.uuid === id), { status: 'approved', approver: user, version: 2 }); return send(rows.find(r => r.uuid === id)) }
          const record = { uuid: id || uuid(50), branch, creator: user, date: body.date, total: action === 'correct' ? '150.30' : '100.30', status: action === 'correct' ? 'approved' : 'draft', version: action === 'correct' ? 3 : 1, notes: body.notes, lines: body.lines.map(l => ({ amount: l.amount === '' || l.amount == null ? '0.00' : l.amount, source: sources.find(s => s.uuid === l.source_uuid) })) }
          if (id) Object.assign(rows.find(r => r.uuid === id), record); else rows.push(record)
          return send(record, id ? 200 : 201)
        }
        if (kind === 'expenses' && rejectExpense) { rejectExpense = false; return send(null, 422, { errors: { amount: ['المبلغ غير صحيح للاختبار'] } }) }
        const record = { uuid: id || uuid(kind === 'expenses' ? 60 : 61 + rows.length), ...body, branch, creator: user, category: categories[0], supplier: suppliers.find(s => s.uuid === body.supplier_uuid) || null, version: 1, has_attachment: false }
        if (id) Object.assign(rows.find(r => r.uuid === id), record); else rows.push(record)
        return send(record, id ? 200 : 201)
      }
      return send(null, 404)
    }
    const pathOnDisk = resolve(root, '.' + decodeURIComponent(path))
    if (pathOnDisk !== root && !pathOnDisk.startsWith(root + '/') && !pathOnDisk.startsWith(root + '\\')) { res.writeHead(403); return res.end() }
    let file = pathOnDisk, content
    try { content = await readFile(file) } catch { file = resolve(root, 'finance.html'); content = await readFile(file) }
    res.writeHead(200, { 'Content-Type': { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' }[extname(file)] || 'application/octet-stream' }); res.end(content)
  } catch (e) { errors.push(e.message); res.writeHead(500); res.end('{}') }
})
await mkdir(output, { recursive: true })
await new Promise(done => server.listen(0, '127.0.0.1', done))
const origin = 'http://127.0.0.1:' + server.address().port
let browser
async function assertDialogFits(width, height) {
  await browser.wait('(()=>{const d=document.querySelector("dialog[open]"),h=d?.querySelector("header"),f=d?.querySelector(".modal-actions");return h && f && h.getBoundingClientRect().top>=0 && f.getBoundingClientRect().bottom<=innerHeight+1})()')
  const geometry = await browser.evaluate('(()=>{const d=document.querySelector("dialog[open]");return {horizontal:d.scrollWidth>d.clientWidth+1,outerScroll:d.scrollHeight>d.clientHeight+1,header:d.querySelector("header").getBoundingClientRect().top,footer:d.querySelector(".modal-actions").getBoundingClientRect().bottom}})()')
  assert.equal(geometry.horizontal, false, `modal fits ${width}x${height}`)
  assert.equal(geometry.outerScroll, false, `one inner scrollbar at ${width}x${height}`)
  assert.ok(geometry.header >= 0 && geometry.footer <= height + 1)
}
try {
  browser = await browserClient(9401, output)
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    const url = new URL(request.url)
    if (url.hostname === 'fonts.googleapis.com') await browser.send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/css' }], body: '' })
    else if (url.origin !== origin) { errors.push('Unexpected external request: ' + url.origin); await browser.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }) }
    else await browser.send('Fetch.continueRequest', { requestId })
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: 'http*' }] })
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  await browser.go(origin, '/login')
  for (const [width, height] of [[320,568],[360,740],[390,844],[430,932],[640,360],[844,390],[1024,768],[1280,800],[1440,900],[1920,1080]]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width <= 1024 })
    await browser.wait('document.querySelector(".login-form")')
    const layout = await browser.evaluate('(()=>{const form=document.querySelector(".login-form"),button=form.querySelector("button[type=submit]"),r=form.getBoundingClientRect();return {overflow:document.documentElement.scrollWidth>innerWidth,visible:r.left>=0&&r.right<=innerWidth+1,touch:button.getBoundingClientRect().height,font:parseFloat(getComputedStyle(form.querySelector("input")).fontSize),labels:[...form.querySelectorAll("input")].every(i=>!!i.labels?.length),password:form.querySelector("input[type=password]").autocomplete}})()')
    assert.equal(layout.overflow, false, `login has no horizontal overflow at ${width}x${height}`)
    assert.equal(layout.visible, true)
    assert.ok(layout.touch >= 44)
    assert.ok(layout.font >= 16)
    assert.equal(layout.labels, true)
    assert.equal(layout.password, 'current-password')
    await browser.input('input[type=email]', 'finance@example.test')
    await browser.input('input[type=password]', 'FixturePassword123')
    await browser.evaluate('document.querySelector("button[type=submit]").scrollIntoView({block:"center"})')
    assert.equal(await browser.evaluate('(()=>{const r=document.querySelector("button[type=submit]").getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight})()'), true)
    if ([320,390,1440].includes(width)) {
      await browser.evaluate('document.activeElement?.blur();window.scrollTo(0,0)')
      await writeFile(resolve(output, `login-${width}.png`), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
    }
  }
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  await browser.click('button', 'الدخول إلى')
  await browser.wait('document.querySelector(".stats-grid")')
  assert.deepEqual(await browser.evaluate('[...document.querySelectorAll(".dashboard-quick-actions a")].map(a=>a.getAttribute("href"))'), ['/revenues', '/expenses', '/purchases'])
  assert.equal(await browser.evaluate('document.querySelector(".dashboard-period-metrics strong").textContent === new Intl.NumberFormat("ar-SA",{style:"currency",currency:"SAR",maximumFractionDigits:2}).format(100.30)'), true)
  reportMode = 'long'
  await browser.go(origin, '/'); await browser.wait('document.querySelectorAll(".dashboard-trend-row").length===7')
  await browser.click('.dashboard-expand', 'عرض جميع الفترات')
  await browser.wait('document.querySelectorAll(".dashboard-trend-row").length===10')
  await browser.click('.dashboard-expand', 'عرض آخر 7 فترات')
  await browser.wait('document.querySelectorAll(".dashboard-trend-row").length===7')
  reportMode = 'empty'
  await browser.go(origin, '/'); await browser.wait('document.querySelector(".trend-panel .empty")')
  assert.equal(await browser.evaluate('document.querySelector(".comparison-value").textContent'), '—')
  assert.equal(await browser.evaluate('!!document.querySelector(".dashboard-sources .empty")'), true)
  assert.doesNotMatch(await browser.evaluate('document.body.innerText'), /NaN|Infinity/)
  reportMode = 'negative'
  await browser.go(origin, '/'); await browser.wait('document.querySelector(".dashboard-net-flow>.negative")')
  assert.equal(await browser.evaluate('document.querySelector(".dashboard-net-flow>strong").textContent === new Intl.NumberFormat("ar-SA",{style:"currency",currency:"SAR",maximumFractionDigits:2}).format(-40.20)'), true)
  reportMode = 'normal'
  await browser.go(origin, '/'); await browser.wait('document.querySelector(".stats-grid")')
  assert.equal(await browser.evaluate(`(()=>{const expected=document.createElementNS('http://www.w3.org/2000/svg','svg');expected.innerHTML=${JSON.stringify(adminIconPaths.wallet)};return document.querySelector('nav a[href="/revenues"] svg').innerHTML===expected.innerHTML})()`), true)
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("nav svg")).strokeWidth'), '1.7px')
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth'), true)
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("nav"),"::-webkit-scrollbar").width'), '6px')
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("nav")).scrollbarColor'), 'auto')
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("nav"),"::-webkit-scrollbar-button").display'), 'none')
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("select"),"::-webkit-scrollbar").width'), '6px')
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 500, deviceScaleFactor: 1, mobile: false })
  assert.equal(await browser.evaluate('(()=>{const n=document.querySelector("nav");n.scrollTop=100;return n.scrollHeight>n.clientHeight && n.scrollTop>0})()'), true)
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".finance-sidebar-close")).display'), 'none')
  await writeFile(resolve(output, 'sidebar-desktop-scroll.png'), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  await writeFile(resolve(output, 'dashboard-desktop.png'), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data, 'base64'))
  await browser.evaluate('window.financeDownloads=[];document.addEventListener("click",e=>{const a=e.target.closest("a[download]");if(a)window.financeDownloads.push(a.download)},true)')
  const exportsBefore = calls.filter(c => c.path.includes('/reports/export/')).length
  await browser.evaluate('[...document.querySelectorAll(".page-intro button")].find(b=>b.textContent.trim()==="PDF").click();[...document.querySelectorAll(".page-intro button")].find(b=>b.textContent.trim()==="PDF").click()')
  await browser.wait('![...document.querySelectorAll(".page-intro button")].some(b => b.disabled)')
  assert.deepEqual(calls.filter(c => c.path.includes('/reports/export/')).slice(exportsBefore).map(c=>c.path), ['/api/v1/finance/reports/export/pdf'])
  assert.deepEqual(await browser.evaluate('window.financeDownloads'), ['shishli-finance.pdf'])
  assert.equal(await browser.evaluate('document.querySelectorAll("a[download]").length'), 0)
  for (exportMode of ['wrong-type', 'wrong-signature']) {
    await browser.click('button', 'PDF'); await browser.wait('![...document.querySelectorAll(".page-intro button")].some(b => b.disabled)')
    assert.deepEqual(await browser.evaluate('window.financeDownloads'), ['shishli-finance.pdf'])
    assert.match(await browser.evaluate('document.querySelector(".notice.danger").textContent'), /صيغة التقرير المستلم/)
  }
  exportMode = 'normal'
  await browser.click('button', 'Excel'); await browser.wait('![...document.querySelectorAll(".page-intro button")].some(b => b.disabled)')
  assert.deepEqual(await browser.evaluate('window.financeDownloads'), ['shishli-finance.pdf', 'shishli-finance.xlsx'])
  await browser.click('nav a', 'الإيرادات اليومية'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'تسجيل يوم جديد'); await browser.wait('document.querySelector("dialog[open]")')
  const amountSelector = 'dialog .amount-field input'
  const revenueRequests = calls.filter(c=>c.path==='/api/v1/finance/revenues' && c.method==='POST').length
  const allowedDate = await browser.evaluate('document.querySelector("dialog input[type=date]").max')
  assert.equal(await browser.evaluate('document.querySelector("dialog input[type=date]").value'), allowedDate)
  await browser.input('dialog input[type=date]', '2099-01-01')
  assert.equal(await browser.evaluate('document.querySelector("dialog input[type=date]").validity.rangeOverflow'), true)
  await browser.evaluate('document.querySelector("dialog form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
  await browser.wait('document.querySelector("dialog").textContent.includes("لا يمكن تسجيل إيرادات بتاريخ مستقبلي")')
  assert.equal(calls.filter(c=>c.path==='/api/v1/finance/revenues' && c.method==='POST').length, revenueRequests)
  await browser.input('dialog input[type=date]', allowedDate)
  assert.equal(await browser.evaluate('[...document.querySelectorAll("dialog .amount-field input")].every(e => e.value === "" && e.placeholder === "0.00")'), true)
  async function checkAmountEditing(selector) {
    for (const amount of ['5', '15', '150', '150.50', '0.75']) {
      await browser.evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.focus();e.select()})()`)
      await browser.send('Input.insertText', { text: amount })
      assert.equal(await browser.evaluate(`document.querySelector(${JSON.stringify(selector)}).value`), amount)
      await browser.evaluate('document.activeElement.blur()')
      assert.equal(await browser.evaluate(`document.querySelector(${JSON.stringify(selector)}).value`), amount)
    }
    await browser.input(selector, '150.50')
    await browser.evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.focus();e.setSelectionRange(1,1)})()`)
    await browser.send('Input.insertText', { text: '2' })
    assert.deepEqual(await browser.evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});return [e.value,e.selectionStart]})()`), ['1250.50', 2])
    await browser.input(selector, '')
    assert.equal(await browser.evaluate(`document.querySelector(${JSON.stringify(selector)}).value`), '')
    assert.equal(await browser.evaluate(`document.querySelector(${JSON.stringify(selector)}).validity.valueMissing`), selector !== amountSelector)
    await browser.send('Input.insertText', { text: '0' })
    assert.equal(await browser.evaluate(`document.querySelector(${JSON.stringify(selector)}).checkValidity()`), true)
  }
  await checkAmountEditing(amountSelector)
  await browser.input(amountSelector, '')
  await browser.click('dialog button', 'حفظ المسودة')
  await browser.wait('document.querySelector("dialog .danger")')
  assert.equal(revenues.length, 0)
  for (const [network, cash, total] of [['', '150', 150], ['200', '100', 300], ['0', '150', 150]]) {
    await browser.input(amountSelector, network)
    await browser.evaluate(`(()=>{const e=document.querySelectorAll("dialog .amount-field input")[1];e.value=${JSON.stringify(cash)};e.dispatchEvent(new Event("input",{bubbles:true}))})()`)
    assert.equal(await browser.evaluate('document.querySelector("dialog form").checkValidity()'), true)
    assert.equal(await browser.evaluate(`document.querySelector('.total-row strong').textContent === new Intl.NumberFormat('ar-SA',{style:'currency',currency:'SAR',maximumFractionDigits:2}).format(${total})`), true)
  }
  for (const invalid of ['-1', 'abc', '1.001']) {
    await browser.input(amountSelector, invalid)
    assert.equal(await browser.evaluate('document.querySelector("dialog form").checkValidity()'), false)
  }
  await browser.input('dialog .amount-field input', '100.10'); await browser.evaluate('(()=>{const e=document.querySelectorAll("dialog .amount-field input")[1];e.value="0.20";e.dispatchEvent(new Event("input",{bubbles:true}))})()')
  await browser.click('dialog button', 'حفظ المسودة'); await browser.wait('document.querySelector("tbody tr") && !document.querySelector("dialog")')
  assert.equal(revenues.length, 1); assert.equal(revenues[0].lines[0].amount, '100.10')
  await browser.evaluate('Object.defineProperty(navigator,"clipboard",{configurable:true,value:{writeText:async text=>{window.copiedRevenueText=text}}})')
  const beforeCopy = calls.length
  await browser.click('.finance-revenue-copy', 'نسخ')
  await browser.wait('document.querySelector(".finance-revenue-copy").textContent.includes("تم النسخ")')
  assert.match(await browser.evaluate('window.copiedRevenueText'), /المجموع 100\.30\nالحالة: مسودة/)
  assert.equal(calls.length, beforeCopy)
  await browser.click('tbody button', 'عرض وتعديل'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelector("dialog .amount-field input").value'), '100.10')
  await checkAmountEditing(amountSelector)
  await browser.click('dialog button', 'إغلاق')
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".revenue-table tbody tr")).display'), 'grid')
  await browser.click('tbody button', 'اعتماد'); await browser.wait('document.querySelector("dialog[open]")'); await browser.click('dialog button', 'تأكيد الاعتماد'); await browser.wait('document.querySelector("tbody .approved") && !document.querySelector("dialog")')
  await browser.click('tbody button', 'تصحيح موثق'); await browser.wait('document.querySelector("dialog[open]")'); await browser.input('dialog .amount-field input', '150.10'); await browser.input('dialog textarea[minlength]', 'تصحيح مبلغ الشبكة'); await browser.click('dialog button', 'حفظ التصحيح الموثق'); await browser.wait('!document.querySelector("dialog")')
  assert.equal(revenues[0].version, 3)
  await browser.click('.finance-revenue-copy', 'نسخ')
  await browser.wait('document.querySelector(".finance-revenue-copy").textContent.includes("تم النسخ")')
  const approvedCopy = await browser.evaluate('window.copiedRevenueText')
  assert.match(approvedCopy, /المجموع 150\.30$/); assert.equal(approvedCopy.includes('مسودة'), false)
  await browser.evaluate('Object.defineProperty(navigator,"clipboard",{configurable:true,value:{writeText:async()=>{throw new Error("NotAllowedError")}}})')
  await browser.click('.finance-revenue-copy', 'تم النسخ')
  await browser.wait('document.querySelector(".finance-copy-text textarea")')
  assert.equal(await browser.evaluate('document.querySelector(".finance-copy-text textarea").value'), approvedCopy)
  assert.equal(await browser.evaluate('document.querySelector(".finance-copy-text textarea").readOnly'), true)
  await browser.click('dialog button', 'تحديد النص')
  assert.equal(await browser.evaluate('(()=>{const t=document.querySelector(".finance-copy-text textarea");return t.selectionStart===0&&t.selectionEnd===t.value.length})()'), true)
  await browser.click('dialog button', 'إغلاق'); await browser.wait('!document.querySelector("dialog")')
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  await browser.click('nav a', 'المصروفات'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'إضافة مصروف'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelector("dialog input[inputmode=decimal]").value'), '')
  await checkAmountEditing('dialog input[inputmode=decimal]')
  await browser.input('dialog input[inputmode=decimal]', '20.25'); await browser.input('dialog textarea', 'دفعة الكهرباء'); await browser.evaluate(`(()=>{const e=document.querySelectorAll('dialog select')[1];e.value=${JSON.stringify(categories[0].uuid)};e.dispatchEvent(new Event('change',{bubbles:true}))})()`)
  await browser.click('dialog button', 'حفظ السجل'); await browser.wait('document.querySelector("dialog .danger")'); assert.equal(expenses.length, 0)
  await browser.click('dialog button', 'حفظ السجل'); await browser.wait('!document.querySelector("dialog")'); assert.equal(expenses.length, 1)
  await browser.click('tbody button', 'تعديل'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelector("dialog input[inputmode=decimal]").value'), '20.25')
  await browser.click('dialog button', 'إغلاق')
  await browser.click('nav a', 'الموردون'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'إضافة مورد'); await browser.wait('document.querySelector("dialog[open]")'); await browser.input('dialog input', 'مورد تجريبي'); await browser.click('dialog button', 'حفظ'); await browser.wait('!document.querySelector("dialog")'); assert.equal(suppliers.length, 1)
  await browser.click('nav a', 'المشتريات'); await browser.wait('document.querySelector(".empty")'); await browser.click('button', 'فاتورة مشتريات'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelector(".finance-camera-input").getAttribute("capture")'), 'environment')
  await browser.evaluate('window.financeRevokedUrls=[];const originalRevoke=URL.revokeObjectURL;URL.revokeObjectURL=url=>{window.financeRevokedUrls.push(url);originalRevoke.call(URL,url)}')
  async function chooseInvoiceFile(name, type, size = null, camera = false) {
    await browser.evaluate(`(()=>{const raw=atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aT1sAAAAASUVORK5CYII=');const bytes=Uint8Array.from(raw,c=>c.charCodeAt(0));const file=new File([${size === null ? 'bytes' : `new Uint8Array(${size})`}],${JSON.stringify(name)},{type:${JSON.stringify(type)}});const transfer=new DataTransfer();transfer.items.add(file);const input=document.querySelector(${JSON.stringify(camera ? '.finance-camera-input' : '.finance-attachment-input:not(.finance-camera-input)')});input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}))})()`)
  }
  await chooseInvoiceFile('invoice.png', 'image/png')
  await browser.wait('document.querySelector(".finance-attachment-selected img")?.naturalWidth > 0')
  const firstPreview = await browser.evaluate('document.querySelector(".finance-attachment-selected img").src')
  await chooseInvoiceFile('bad.svg', 'image/svg+xml')
  assert.equal(await browser.evaluate('document.querySelector(".finance-attachment-selected img").src'), firstPreview)
  assert.equal(await browser.evaluate('!!document.querySelector(".finance-attachment [role=alert]")'), true)
  await chooseInvoiceFile('large.png', 'image/png', 10 * 1024 * 1024 + 1)
  assert.equal(await browser.evaluate('document.querySelector(".finance-attachment [role=alert]").textContent.includes("10")'), true)
  await chooseInvoiceFile('invoice.pdf', 'application/pdf')
  assert.equal(await browser.evaluate('!!document.querySelector(".finance-attachment-pdf") && !document.querySelector(".finance-attachment-selected img")'), true)
  assert.equal(await browser.evaluate(`window.financeRevokedUrls.includes(${JSON.stringify(firstPreview)})`), true)
  await browser.click('.finance-attachment button', 'إزالة الاختيار')
  assert.equal(await browser.evaluate('!!document.querySelector(".finance-attachment-selected")'), false)
  await chooseInvoiceFile('camera-invoice.png', 'image/png', null, true)
  await browser.wait('document.querySelector(".finance-attachment-selected img")?.naturalWidth > 0')
  const cameraPreview = await browser.evaluate('document.querySelector(".finance-attachment-selected img").src')
  assert.equal(await browser.evaluate('document.querySelector("dialog input[inputmode=decimal]").value'), '')
  await checkAmountEditing('dialog input[inputmode=decimal]')
  await browser.input('dialog input[inputmode=decimal]', '30.00'); await browser.input('dialog textarea', 'فاتورة مواد'); await browser.input('dialog input[maxlength="100"]', 'INV-1'); await browser.evaluate(`(()=>{const e=document.querySelectorAll('dialog select')[1];e.value=${JSON.stringify(suppliers[0].uuid)};e.dispatchEvent(new Event('change',{bubbles:true}))})()`); await browser.click('dialog button', 'حفظ السجل'); await browser.wait('!document.querySelector("dialog")'); assert.equal(purchases.length, 1)
  assert.equal(purchases[0].attachment.name, 'camera-invoice.png')
  assert.equal(await browser.evaluate(`window.financeRevokedUrls.includes(${JSON.stringify(cameraPreview)})`), true)
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await browser.click('button', 'فاتورة مشتريات'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelectorAll("dialog select")[1].required'), false)
  assert.equal(await browser.evaluate('document.querySelectorAll("dialog select")[1].value'), '')
  await browser.input('dialog input[inputmode=decimal]', '15.50')
  await browser.input('dialog input[maxlength="100"]', 'DIRECT-1')
  await browser.input('dialog textarea', 'شراء مباشر من المتجر')
  await browser.click('dialog button', 'حفظ السجل'); await browser.wait('!document.querySelector("dialog")')
  assert.equal(purchases.length, 2)
  assert.equal(purchases[1].supplier, null)
  assert.equal(purchases[1].amount, '15.50')
  assert.match(await browser.evaluate('document.querySelector("tbody").textContent'), /شراء مباشر/)
  await browser.evaluate('[...document.querySelectorAll("tbody tr")].find(row=>row.textContent.includes("DIRECT-1")).querySelector("button").click()')
  await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelectorAll("dialog select")[1].value'), '')
  assert.equal(await browser.evaluate('document.querySelector("dialog input[inputmode=decimal]").value'), '15.50')
  await browser.click('dialog button', 'إغلاق'); await browser.wait('!document.querySelector("dialog")')
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  await browser.click('nav a', 'الإعدادات'); await browser.wait('document.querySelector(".tabs") && document.querySelector("tbody button")'); await browser.click('tbody button', 'تعديل'); await browser.wait('document.querySelector("dialog[open]")'); await browser.input('dialog input:not([type=checkbox])', 'شبكة معدّلة'); await browser.click('dialog button', 'حفظ'); await browser.wait('!document.querySelector("dialog")'); assert.equal(sources[0].name, 'شبكة معدّلة')
  assert.equal(await browser.evaluate('document.querySelector(".finance-settings-counts b").textContent'), String(sources.length))
  await browser.click('.finance-settings-tabs button', 'تصنيفات المصروفات')
  await browser.wait('document.querySelector(".finance-settings-heading h3").textContent === "تصنيفات المصروفات" && !document.querySelector(".loading")')
  assert.equal(await browser.evaluate('document.querySelector(".finance-settings-counts b").textContent'), String(categories.length))
  assert.equal(await browser.evaluate('document.querySelector(".finance-settings-tabs button[aria-pressed=true]").textContent.trim()'), 'تصنيفات المصروفات')
  await browser.click('tbody button', 'تعديل'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelector("dialog input:not([type=checkbox])").value'), categories[0].name)
  assert.equal(await browser.evaluate('!!document.querySelector(".finance-settings-availability input[type=checkbox]:checked")'), true)
  await browser.click('dialog button', 'إغلاق'); await browser.wait('!document.querySelector("dialog")')
  await browser.click('.finance-settings-tabs button', 'مصادر الإيراد')
  await browser.wait('document.querySelector(".finance-settings-heading h3").textContent === "مصادر الإيراد" && !document.querySelector(".loading")')
  await browser.click('nav a', 'المستخدمون'); await browser.wait('document.querySelector("table")'); await browser.click('button', 'إضافة مستخدم مالي'); await browser.wait('document.querySelector("dialog[open]")'); await browser.input('dialog input[maxlength="120"]', 'موظف تجريبي'); await browser.input('dialog input[type=email]', 'entry@example.test'); await browser.input('dialog input[type=password]', 'FixturePassword123'); await browser.evaluate('document.querySelector("dialog fieldset input").click()'); await browser.click('dialog button', 'حفظ المستخدم'); await browser.wait('!document.querySelector("dialog")'); assert.equal(users[0].role, 'finance_entry')
  await browser.click('nav a', 'سجل التدقيق'); await browser.wait('document.querySelector(".finance-audit-details")'); await browser.click('summary', 'عرض التفاصيل'); assert.match(await browser.evaluate('document.body.innerText'), /١٥٠٫٣٠/)
  await browser.evaluate('document.querySelectorAll(".finance-audit-details")[1].open=true')
  const auditText = await browser.evaluate('document.querySelector("tbody").innerText')
  assert.match(auditText, /شراء غاز للمحل/)
  assert.match(auditText, /نقدًا/)
  assert.match(auditText, /المبلغ/)
  assert.equal(auditText.includes(uuid(60)), false)
  assert.equal(await browser.evaluate('!!document.querySelector(".finance-audit-details pre")'), false)
  assert.doesNotMatch(auditText, /attachment_changed|created_at|"amount"|\[\]/)
  const overflowChecks = []
  const viewportCases = [
    { width: 320, height: 568 }, { width: 360, height: 740 }, { width: 390, height: 844 }, { width: 430, height: 932 },
    { width: 640, height: 360 }, { width: 844, height: 390 }, { width: 1024, height: 768 },
    { width: 1280, height: 800 }, { width: 1440, height: 900 }, { width: 1920, height: 1080 },
  ]
  for (const { width, height } of viewportCases) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width <= 1024 })
    for (const path of ['/', '/revenues', '/expenses', '/purchases', '/suppliers', '/reports', '/users', '/audit', '/settings']) {
      await browser.go(origin, path); await browser.wait('!document.querySelector(".loading")')
      if (width <= 1024) {
        const header = await browser.evaluate('(()=>{const h=document.querySelector(".topbar"),m=h.querySelector(".mobile-toggle").getBoundingClientRect(),t=h.querySelector("h1").getBoundingClientRect(),a=h.querySelector(".account").getBoundingClientRect();return {height:h.getBoundingClientRect().height,touch:m.height,noOverlap:t.left>=a.right && t.right<=m.left,inputFont:parseFloat(getComputedStyle(document.querySelector("input:not([type=checkbox]),select")||h).fontSize)}})()')
        assert.equal(header.height, 64, `${path} stable mobile header`)
        assert.equal(header.touch, 44)
        assert.equal(header.noOverlap, true, `${path} header controls do not overlap`)
        assert.ok(header.inputFont >= 16)
        if (path === '/') {
          for (const scaleFactor of [1.6, 0.6]) {
            await browser.send('Input.synthesizePinchGesture', { x: width / 2, y: 300, scaleFactor, relativeSpeed: 800, gestureSourceType: 'touch' })
            assert.equal(await browser.evaluate('window.visualViewport.scale'), 1)
          }
          await writeFile(resolve(output, `phone-header-${width}.png`), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
        }
      }
      if (await browser.evaluate('!!document.querySelector(".finance-filter-panel")')) {
        assert.equal(await browser.evaluate('document.querySelector(".finance-filter-panel").open'), width > 1024, `${path} filters default state at ${width}`)
        if (width <= 1024) {
          assert.equal(await browser.evaluate('document.querySelector(".finance-filter-panel input,.finance-filter-panel select").getBoundingClientRect().height'), 0)
          await browser.click('.finance-filter-panel>summary', '')
          await browser.wait('document.querySelector(".finance-filter-panel").open')
          assert.ok(await browser.evaluate('document.querySelector(".finance-filter-panel input,.finance-filter-panel select").getBoundingClientRect().height >= 44'))
          if (path === '/revenues' && width === 390) {
            await browser.input('.finance-filter-panel input[type=date]', '2026-11-01')
            assert.equal(await browser.evaluate('document.querySelector(".finance-filter-panel select").value'), '')
            await browser.click('.finance-filter-panel button', 'تطبيق الفلاتر')
            await browser.wait('!document.querySelector(".finance-filter-panel").open && !document.querySelector(".loading")')
            assert.equal(calls.filter(c=>c.path==='/api/v1/finance/revenues' && c.method==='GET').at(-1).query.from, '2026-11-01')
            assert.match(await browser.evaluate('document.querySelector(".finance-filter-summary").textContent'), /نوفمبر 2026/)
          } else {
            await browser.click('.finance-filter-panel>summary', '')
            await browser.wait('!document.querySelector(".finance-filter-panel").open')
          }
        }
      }
      assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `${path} at ${width}px`)
      if (path === '/reports' && width === 390) {
        await browser.evaluate('window.financeDownloads=[];document.addEventListener("click",e=>{const a=e.target.closest("a[download]");if(a)window.financeDownloads.push(a.download)},true)')
        const count = calls.filter(c => c.path.includes('/reports/export/')).length
        const point = await browser.evaluate('(()=>{const b=[...document.querySelectorAll(".page-intro button")].find(b=>b.textContent.trim()==="PDF");b.scrollIntoView({block:"center"});const r=b.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()')
        await browser.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point] })
        await browser.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
        await browser.wait('window.financeDownloads.length === 1 && ![...document.querySelectorAll(".page-intro button")].some(b=>b.disabled)')
        assert.deepEqual(calls.filter(c=>c.path.includes('/reports/export/')).slice(count).map(c=>c.path), ['/api/v1/finance/reports/export/pdf'])
        assert.deepEqual(await browser.evaluate('window.financeDownloads'), ['shishli-finance.pdf'])
      }
      const tables = await browser.evaluate('[...document.querySelectorAll("table")].map(table=>({responsive:table.classList.contains("finance-responsive-table"),rowDisplay:getComputedStyle(table.querySelector("tbody tr")).display,overflow:table.parentElement.scrollWidth>table.parentElement.clientWidth,labels:[...table.querySelectorAll("tbody td,tbody th")].every(cell=>!!cell.querySelector(".finance-cell-label"))}))')
      assert.ok(tables.length > 0, `table found on ${path}`)
      for (const table of tables) {
        assert.equal(table.rowDisplay, width <= 1024 ? table.responsive ? 'block' : 'grid' : 'table-row', `${path} card layout at ${width}`)
        if (table.responsive) assert.equal(table.labels, true, `${path} cell labels`)
        if (width <= 1024) assert.equal(table.overflow, false, `${path} has no horizontal table scrolling`)
      }
      if (width <= 1024 && path !== '/revenues') {
        const hasActions = await browser.evaluate('!!document.querySelector(".finance-cell-actions button")')
        if (hasActions) {
          assert.equal(await browser.evaluate('[...document.querySelectorAll(".finance-cell-actions button")].every(b=>b.getBoundingClientRect().height>=44)'), true)
          await browser.click('.finance-cell-actions button', '')
          await browser.wait('document.querySelector("dialog[open]")')
          await assertDialogFits(width, height)
          await browser.evaluate('document.querySelector("dialog header button").click()')
          await browser.wait('!document.querySelector("dialog")')
        }
        if (path === '/audit') {
          await browser.click('summary', 'عرض التفاصيل')
          assert.equal(await browser.evaluate('document.querySelector(".finance-audit-details").open'), true)
          assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth'), true)
        }
        await browser.evaluate('document.querySelector(".finance-responsive-table tbody tr").scrollIntoView({block:"start"})')
        await writeFile(resolve(output, `cards-${path === '/' ? 'dashboard' : path.slice(1)}-${width}.png`), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
      }
      overflowChecks.push({ path, width, height })
    }
    await browser.go(origin, '/revenues'); await browser.wait('document.querySelector("tbody tr")')
    assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".revenue-table tbody tr")).display'), width <= 1024 ? 'grid' : 'table-row')
    if (width <= 1024) {
      assert.equal(await browser.evaluate('document.querySelector(".revenue-table-wrap").scrollWidth <= document.querySelector(".revenue-table-wrap").clientWidth'), true)
      assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".revenue-mobile-label")).display'), 'block')
      await browser.click('.revenue-actions-cell button', 'عرض')
      await browser.wait('document.querySelector("dialog[open]")')
      assert.equal(await browser.evaluate('document.querySelector("dialog .amount-field input").value'), '150.10')
      await browser.click('dialog button', 'إغلاق')
      await browser.wait('!document.querySelector("dialog")')
      await browser.evaluate('document.querySelector(".revenue-table tbody tr").scrollIntoView({block:"start"})')
      await writeFile(resolve(output, `revenue-card-${width}.png`), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
    }
    await browser.evaluate('window.scrollTo(0,120)')
    const previousScroll = await browser.evaluate('window.scrollY')
    await browser.click('button', 'تسجيل يوم جديد'); await browser.wait('document.querySelector("dialog[open]")')
    await assertDialogFits(width, height)
    const before = await browser.evaluate(`(()=>{const d=document.querySelector('dialog'),h=d.querySelector('header'),f=d.querySelector('.modal-actions'),b=d.querySelector('.modal-body');return {top:h.getBoundingClientRect().top,bottom:f.getBoundingClientRect().bottom,bodyScroll:b.scrollHeight>b.clientHeight,outerScroll:d.scrollHeight>d.clientHeight+1,locked:document.body.style.position==='fixed',width:getComputedStyle(b,'::-webkit-scrollbar').width}})()`)
    assert.equal(before.locked, true)
    assert.equal(before.outerScroll, false, `no outer modal scrollbar at ${width}`)
    assert.ok(before.top >= 0 && before.bottom <= height, `fixed controls fit at ${width}`)
    assert.equal(before.width, '6px')
    await browser.evaluate('document.querySelector("dialog .modal-body").scrollTop=300')
    assert.deepEqual(await browser.evaluate('(()=>{const d=document.querySelector("dialog");return [d.querySelector("header").getBoundingClientRect().top,d.querySelector(".modal-actions").getBoundingClientRect().bottom]})()'), [before.top, before.bottom])
    await browser.send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: 2, y: 400, deltaY: 300, deltaX: 0 })
    assert.equal(await browser.evaluate('document.body.style.position'), 'fixed')
    if (width === 390) {
      await browser.evaluate('document.querySelector("dialog .amount-field input").focus()')
      await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 360, deviceScaleFactor: 1, mobile: true })
      await assertDialogFits(390, 360)
      await browser.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: true })
      await assertDialogFits(width, height)
    }
    await browser.click('dialog button', 'إغلاق'); await browser.wait('!document.querySelector("dialog")')
    assert.equal(await browser.evaluate('window.scrollY'), previousScroll)
    assert.equal(await browser.evaluate('document.body.style.position'), '')
  }
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await browser.click('.mobile-toggle', '')
  await browser.wait('document.querySelector("aside.open")')
  assert.equal(await browser.evaluate('document.querySelector("aside").getBoundingClientRect().bottom <= innerHeight'), true)
  await writeFile(resolve(output, 'sidebar-mobile.png'), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
  await browser.click('.finance-sidebar-close', '')
  await browser.wait('!document.querySelector("aside.open")')
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("aside")).visibility'), 'hidden')
  await browser.click('.mobile-toggle', '')
  await browser.wait('document.querySelector("aside.open") && document.querySelector(".finance-sidebar-backdrop")')
  await browser.click('.finance-sidebar-backdrop', '')
  await browser.wait('!document.querySelector("aside.open")')
  entry = true
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await browser.go(origin, '/revenues'); await browser.wait('document.querySelector("tbody tr")')
  assert.equal(await browser.evaluate('[...document.querySelectorAll("tbody button")].some(e => /اعتماد|تصحيح/.test(e.textContent))'), false)
  assert.equal(await browser.evaluate('[...document.querySelectorAll("nav a")].some(a => a.getAttribute("href") === "/users")'), false)
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth'), true)
  assert.equal(await browser.evaluate('(()=>{const t=document.querySelector(".revenue-table-wrap");return t.scrollWidth<=t.clientWidth})()'), true)
  assert.equal(await browser.evaluate('[...document.querySelectorAll(".revenue-actions-cell button")].every(b=>b.getBoundingClientRect().height>=44)'), true)
  await browser.click('button', 'تسجيل يوم جديد'); await browser.wait('document.querySelector("dialog[open]")')
  assert.equal(await browser.evaluate('document.querySelector("dialog").scrollWidth <= document.querySelector("dialog").clientWidth'), true)
  assert.equal(await browser.evaluate('(()=>{const d=document.querySelector("dialog .modal-body");d.scrollTop=100;return d.scrollHeight>d.clientHeight && d.scrollTop>0})()'), true)
  await writeFile(resolve(output, 'revenues-mobile.png'), Buffer.from((await browser.send('Page.captureScreenshot', { format: 'png' })).data, 'base64'))
  await browser.click('dialog button', 'إغلاق')
  assert.equal(errors.length, 0, JSON.stringify(errors)); assert.equal(browser.diagnostics.length, 0, JSON.stringify(browser.diagnostics))
  assert.ok(calls.some(c => c.path.endsWith('/export/pdf'))); assert.ok(calls.some(c => c.path.endsWith('/export/xlsx')))
  console.log(JSON.stringify({ passed: true, checks: ['login', 'dashboard', 'revenue draft', 'approval', 'documented correction', '422 validation', 'expense', 'supplier', 'purchase', 'settings', 'financial user', 'audit', 'export', 'entry permissions', 'mobile overflow', 'empty and zero amounts', '5/15/150/150.50/0.75 keyboard entry', 'existing amount retained', 'clear and retype', 'caret position', '6px scrollbars', 'sidebar and table and modal scrolling'], overflowChecks, transport: 'isolated fixtures; Laravel API independently tested', screenshots: ['dashboard-desktop.png', 'revenues-mobile.png'] }))
} finally { browser?.close(); await new Promise(done => server.close(done)) }
