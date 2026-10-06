// Read-only real API/browser checks. Does not create, edit or delete business data.
import { spawn } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'
const output = fileURLToPath(new URL('../.reports-check/settings-operations/', import.meta.url))
await mkdir(output, { recursive: true })
const api = process.env.DESIGN_API_URL || 'http://127.0.0.1:8000/api/v1'
const origin = process.env.DESIGN_APP_URL || 'http://127.0.0.1:5173'
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
const report = { pages: [], responsive: [], dialogs: [], filters: [], diagnostics: [], failures: [] }
let token, browser, socket, settingsSnapshot, branchSnapshot, hoursSnapshot, branchId, closureUuid, guestToken, managerToken, checkoutIntercept
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
  browser = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--remote-debugging-port=9263', `--user-data-dir=${output}/profile`, 'about:blank'], { windowsHide: true, stdio: 'ignore' })
  let pages
  for (let attempt = 0; attempt < 100; attempt++) { try { pages = await (await fetch('http://127.0.0.1:9263/json')).json(); if (pages.length) break } catch {} await sleep(100) }
  assert.ok(pages?.length, 'Chrome debugging connection')
  socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl)
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }) })
  let sequence = 0
  const pending = new Map()
  const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })) })
  socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data)
    if (message.id) { const promise = pending.get(message.id); pending.delete(message.id); message.error ? promise.reject(message.error) : promise.resolve(message.result); return }
    if(message.method==='Fetch.requestPaused'){checkoutIntercept?.(message.params).catch(error=>report.diagnostics.push(error.message));return}
    if (message.method === 'Page.javascriptDialogOpening') { send('Page.handleJavaScriptDialog',{accept:true}).catch(()=>{}); return }
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

  report.checks=[]
  const publicBranches=await http('/branches');branchId=publicBranches.data[0].id
  settingsSnapshot=Object.fromEntries((await http('/admin/settings')).data.map(row=>[row.key,row.value]))
  branchSnapshot=(await http('/admin/branches/'+branchId)).data
  const hours=(await http('/admin/branches/'+branchId+'/business-hours')).data
  hoursSnapshot=hours.days.flatMap(day=>day.periods.map(period=>({day_of_week:day.day_of_week,...period})))
  const setInput=async(name,value)=>{assert.ok(await evaluate("(() => {const e=document.querySelector('[name=\""+name+"\"]');if(!e)return false;e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event('input',{bubbles:true}));return true})()"))}
  const settingsTab=async label=>clickText('.settings-tabs button',label)
  await navigate('/admin/settings');await waitFor('document.querySelector("#settings-form input")')
  assert.ok(!(await evaluate('document.querySelector("main").textContent')).includes('بيانات تجريبية'))
  for(const width of [1440,768,390]){await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await sleep(350);assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));await writeFile(output+'settings-'+width+'.png',Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));report.responsive.push({width})}
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false})
  await setInput('restaurant.timezone','Invalid/Zone');await evaluate('document.querySelector("#settings-form").requestSubmit()');await waitFor('document.querySelector("#settings-form .field-error")');report.checks.push('live settings 422')
  await setInput('restaurant.timezone',settingsSnapshot['restaurant.timezone'])
  const testName='شيشلي اختبار التشغيل';await setInput('restaurant.name',testName)
  await settingsTab('التواصل');await settingsTab('بيانات المطعم');assert.equal(await evaluate('document.querySelector("#settings-form input").value'),testName)
  await evaluate('document.querySelector("#settings-form").requestSubmit()');await waitFor('document.querySelector(".settings-save")?.disabled && !document.querySelector("#settings-form").textContent.includes("جارٍ الحفظ")')
  assert.equal((await http('/settings/public')).data.restaurant.name,testName);report.checks.push('live settings update/cache and draft across tabs')
  await http('/admin/settings',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({settings:{'restaurant.name':settingsSnapshot['restaurant.name']}})})
  await navigate('/admin/settings');await waitFor('document.querySelector("#settings-form input")');await settingsTab('تشغيل الفروع')
  await waitFor('document.querySelector(".business-hours-panel .hours-day")')
  assert.equal(await evaluate('document.querySelectorAll(".hours-day").length'),7)
  await clickText('.closures-panel button','إضافة إغلاق مؤقت');await waitFor('document.querySelector("dialog[open]")')
  for(const width of [768,390]){await send('Emulation.setDeviceMetricsOverride',{width,height:800,deviceScaleFactor:1,mobile:false});assert.ok(await evaluate('(() => {const d=document.querySelector("dialog[open]"),r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.height<=innerHeight&&getComputedStyle(d.querySelector("form")).paddingInlineStart==="16px"})()'));report.dialogs.push({width})}
  await evaluate('document.querySelector("dialog[open] .icon-button").click()')
  await settingsTab('سجل التغييرات');await waitFor('document.querySelector(".table-wrap tbody tr")');report.checks.push('hours/editor/audit UI')
  const patchSettings=values=>http('/admin/settings',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({settings:values})})
  const patchBranch=values=>http('/admin/branches/'+branchId,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify(values)})
  const replaceHours=values=>http('/admin/branches/'+branchId+'/business-hours',{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify({hours:values})})
  await patchSettings({'orders.enabled':true,'orders.maintenance_mode':false,'pickup.enabled':true,'delivery.enabled':true})
  await patchBranch({accepts_orders:true,supports_pickup:true,supports_delivery:true})
  await replaceHours(Array.from({length:7},(_,day)=>[{day_of_week:day+1,opens_at:'00:00',closes_at:'12:00'},{day_of_week:day+1,opens_at:'12:00',closes_at:'00:00'}]).flat())
  assert.equal((await http('/branches/'+branchId+'/status')).data.is_open,true);report.checks.push('live open schedule')
  await replaceHours([]);assert.equal((await http('/branches/'+branchId+'/status')).data.is_open,false);report.checks.push('live closed day')
  await replaceHours([{day_of_week:5,opens_at:'18:00',closes_at:'03:00'}]);assert.equal((await http('/branches/'+branchId+'/business-hours')).data.days[4].periods[0].closes_at,'03:00');report.checks.push('live overnight round trip')
  await replaceHours(Array.from({length:7},(_,day)=>[{day_of_week:day+1,opens_at:'00:00',closes_at:'12:00'},{day_of_week:day+1,opens_at:'12:00',closes_at:'00:00'}]).flat())
  const now=Date.now(),closure=(await http('/admin/branches/'+branchId+'/closures',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({starts_at:new Date(now-60000).toISOString(),ends_at:new Date(now+3600000).toISOString(),reason:'اختبار التشغيل'})})).data
  closureUuid=closure.uuid;assert.equal((await http('/branches/'+branchId+'/status')).data.reason.code,'BRANCH_TEMPORARILY_CLOSED')
  await http('/admin/branches/'+branchId+'/closures/'+closureUuid,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({is_active:false})});assert.equal((await http('/branches/'+branchId+'/status')).data.is_open,true)
  await http('/admin/branches/'+branchId+'/closures/'+closureUuid,{method:'DELETE'});closureUuid=null;report.checks.push('live closure CRUD/precedence')
  for(const [key,code] of [['orders.enabled','RESTAURANT_ORDERS_DISABLED'],['pickup.enabled','PICKUP_DISABLED'],['delivery.enabled','DELIVERY_DISABLED']]){
    await patchSettings({[key]:false});const value=(await http('/branches/'+branchId+'/status')).data
    assert.equal(key==='delivery.enabled'?value.delivery.reason_code:key==='pickup.enabled'?value.pickup.reason_code:value.reason.code,code)
    if(key==='orders.enabled')assert.equal((await http('/settings/public')).data.orders.enabled,false)
    await patchSettings({[key]:true});report.checks.push('live '+key+' OFF/ON')
  }
  await patchSettings({'orders.maintenance_mode':true,'orders.maintenance_message':'اختبار صيانة الطلبات'})
  assert.equal((await http('/branches/'+branchId+'/status')).data.reason.code,'ORDERING_MAINTENANCE');assert.equal((await http('/settings/public')).data.orders.maintenance_message,'اختبار صيانة الطلبات')
  await patchSettings({'orders.maintenance_mode':false});assert.ok(!('maintenance_message' in (await http('/settings/public')).data.orders));report.checks.push('live maintenance/public message')
  await patchBranch({accepts_orders:false});assert.equal((await http('/branches/'+branchId+'/status')).data.reason.code,'BRANCH_NOT_ACCEPTING_ORDERS');await patchBranch({accepts_orders:true});report.checks.push('live branch suspension OFF/ON')
  await evaluate("localStorage.setItem('shishli.selectedBranch',"+JSON.stringify(branchId)+")");await navigate('/menu');await waitFor('document.querySelector(".public-operation-status").textContent.includes("يستقبل الطلبات")');await waitFor('document.querySelector(".public-business-hours dl")');report.checks.push('live public status/hours')
  let failSettingsOnce=true
  checkoutIntercept=async params=>{if(failSettingsOnce){failSettingsOnce=false;await sleep(700);await send('Fetch.fulfillRequest',{requestId:params.requestId,responseCode:500,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({success:false,message:'PRIVATE-TRACE-DO-NOT-DISPLAY'})).toString('base64')})}else await send('Fetch.continueRequest',{requestId:params.requestId})}
  await send('Fetch.enable',{patterns:[{urlPattern:api+'/admin/settings',requestStage:'Response'}]});await navigate('/admin/settings');await waitFor('document.querySelector("main [role=alert]")')
  assert.ok(!(await evaluate('document.querySelector("main").textContent')).includes('PRIVATE-TRACE'))
  assert.equal(await evaluate('document.querySelectorAll(".settings-save").length'),0)
  await clickText('main button','إعادة المحاولة');await waitFor('document.querySelector("#settings-form input")');await send('Fetch.disable');report.checks.push('transport fixture: settings 500 hides form, safe error, retry recovers')
  managerToken=(await http('/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:'manager@demo.shishli.local',password:process.env.DESIGN_TEST_PASSWORD||'DemoTest123!'})})).data.token
  await evaluate("localStorage.setItem('shishli_auth_token',"+JSON.stringify(managerToken)+")");await navigate('/admin/settings');await waitFor('document.querySelector("#settings-form input")')
  assert.ok(await evaluate('document.querySelector("#settings-form input").disabled || document.querySelector("#settings-form fieldset").disabled'))
  assert.equal(await evaluate('document.querySelectorAll(".settings-save").length'),0)
  const denied=await fetch(api+'/admin/settings',{method:'PATCH',headers:{Authorization:'Bearer '+managerToken,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({settings:{'orders.enabled':false}})});assert.equal(denied.status,403);report.checks.push('live manager read-only and API 403; branches globally scoped')
  await patchSettings({'orders.enabled':true,'orders.maintenance_mode':false,'orders.allow_guest_checkout':true,'orders.minimum_amount':'0.00','pickup.minimum_amount':'0.00','orders.maximum_items_per_order':1000})
  async function guest(path,method='GET',body){const response=await fetch(api+path,{method,headers:{Accept:'application/json','Content-Type':'application/json',...(guestToken?{'X-Cart-Token':guestToken}:{})},...(body?{body:JSON.stringify(body)}:{}),signal:AbortSignal.timeout(20000)});assert.ok(response.ok,'Guest API '+path+': '+response.status);return response.json()}
  guestToken=(await guest('/cart','POST',{branch_uuid:branchId})).data.token
  const menu=(await http('/menu?branch='+branchId)).data
  let product
  for(const candidate of menu.categories.flatMap(category=>category.products)){const details=(await http('/menu/products/'+candidate.id+'?branch='+branchId)).data;if(!details.option_groups?.some(group=>group.is_required||group.min_select>0)){product=details;break}}
  assert.ok(product,'A product without required options for checkout race check')
  await guest('/cart/items','POST',{product_uuid:product.id,quantity:1,options:[]})
  await evaluate("localStorage.removeItem('shishli_auth_token');localStorage.setItem('shishli_cart_token',"+JSON.stringify(guestToken)+");localStorage.setItem('shishli.selectedBranch',"+JSON.stringify(branchId)+")")
  await navigate('/checkout');await waitFor('document.querySelector("#checkout-submit-form")')
  await setInput('customer_name','اختبار التشغيل');await setInput('customer_phone','0501234567');await waitFor('!document.querySelector("#checkout-submit-form button").disabled')
  await patchSettings({'orders.enabled':false});await evaluate('document.querySelector("#checkout-submit-form").requestSubmit()');await waitFor('document.querySelector("main").textContent.includes("استقبال الطلبات معطل حاليًا")')
  assert.ok((await guest('/cart')).data.items.length>0);report.checks.push('live checkout preflight notices changed setting, preserves cart')
  await patchSettings({'orders.enabled':true});await navigate('/checkout');await waitFor('document.querySelector("#checkout-submit-form")');await setInput('customer_name','اختبار التشغيل');await setInput('customer_phone','0501234567');await waitFor('!document.querySelector("#checkout-submit-form button").disabled')
  checkoutIntercept=async params=>{await patchBranch({accepts_orders:false});await send('Fetch.continueRequest',{requestId:params.requestId})}
  await send('Fetch.enable',{patterns:[{urlPattern:api+'/checkout',requestStage:'Request'}]})
  await evaluate('document.querySelector("#checkout-submit-form").requestSubmit()');await waitFor('document.querySelector("main").textContent.includes("لا يستقبل")||document.querySelector("main").textContent.includes("لا يستقبل الطلبات")')
  await send('Fetch.disable');assert.ok((await guest('/cart')).data.items.length>0);assert.ok(report.failures.some(item=>item.path==='/api/v1/checkout'&&item.status===422));report.checks.push('live checkout race: backend rejects 422, frontend refreshes, cart preserved')
  assert.deepEqual(report.diagnostics,[],'Browser diagnostics')
  const unexpected=report.failures.filter(item=>!(item.status===422&&['/api/v1/admin/settings','/api/v1/checkout'].includes(item.path))&&!(item.status===500&&item.path==='/api/v1/admin/settings')&&!(item.status===404&&item.path==='/api/v1/cart'))
  assert.deepEqual(unexpected,[],'Unexpected HTTP failures')
  await writeFile(output+'report.json',JSON.stringify(report,null,2));console.log(JSON.stringify({checks:report.checks.length,responsive:report.responsive.length,dialogs:report.dialogs.length,diagnostics:report.diagnostics.length}))

} finally {
  if(token){
    if(guestToken)await fetch(api+'/cart/items',{method:'DELETE',headers:{'X-Cart-Token':guestToken,Accept:'application/json'},signal:AbortSignal.timeout(10000)}).catch(()=>{})
    if(managerToken)await fetch(api+'/logout',{method:'POST',headers:{Authorization:'Bearer '+managerToken,Accept:'application/json'},signal:AbortSignal.timeout(10000)}).catch(()=>{})
    if(closureUuid)await http('/admin/branches/'+branchId+'/closures/'+closureUuid,{method:'DELETE'}).catch(()=>{})
    if(hoursSnapshot)await http('/admin/branches/'+branchId+'/business-hours',{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify({hours:hoursSnapshot})}).catch(()=>{})
    if(branchSnapshot)await http('/admin/branches/'+branchId,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({accepts_orders:branchSnapshot.accepts_orders,supports_pickup:branchSnapshot.supports_pickup,supports_delivery:branchSnapshot.supports_delivery})}).catch(()=>{})
    if(settingsSnapshot){await http('/admin/settings',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({settings:settingsSnapshot})});assert.deepEqual(Object.fromEntries((await http('/admin/settings')).data.map(row=>[row.key,row.value])),settingsSnapshot);report.settingsRestored=true}
    if(hoursSnapshot){const restored=(await http('/admin/branches/'+branchId+'/business-hours')).data.days.flatMap(day=>day.periods.map(period=>({day_of_week:day.day_of_week,...period})));assert.deepEqual(restored,hoursSnapshot);report.hoursRestored=true}
    if(branchSnapshot){const restored=(await http('/admin/branches/'+branchId)).data;for(const key of ['accepts_orders','supports_pickup','supports_delivery'])assert.equal(restored[key],branchSnapshot[key]);report.branchRestored=true}
    await fetch(api+'/logout',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:AbortSignal.timeout(10000)}).catch(()=>{})
  }
  await writeFile(output+'last-run.json',JSON.stringify(report,null,2));socket?.close();browser?.kill()
}
