// Run with Vite and Laravel already running:
// node --experimental-websocket tests/auth.browser.mjs
// Staff/error fixtures below are test transport only, never application auth.
import { spawn } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { randomBytes } from 'node:crypto'
import assert from 'node:assert/strict'
const output = fileURLToPath(new URL('../.auth-check/', import.meta.url))
await mkdir(output, { recursive: true })
const browser = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=9241', `--user-data-dir=${output}/profile`, 'about:blank'], { windowsHide: true, stdio: 'ignore' })
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
let socket
const report = { live: [], simulated: [], responsive: [], diagnostics: [] }
const skipLive = process.env.AUTH_SKIP_LIVE === '1'
try {
  let pages
  for (let i = 0; i < 100; i++) {
    try { pages = await (await fetch('http://127.0.0.1:9241/json')).json(); if (pages.length) break } catch {}
    await sleep(100)
  }
  socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl)
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }))
  let sequence = 0, mode = skipLive ? 'mock' : 'live', staff = true, limited = false, authFailure = null, hold = false, heldRequest, loginSequence = 0, shortSession = false
  const pending = new Map(), requests = []
  const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })) })
  const payload = (data, status = 200, errors) => status < 400 ? { success: true, message: 'Success', data, errors: null } : { success: false, message: status === 422 ? 'البيانات المدخلة غير صحيحة' : 'تعذر تنفيذ الطلب', ...(errors ? { errors } : {}) }
  async function fulfill(id, status, body) {
    await send('Fetch.fulfillRequest', { requestId: id, responseCode: status, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: '*' }, { name: 'Access-Control-Allow-Headers', value: 'Authorization,Content-Type' }, { name: 'Access-Control-Allow-Methods', value: 'GET,POST,PATCH,OPTIONS' }], body: Buffer.from(JSON.stringify(body)).toString('base64') })
  }
  const permissions = ['dashboard.view', 'orders.view', 'products.view', 'categories.view', 'promotions.view', 'customers.view', 'settings.view', 'settings.update', 'staff.view', 'staff.create', 'staff.update']
  const customerCandidate = { id: '77777777-7777-4777-8777-777777777777', name: 'حساب العميل التجريبي', phone: '+966551234567' }, staffRows = []
  let rejectPromotion = true, promotionCount = 0, lookupPhone
  const settingValues = { 'restaurant.timezone': 'Asia/Riyadh', 'orders.enabled': true, 'orders.maintenance_mode': false, 'orders.expected_resume_at': null }
  let lastSettingsPatch
  const customer = { uuid: 'test-user-uuid', name: 'مستخدم الاختبار', email: 'fixture@example.invalid', phone: null, email_verified_at: null, created_at: null, updated_at: null }
  const admin = () => ({ id: customer.uuid, name: customer.name, email: customer.email, phone: null, is_active: true, roles: limited ? ['kitchen'] : ['manager'], permissions: limited ? ['orders.view'] : permissions, capabilities: {} })
  socket.addEventListener('message', async ({ data }) => {
    const message = JSON.parse(data)
    if (message.id) { const call = pending.get(message.id); if(!call)return; pending.delete(message.id); message.error ? call.reject(message.error) : call.resolve(message.result); return }
    if (message.method === 'Runtime.exceptionThrown') report.diagnostics.push(message.params.exceptionDetails.text)
    if (message.method === 'Runtime.consoleAPICalled' && ['warning', 'error'].includes(message.params.type)) report.diagnostics.push(message.params.args.map(arg => arg.value ?? arg.description).join(' '))
    if (message.method !== 'Fetch.requestPaused') return
    const { requestId, request } = message.params, path = new URL(request.url).pathname
    requests.push({ path, method: request.method, bearer: Boolean(request.headers.Authorization), keys: request.postData ? Object.keys(JSON.parse(request.postData)) : [], phone: mode === 'mock' && request.postData ? JSON.parse(request.postData).phone : undefined })
    if (mode === 'live') { await send('Fetch.continueRequest', { requestId }); return }
    if (request.method === 'OPTIONS') { await fulfill(requestId, 200, {}); return }
    if (path.endsWith('/admin/staff/customer-lookup')) {
      lookupPhone = JSON.parse(request.postData).phone
      await fulfill(requestId, lookupPhone === customerCandidate.phone ? 200 : 404, payload(lookupPhone === customerCandidate.phone ? customerCandidate : null, lookupPhone === customerCandidate.phone ? 200 : 404)); return
    }
    if (path.endsWith('/admin/staff/from-customer')) {
      promotionCount++
      if (rejectPromotion) { await fulfill(requestId, 422, payload(null, 422, { password: ['راجع كلمة المرور الإدارية الجديدة.'] })); return }
      const body = JSON.parse(request.postData)
      assert.equal(body.customer_uuid, customerCandidate.id)
      assert.deepEqual(body.roles, ['cashier']); assert.equal(body.confirmed, true)
      staffRows.push({ ...customerCandidate, roles: ['cashier', 'customer'], permissions: ['orders.view'], is_active: true })
      await fulfill(requestId, 200, payload(staffRows[0])); return
    }
    if (path.endsWith('/admin/staff')) {
      if (request.method === 'POST') { await fulfill(requestId, 422, payload(null, 422, { phone: ['validation.unique', 'validation.unique'], password: ['validation.min.string'] })); return }
      await fulfill(requestId, 200, { ...payload(staffRows), meta: { current_page: 1, last_page: 1, per_page: 20, total: staffRows.length }, links: {} }); return
    }
    if (path.endsWith('/admin/staff/' + customerCandidate.id)) { await fulfill(requestId, 200, payload(staffRows[0])); return }
    if (path.endsWith('/settings/public')) {
      const orders = { enabled: settingValues['orders.enabled'], maintenance_mode: settingValues['orders.maintenance_mode'], allow_guest_checkout: true, allow_scheduled_orders: false }
      if ((!orders.enabled || orders.maintenance_mode) && Date.parse(settingValues['orders.expected_resume_at']) > Date.now()) orders.expected_resume_at = settingValues['orders.expected_resume_at']
      await fulfill(requestId, 200, payload({ restaurant: { timezone: 'Asia/Riyadh', currency: 'SAR' }, orders, pickup: { enabled: true }, delivery: { enabled: true } })); return
    }
    if (path.endsWith('/admin/settings')) {
      if (request.method === 'PATCH') {
        lastSettingsPatch = JSON.parse(request.postData).settings
        Object.assign(settingValues, lastSettingsPatch)
        if (lastSettingsPatch['orders.expected_resume_at']) settingValues['orders.expected_resume_at'] = new Date(lastSettingsPatch['orders.expected_resume_at'] + ':00+03:00').toISOString()
        if (settingValues['orders.enabled'] && !settingValues['orders.maintenance_mode']) settingValues['orders.expected_resume_at'] = null
        await fulfill(requestId, 200, payload(null)); return
      }
      const rows = Object.entries(settingValues).map(([key, value]) => ({ key, value, type: typeof value === 'boolean' ? 'boolean' : 'string', group: key.startsWith('restaurant.') ? 'general' : 'orders' }))
      await fulfill(requestId, 200, payload(rows)); return
    }
    if (path.endsWith('/protected-test') && hold) { heldRequest = requestId; return }
    if (path.endsWith('/login') && authFailure) {
      if (authFailure === 'network') await send('Fetch.failRequest', { requestId, errorReason: 'Failed' })
      else await fulfill(requestId, authFailure, payload(null, authFailure, authFailure === 422 ? { phone: ['خطأ الجوال من Laravel'] } : undefined))
      return
    }
    if (path.endsWith('/user') && authFailure === 'expired') { await fulfill(requestId, 401, payload(null, 401)); return }
    if (path.endsWith('/login')) await fulfill(requestId, 200, payload({ user: customer, token: `synthetic-test-token-${++loginSequence}`, token_type: 'Bearer', expires_at: new Date(Date.now() + (shortSession ? 5000 : staff ? 8 * 3600000 : 7 * 86400000)).toISOString(), session_only: staff }))
    else if (path.endsWith('/register')) await fulfill(requestId, 201, payload(customer))
    else if (path.endsWith('/user')) await fulfill(requestId, 200, payload(customer))
    else if (path.endsWith('/admin/me')) await fulfill(requestId, staff ? 200 : 403, payload(staff ? admin() : null, staff ? 200 : 403))
    else if (path.endsWith('/logout')) await fulfill(requestId, 200, payload(null))
    else if(path.endsWith('/protected-test'))await fulfill(requestId,401,payload(null,401))
    else await fulfill(requestId,404,payload(null,404))
  })
  const evaluate = async expression => {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text)
    return result.result.value
  }
  const waitFor = async expression => {
    for (let i = 0; i < 180; i++) { if (await evaluate(expression)) return; await sleep(100) }
    throw new Error(`Timed out: ${expression}`)
  }
  const navigate = async path => {
    await send('Page.navigate', { url: (process.env.PUBLIC_UI_ORIGIN||'http://127.0.0.1:5173') + path }); await sleep(150)
    await waitFor(`document.readyState === 'complete' && Boolean(document.querySelector('main'))`)
    await evaluate(`import('/src/stores/auth.js').then(module => {window.__authTest = module.useAuthStore(); window.__routerTest = document.querySelector('#app').__vue_app__.config.globalProperties.$router; return true})`)
    await evaluate('document.fonts.ready.then(() => true)')
  }
  const input = (id, value) => evaluate(`(() => {const element = document.getElementById(${JSON.stringify(id)}); element.value = ${JSON.stringify(value)}; element.dispatchEvent(new Event('input',{bubbles:true})); return true})()`)
  const submit = id => evaluate(`document.getElementById(${JSON.stringify(id)}).requestSubmit()`)
  const push = path => evaluate(`window.__routerTest.push(${JSON.stringify(path)}).then(() => true)`)
  const capture = async name => { const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); await writeFile(`${output}/${name}.png`, Buffer.from(data, 'base64')) }
  const login = async (identity = 'fixture@example.invalid', password = 'test-password-only') => {
    await input('login-phone', identity); await input('login-password', password); await submit('login-form'); await waitFor(`!window.__authTest.isLoading&&(!document.querySelector('#login-form')||!document.querySelector('#login-form button[type=submit]').disabled)`);if(await evaluate('window.__authTest.isAuthenticated'))await waitFor('location.pathname!=="/login"')
  }
  await send('Page.enable'); await send('Runtime.enable')
  await send('Fetch.enable', { patterns: [{ urlPattern: '*:8000/api/*', requestStage: 'Request' }] })
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] })
  await navigate('/admin/orders')
  assert.equal(await evaluate('location.pathname'), '/login')
  assert.equal(await evaluate(`new URLSearchParams(location.search).get('redirect')`), '/admin/orders')
  report.live.push('Guest admin intended-route redirect')
  if (!skipLive) {
  await push('/register')
  const email = `vue-auth-check-${Date.now()}@example.invalid`, password = randomBytes(18).toString('hex')
  await input('register-name', 'اختبار تكامل Vue'); await input('register-email', email)
  await input('login-password', password); await input('confirm-password', password); await submit('register-form')
  await waitFor('!window.__authTest.isLoading')
  const registered = await evaluate(`window.__authTest.isAuthenticated || document.getElementById('auth-status')?.textContent.includes('تم إنشاء حسابك')`)
  if (registered) {
    report.live.push('Register 201 with automatic login, or a login fallback after account creation')
    assert.ok(requests.find(item => item.path.endsWith('/register')).keys.every(key => !['role', 'roles', 'permissions'].includes(key)))
    if (!(await evaluate('window.__authTest.isAuthenticated'))) { await push('/login'); await login(email, password) }
    assert.equal(await evaluate('window.__authTest.isAuthenticated'), true)
    assert.equal(await evaluate('window.__authTest.hasAdminAccess'), false)
    report.live.push('Login, GET /api/user and real customer /admin/me 403')
    await navigate('/'); assert.equal(await evaluate('window.__authTest.isAuthenticated'), true)
    report.live.push('Refresh restores valid customer session')
    await push('/admin/settings'); assert.equal(await evaluate('location.pathname'), '/forbidden')
    report.live.push('Authenticated customer denied direct admin URL')
    await evaluate('window.__authTest.logout().then(() => true)')
    assert.equal(await evaluate(`localStorage.getItem('shishli_auth_token') === null`), true)
    report.live.push('Logout revokes current token and clears credentials')
    await push('/login'); await login(email, 'incorrect-test-password')
    assert.equal(await evaluate('location.pathname'), '/login')
    assert.equal(await evaluate('window.__authTest.isAuthenticated'), false)
    report.live.push('Invalid credentials 401 leaves Login in place')
    await evaluate(`import('/src/api/auth.api.js').then(async ({authApi})=>{try {await authApi.register({})} catch(error) {return error.response?.status===422}})`).then(value => assert.equal(value, true))
    report.live.push('Real Laravel 422 wrapper')
  } else report.live.push('Register success unavailable; database/API error prevented live authenticated cycle')
  await evaluate(`localStorage.setItem('shishli_auth_token','invalid-test-token')`)
  await navigate('/admin'); assert.equal(await evaluate('location.pathname'), '/login')
  assert.equal(await evaluate(`localStorage.getItem('shishli_auth_token') === null`), true)
  report.live.push('Real invalid token /api/user 401 clears credentials')
  }

  mode = 'mock'
  await evaluate(`localStorage.setItem('shishli.cart.v1', '[]')`)
  await navigate('/admin/orders'); await login('٠٥٥١٢٣٤٥٦٧')
  assert.equal(requests.filter(item => item.path.endsWith('/login') && item.method === 'POST').at(-1).phone, '+966551234567')
  assert.equal(await evaluate('location.pathname'), '/admin/orders')
  assert.equal(await evaluate("localStorage.getItem('shishli_auth_token')"), null)
  assert.ok(await evaluate("sessionStorage.getItem('shishli_auth_token')"))
  await evaluate("localStorage.setItem('shishli_auth_token',sessionStorage.getItem('shishli_auth_token'));sessionStorage.removeItem('shishli_auth_token')")
  await navigate('/admin/orders')
  assert.equal(await evaluate("localStorage.getItem('shishli_auth_token')"), null)
  assert.ok(await evaluate("sessionStorage.getItem('shishli_auth_token')"))
  await evaluate('sessionStorage.clear()')
  await navigate('/admin/orders'); assert.equal(await evaluate('location.pathname'), '/login')
  await login()
  report.simulated.push('Admin tokens use session storage, legacy tokens migrate, and closing the browser session requires login')
  assert.deepEqual(await evaluate(`[...document.querySelectorAll('.sidebar nav a')].map(a=>a.getAttribute('href')).sort()`),['/admin','/admin/orders','/admin/kitchen','/admin/products','/admin/categories','/admin/offers','/admin/customers','/admin/settings','/admin/staff'].sort())
  assert.ok(await evaluate(`document.querySelector('.admin').textContent.includes('مستخدم الاختبار')`))
  report.simulated.push('Staff login, real header binding, eight permitted links including kitchen, intended Orders route')
  authFailure = 401
  await evaluate(`window.__authTest.login({email:'fixture@example.invalid',password:'incorrect-test-password'}).catch(()=>false)`)
  assert.equal(await evaluate('window.__authTest.isAuthenticated'), true)
  assert.equal(await evaluate('location.pathname'), '/admin/orders')
  authFailure = null
  report.simulated.push('Public login 401 does not invalidate an existing verified session')
  const before = requests.filter(item => item.path === '/api/user').length
  await evaluate('Promise.all([window.__authTest.initialize(),window.__authTest.initialize()]).then(()=>true)')
  assert.equal(requests.filter(item => item.path === '/api/user').length, before)
  await navigate('/admin/orders'); assert.equal(await evaluate('location.pathname'), '/admin/orders')
  assert.equal(requests.filter(item => item.path === '/api/user').length, before + 1)
  report.simulated.push('Bootstrap deduplication and authorized refresh')
  await navigate('/admin/settings')
  await evaluate('[...document.querySelectorAll(".settings-tabs button")].find(b=>b.textContent==="استقبال الطلبات").click()')
  await waitFor('document.getElementsByName("orders.enabled")[0]')
  assert.equal(await evaluate('!!document.getElementsByName("orders.expected_resume_at")[0]'), false)
  await evaluate('document.getElementsByName("orders.enabled")[0].click()')
  await waitFor('document.getElementsByName("orders.expected_resume_at")[0]')
  const expectedInput = await evaluate('import("/src/utils/formDates.js").then(({zonedDateTimeInput})=>zonedDateTimeInput(new Date(Date.now()+86400000).toISOString(),"Asia/Riyadh"))')
  await evaluate(`(()=>{const e=document.querySelector('input[type="datetime-local"]');e.value=${JSON.stringify(expectedInput)};e.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('#settings-form').requestSubmit()})()`)
  await waitFor('document.querySelector("dialog[open]")')
  await evaluate('document.querySelector("dialog[open] button[type=submit]").click()')
  await waitFor('!document.querySelector("dialog[open]") && document.querySelector(".settings-save").textContent.includes("حفظ جميع التغييرات") && document.querySelector(".settings-save").disabled')
  assert.equal(lastSettingsPatch['orders.enabled'], false)
  assert.equal(lastSettingsPatch['orders.expected_resume_at'], expectedInput)
  await navigate('/')
  await waitFor('document.querySelector(".ordering-pause-notice")?.textContent.includes("سنعاود استقبال الطلبات")')
  for (const width of [320, 390, 1440]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 800 })
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true)
    await capture(`ordering-resume-${width}`)
  }
  settingValues['orders.expected_resume_at'] = new Date(Date.now()-60000).toISOString()
  await navigate('/menu'); await waitFor('document.querySelector(".ordering-pause-notice")')
  assert.equal(await evaluate('document.querySelector(".ordering-pause-notice").textContent.includes("سنعاود استقبال الطلبات")'), false)
  await navigate('/admin/settings')
  await evaluate('[...document.querySelectorAll(".settings-tabs button")].find(b=>b.textContent==="استقبال الطلبات").click()')
  await waitFor('document.getElementsByName("orders.enabled")[0]')
  await evaluate('document.getElementsByName("orders.enabled")[0].click()')
  await waitFor('!document.getElementsByName("orders.expected_resume_at")[0]')
  await evaluate('document.querySelector("#settings-form").requestSubmit()')
  await waitFor('document.querySelector(".settings-save").disabled && !document.querySelector(".settings-page").textContent.includes("جارٍ الحفظ")')
  await navigate('/'); assert.equal(await evaluate('!!document.querySelector(".ordering-pause-notice")'), false)
  report.simulated.push('Global pause captures a restaurant-local resume estimate, shows it at 320/390/1440px, hides expired promises, and removes the notice after reopening')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  await navigate('/admin/staff')
  await evaluate('[...document.querySelectorAll(".page-heading button")].find(b=>b.textContent==="إضافة موظف").click()')
  await waitFor('document.querySelector("dialog[open] input[type=tel]")')
  await evaluate('(()=>{const e=document.querySelector("dialog[open] input[type=tel]");e.value="0551234567";e.dispatchEvent(new Event("input",{bubbles:true}))})()')
  await evaluate('document.querySelector("dialog[open] form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
  await waitFor('document.querySelector("dialog[open] .admin-feedback-error")')
  assert.equal(await evaluate('document.querySelector("dialog[open]").textContent.includes("validation.")'), false)
  assert.deepEqual(await evaluate('[...document.querySelectorAll("dialog[open] .admin-feedback-error li")].map(e=>e.textContent)'), ['رقم الجوال مستخدم بالفعل.', 'كلمة المرور أقصر من الحد المطلوب. راجع الحد الأدنى الموضّح بجانب الحقل.'])
  report.simulated.push('Raw duplicate validation.unique and validation.min.string render as distinct readable Arabic field errors without technical keys')
  await evaluate('[...document.querySelectorAll("dialog[open] button")].find(b=>b.textContent.includes("الرقم مسجّل مسبقًا")).click()')
  await waitFor('document.querySelector("dialog[open] h2")?.textContent==="إضافة موظف من حساب عميل موجود"')
  assert.equal(await evaluate('document.querySelector("dialog[open] input[type=tel]").value'), '0551234567')
  await evaluate('document.querySelector("dialog[open] form").requestSubmit()')
  await waitFor('document.querySelector(".staff-existing-customer")')
  assert.equal(lookupPhone, '+966551234567')
  assert.equal(await evaluate('document.querySelector(".staff-existing-customer strong").textContent'), customerCandidate.name)
  assert.equal(await evaluate('!!document.querySelector("dialog[open] input[value=finance_manager]")'), false)
  assert.equal(await evaluate('document.querySelector("dialog[open] button[type=submit]").disabled'), true)
  await evaluate('document.querySelector("dialog[open] input[value=cashier]").click();const e=document.querySelector("dialog[open] input[type=password]");e.value="BrowserStaff123";e.dispatchEvent(new Event("input",{bubbles:true}));document.querySelector("dialog[open] input[required][type=checkbox]").click()')
  for (const width of [320, 390, 1440]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 800 })
    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'), true)
    assert.equal(await evaluate('(()=>{const d=document.querySelector("dialog[open]"),r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&d.scrollWidth<=d.clientWidth+1})()'), true)
    await capture(`existing-customer-staff-${width}`)
  }
  await evaluate('document.querySelector("dialog[open] form").requestSubmit()')
  await waitFor('document.querySelector("dialog[open]")?.textContent.includes("راجع كلمة المرور الإدارية الجديدة")')
  assert.equal(staffRows.length, 0)
  rejectPromotion = false
  await evaluate('const f=document.querySelector("dialog[open] form");f.requestSubmit();f.requestSubmit()')
  await waitFor('!document.querySelector("dialog[open]") && document.querySelector("tbody")?.textContent.includes("حساب العميل التجريبي")')
  assert.equal(promotionCount, 2)
  assert.equal(await evaluate('!!document.querySelector(".admin-feedback-error")'), false)
  await evaluate('[...document.querySelectorAll("tbody button")].find(b=>b.textContent==="تعديل").click()')
  await waitFor('document.querySelector("dialog[open]")')
  assert.deepEqual(await evaluate('[...document.querySelectorAll("dialog[open] .domain-checks input:checked")].map(e=>e.value)'), ['cashier'])
  await evaluate('document.querySelector("dialog[open] .dialog-head button").click()')
  await waitFor('!document.querySelector("dialog[open]")')
  report.simulated.push('Existing customer phone prefills from staff creation; identity preview, explicit confirmation, 422 recovery, single submission, responsive modal, and customer role preservation are verified')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1050, deviceScaleFactor: 1, mobile: false })
  for (const [width, height] of [[1440,1100], [768,1024], [390,844]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false })
    for (const path of ['/admin','/admin/orders','/admin/products','/admin/categories','/admin/offers','/admin/customers','/admin/settings','/','/menu','/product/turkish-chicken','/cart','/checkout','/order-success','/track-order','/about','/gallery','/contact']) {
      await push(path)
      assert.ok(await evaluate(`Boolean(document.querySelector('main')) && document.documentElement.scrollWidth <= innerWidth`))
      assert.equal(await evaluate(`Boolean(document.querySelector('.admin-layout'))`), path.startsWith('/admin'))
    }
    await push('/admin/settings'); await capture(`settings-${width}`)
    report.responsive.push(`${width}px: all authorized Admin and public Site routes rendered without page overflow`)
    console.log(`Responsive auth regression passed: ${width}px`)
  }
  await evaluate('window.__authTest.logout().then(()=>true)'); limited = true
  await navigate('/login?redirect=/admin/settings'); await login()
  assert.equal(await evaluate('location.pathname'), '/admin/orders')
  assert.deepEqual(await evaluate(`[...document.querySelectorAll('.sidebar nav a')].map(a=>a.getAttribute('href')).sort()`),['/admin/orders','/admin/kitchen'].sort())
  await push('/admin/settings'); assert.equal(await evaluate('location.pathname'), '/forbidden')
  await push('/admin'); assert.equal(await evaluate('location.pathname'), '/forbidden')
  assert.equal(await evaluate('window.__authTest.isAuthenticated'), true)
  report.simulated.push('Kitchen fallback to permitted Orders; Settings/Dashboard denied without logout')
  await evaluate('window.__authTest.logout().then(()=>true)'); limited = false; staff = false
  await navigate('/login'); await login(); await push('/admin'); assert.equal(await evaluate('location.pathname'), '/forbidden')
  report.simulated.push('Customer admin 403 is not a session expiry')
  assert.ok(await evaluate("localStorage.getItem('shishli_auth_token')"))
  assert.equal(await evaluate("sessionStorage.getItem('shishli_auth_token')"), null)
  await evaluate('sessionStorage.clear()'); await navigate('/menu')
  assert.equal(await evaluate('window.__authTest.isAuthenticated'), true)
  report.simulated.push('Customer login persists across browser sessions with a stored seven-day expiration')
  await evaluate('window.__authTest.logout().then(()=>true)'); staff = true
  for (const failure of [401, 403, 422, 429, 500, 'network']) {
    authFailure = failure; await navigate('/login'); await login()
    assert.equal(await evaluate('location.pathname'), '/login')
    assert.ok(await evaluate(`Boolean(document.querySelector('[role="alert"]'))`))
    assert.equal(await evaluate(`document.getElementById('login-password').value`), '')
    if (failure === 422) assert.equal(await evaluate(`document.getElementById('phone-error').textContent`), 'خطأ الجوال من Laravel')
  }
  report.simulated.push('401/403/422/429/500/network form errors; no failed-login redirects; passwords cleared')
  authFailure = null; await navigate('/login')
  await input('login-phone', 'fixture@example.invalid'); await input('login-password', 'test-password-only')
  const loginCount = requests.filter(item => item.path.endsWith('/login') && item.method === 'POST').length
  await evaluate(`document.getElementById('login-form').requestSubmit(); document.getElementById('login-form').requestSubmit()`)
  await waitFor('!window.__authTest.isLoading')
  assert.equal(requests.filter(item => item.path.endsWith('/login') && item.method === 'POST').length, loginCount + 1)
  await evaluate('window.__authTest.logout().then(()=>true)')
  report.simulated.push('Double submit sends exactly one Login request')
  authFailure = null; await navigate('/login'); await login()
  await push('/admin'); hold = true
  await evaluate(`import('/src/api/http.js').then(({http})=>{window.__delayedTest = http.get('/protected-test').catch(()=>false);return true})`)
  for (let i=0;i<50 && !heldRequest;i++) await sleep(100)
  assert.ok(heldRequest)
  await evaluate(`window.__authTest.login({email:'fixture@example.invalid',password:'test-password-only'}).then(()=>true)`)
  await fulfill(heldRequest, 401, payload(null,401)); await evaluate('window.__delayedTest')
  assert.equal(await evaluate('window.__authTest.isAuthenticated'), true)
  report.simulated.push('Stale 401 cannot invalidate a newer session')
  hold = false; authFailure = 'expired'
  await evaluate('window.__authTest.fetchMe().catch(()=>false)'); await waitFor(`location.pathname === '/login'`)
  assert.equal(await evaluate(`localStorage.getItem('shishli_auth_token') === null`), true)
  assert.equal(await evaluate(`localStorage.getItem('shishli.cart.v1')`), null)
  report.simulated.push('Protected 401 clears credentials and redirects once; obsolete demo cart storage stays removed')
  authFailure = null
  shortSession = true; staff = true
  await navigate('/login'); await login()
  await waitFor('location.pathname === "/login" && !window.__authTest.token')
  assert.equal(await evaluate("sessionStorage.getItem('shishli_auth_token')"), null)
  report.simulated.push('Absolute session deadline clears an idle admin session without waiting for an API request')
  shortSession = false
  await login()
  await evaluate('window.__authTest.expiresAt=new Date(Date.now()-1000).toISOString();window.dispatchEvent(new Event("pageshow"))')
  await waitFor('location.pathname === "/login" && !window.__authTest.token')
  report.simulated.push('Restoring a sleeping page checks the deadline immediately')
  for (const path of ['/login', '/register']) {
    await push(path); await capture(path.slice(1) + '-390')
    assert.ok(await evaluate(`document.documentElement.scrollWidth <= innerWidth`))
  }
  assert.ok(requests.every(item => !item.path.includes('csrf-cookie')))
  assert.ok(requests.filter(item => ['/api/v1/login','/api/v1/register'].includes(item.path)).every(item => !item.bearer))
  assert.ok(requests.filter(item=>['/api/v1/login','/api/v1/register'].includes(item.path)).every(item=>item.method==='POST'||item.method==='OPTIONS'))
  assert.deepEqual(report.diagnostics, [])
  await writeFile(`${output}/results.json`, JSON.stringify(report, null, 2))
  console.log(JSON.stringify(report, null, 2))
} catch(error){if(socket){const result=await new Promise(resolve=>{const id=987654;socket.addEventListener('message',function listener({data}){const message=JSON.parse(data);if(message.id===id){socket.removeEventListener('message',listener);resolve(message.result?.result?.value)}});socket.send(JSON.stringify({id,method:'Runtime.evaluate',params:{expression:'({path:location.pathname,text:document.querySelector("main")?.innerText,authenticated:window.__authTest?.isAuthenticated,loading:window.__authTest?.isLoading})',returnByValue:true}}))});await writeFile(output+'/failure-state.json',JSON.stringify(result,null,2))}throw error} finally { socket?.close(); browser.kill() }


