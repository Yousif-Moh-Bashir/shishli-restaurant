import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { writeFile } from 'node:fs/promises'
import { browserClient } from './browser-client.mjs'
const browser = await browserClient(9384, resolve('.performance-check/drivers'))
const origin = process.env.FRONTEND_ORIGIN || 'http://127.0.0.1:5176'
const id = n => `${String(n).padStart(8,'0')}-1111-4111-8111-111111111111`
const branch = { id:id(1), name:'فرع المصيف', location:{city:'تبوك',latitude:'28.4378538',longitude:'36.5455267'} }
let mode = 'driver', accepted = false, stage = null, paid = false, acceptConflict = true, detailFailure = 0, settled = false
const driver = { id:id(2),name:'مندوب الاختبار',phone:'+966551234567',email:null,enabled:true,available:true,account_active:true,branch,delivered_count:0,unsettled_cash:'85.00',current_order:null }
const preparation = () => stage==='on_the_way'?'out_for_delivery':stage==='delivered'?'completed':'ready'
const actions = () => stage==='assigned'?['picked_up','failed']:stage==='picked_up'?['on_the_way','failed',...(!paid?['collect_cash']:[])]:stage==='on_the_way'?[...(paid?['delivered']:['collect_cash']),'failed']:[]
const summary = () => ({ id:id(3),order_number:'SH-DRIVER',branch,preparation_status:preparation(),estimated_ready_at:'2026-10-08T12:30:00Z',district:'المصيف',delivery_zone:'المصيف',delivery_fee:'5.00',currency:'SAR',delivery_status:stage,available_actions:accepted?actions():driver.available&&driver.enabled?['accept']:[] })
const detail = () => ({ ...summary(),customer:{name:'عميل خاص',phone:'0559999999'},address:{recipient_name:'عميل خاص',phone:'0559999999',city:'تبوك',district:'المصيف',street:'شارع الاختبار',building_number:'12',notes:'الجرس عند الباب',location:{latitude:'28.4378538',longitude:'36.5455267'}},branch_location:{latitude:'28.43',longitude:'36.54'},total:'85.00',payment_method:'cash',payment_status:paid?'paid':'pending',cash_due:paid?'0.00':'85.00',history:[{to_status:stage,actor:driver.name,at:'2026-10-08T12:00:00Z',reason:null}] })
const collection = () => ({id:id(4),order:{id:id(3),order_number:'SH-DRIVER'},amount:'85.00',currency:'SAR',collected_at:'2026-10-08T12:00:00Z',settled_at:settled?'2026-10-08T12:10:00Z':null,settlement:settled?{cashier:'كاشير الاختبار',reason:'استلام العهدة'}:null})
const permissions = ['drivers.view','drivers.manage','drivers.reassign','drivers.settle_cash','orders.view']
const requests = [], diagnostics = [], checks = []
function page(data) { return {success:true,message:'Success',data,errors:null,meta:{current_page:1,last_page:1,per_page:20,total:data.length,from:data.length?1:0,to:data.length},links:{prev:null,next:null}} }
async function fulfill(requestId, status, data, paginated=false, errors={}, extra={}) {
  const body = status>=400?{success:false,message:'تعذر تنفيذ العملية.',errors}:paginated?page(data):{success:true,message:'Success',data,errors:null}
  await browser.send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type'},{name:'Access-Control-Allow-Methods',value:'GET,POST,PATCH,PUT,OPTIONS'}],body:Buffer.from(JSON.stringify({...body,...extra})).toString('base64')})
}
browser.onEvent('Fetch.requestPaused', async ({requestId,request}) => {
  try {
    const url = new URL(request.url), path = url.pathname, body = request.postData?JSON.parse(request.postData):{}
    if (url.hostname==='tile.openstreetmap.org') { await browser.send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'image/png'}],body:'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg=='}); return }
    if (!path.startsWith('/api/')) { await browser.send('Fetch.continueRequest',{requestId}); return }
    requests.push({path,method:request.method,body})
    let data={}, status=200, paginated=false
    if (request.method==='OPTIONS') {}
    else if (path==='/api/user') data={uuid:driver.id,name:driver.name,email:null,phone:driver.phone,is_driver:mode==='driver'}
    else if (path.endsWith('/admin/me')) { if(mode==='driver'||mode==='customer')status=403;else data={id:driver.id,name:'إدارة الاختبار',is_active:true,roles:['manager'],permissions} }
    else if (path.endsWith('/driver/me')) data=driver
    else if (path.endsWith('/driver/availability')) {driver.available=body.available;data=driver}
    else if (path.endsWith('/driver/orders')) {data=url.searchParams.get('period')==='history'?[{...summary(),id:id(30),order_number:'SH-PREVIOUS',preparation_status:'completed',delivery_status:'delivered',available_actions:[]}]:url.searchParams.get('scope')==='mine'?(accepted?[summary()]:[]):accepted?[]:[summary()];paginated=true}
    else if (path.endsWith('/accept')) {if(acceptConflict){status=409;acceptConflict=false}else{accepted=true;stage='assigned';data=detail()}}
    else if (path.endsWith('/transition')) {stage=body.status;data=detail()}
    else if (path.endsWith('/cash/collect')) {paid=true;data=collection()}
    else if (path.includes('/driver/orders/')) {status=detailFailure||200;data=detail()}
    else if (path.endsWith('/driver/collections')) {data=paid && (url.searchParams.get('settled')!=='0'||!settled)?[collection()]:[];paginated=true}
    else if (path.endsWith('/admin/drivers') && request.method==='POST') {data={...driver,...body,id:id(5)};status=201}
    else if (path.endsWith('/admin/drivers')) {data=url.searchParams.has('branch_uuid')?[{...driver,id:id(8),name:'مندوب بديل',enabled:true,available:true,current_order:null}]:[driver];paginated=true}
    else if (path.includes('/admin/drivers/') && path.endsWith('/collections')) {data=[collection()];paginated=true}
    else if (path.endsWith('/settlements')) {settled=true;driver.unsettled_cash='0.00';data={id:id(6),amount:body.amount}}
    else if (path.includes('/admin/drivers/') && request.method==='PUT') {Object.assign(driver,body);data=driver}
    else if (path.includes('/admin/drivers/')) data=driver
    else if (path.endsWith('/reassign-driver')) {data={id:id(3),delivery_status:'assigned'};driver.current_order=null}
    else if (path.endsWith('/admin/orders/counts')) data={pending:0}
    else if (path.endsWith('/admin/orders')) {data=[];paginated=true}
    else if (path.endsWith('/branches')) data=[branch]
    else if (path.endsWith('/settings/public')) data={restaurant:{name:'شيشلي'},orders:{enabled:true}}
    else if (path.endsWith('/cart')) data={id:id(9),token:'fixture-cart',branch,status:'active',items:[],summary:{quantity_total:0,items_count:0,total:'0.00'}}
    else if (path.endsWith('/logout')) data=null
    const extra=path.endsWith('/driver/orders')?{driver_context:{can_accept:driver.available&&!accepted&&url.searchParams.get('period')!=='history',work_day:{date:url.searchParams.get('work_date')||'2026-10-08',latest_history_date:'2026-10-07',timezone:'Asia/Riyadh',period:url.searchParams.get('period')||'current'},blocking_reason:!driver.available?'أنت غير متاح حاليًا. فعّل حالة التوفر أعلى الصفحة لقبول طلب.':accepted?'لديك طلب نشط. أكمله من «طلبك الحالي» قبل قبول طلب آخر.':null,active_order:accepted&&stage!=='delivered'?summary():null}}:{}
    await fulfill(requestId,status,data,paginated,{},extra)
  } catch(error) {diagnostics.push(String(error));await browser.send('Fetch.failRequest',{requestId,errorReason:'Failed'}).catch(()=>{})}
})
try {
  await browser.send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/*'},{urlPattern:'*://tile.openstreetmap.org/*'}]})
  await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:"localStorage.setItem('shishli_auth_token','driver-fixture-token')"})
  driver.available=false
  await browser.go(origin,'/driver/orders');await browser.wait('document.body.innerText.includes("SH-DRIVER")')
  assert.equal(await browser.evaluate('document.querySelector(".driver-card button")?.disabled'),true)
  assert.equal(await browser.evaluate('document.body.innerText.includes("جاهز لدى المطعم") && document.body.innerText.includes("فعّل حالة التوفر")'),true)
  await browser.click('.driver-availability','غير متاح');await browser.wait('[...document.querySelectorAll(".driver-card button")].some(button=>!button.disabled)')
  checks.push('ready queue remains visible while unavailable, clear reason and acceptance after availability')
  assert.equal(requests.some(request=>request.path.endsWith('/driver/orders') && request.method==='GET'),true)
  assert.equal(await browser.evaluate('document.querySelector(".driver-workday-filter")'),null)
  assert.equal(await browser.evaluate('document.querySelector(".driver-history-date")'),null)
  checks.push('current workday queue without history controls')
  assert.equal(await browser.evaluate('document.body.innerText.includes("عميل خاص")'),false)
  await browser.click('button','قبول الطلب');await browser.wait('document.body.innerText.includes("تغيّر الطلب")')
  await browser.click('button','قبول الطلب');await browser.wait('location.pathname.includes("/orders/") && document.body.innerText.includes("عميل خاص")')
  await browser.go(origin,'/driver/orders');await browser.wait('document.querySelector(".driver-current-order")')
  await browser.click('.driver-current-order a','متابعة الطلب');await browser.wait('document.querySelector(".order-lifecycle")')
  assert.equal(await browser.evaluate('document.querySelectorAll(".lifecycle-track").length'),2)
  checks.push('active order shortcut and separate preparation/delivery sequences')
  await browser.wait('document.querySelector(".leaflet-container")')
  assert.match(await browser.evaluate('[...document.querySelectorAll("a")].find(a=>a.textContent.includes("فتح الملاحة")).href'),/destination=28.4378538%2C36.5455267/)
  assert.equal(await browser.evaluate('[...document.querySelectorAll("a")].find(a=>a.getAttribute("href")?.startsWith("tel:")).getAttribute("href")'),'tel:0559999999')
  checks.push('privacy before accept, 409 recovery, accepted details, real snapshot navigation and map')
  for (const width of [320,360,390,430,768,1280]) {
    await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<700});await browser.sleep(120)
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth'),true,'Driver overflow '+width)
  }
  await browser.send('Page.captureScreenshot',{format:'png'}).then(result=>writeFile(resolve('.performance-check/drivers/driver-detail.png'),Buffer.from(result.data,'base64')))
  checks.push('responsive driver details: 320,360,390,430,768,1280')
  await browser.click('button','استلام من الفرع');await browser.wait('document.body.innerText.includes("بدء التوصيل")')
  await browser.click('button','بدء التوصيل');await browser.wait('document.body.innerText.includes("في الطريق للعميل")')
  assert.equal(await browser.evaluate('[...document.querySelectorAll("button")].some(b=>b.textContent.includes("تأكيد التسليم"))'),false)
  await browser.click('button','تسجيل التحصيل النقدي');await browser.input('.driver-field input[type=number]','85.00');await browser.click('button','تأكيد استلام المبلغ');await browser.wait('document.body.innerText.includes("تأكيد التسليم")')
  await browser.click('button','تأكيد التسليم');await browser.wait('document.body.innerText.includes("تم التسليم")')
  assert.equal(requests.filter(request=>request.method==='POST' && request.path.endsWith('/cash/collect')).length,1)
  checks.push('picked up, on the way, cash before delivered, no duplicate collection')
  await browser.go(origin,'/driver/collections');await browser.wait('document.body.innerText.includes("عهدة نقدية")')
  checks.push('driver unsettled collections')
  for (const code of [403,404,500]) { detailFailure=code;await browser.go(origin,'/driver/orders/'+id(3));await browser.wait('document.querySelector(".driver-error")');assert.equal(await browser.evaluate('document.body.innerText.includes("عميل خاص")'),false) }
  detailFailure=0;checks.push('403,404,500 clear inaccessible details')
  mode='admin';driver.current_order={id:id(3),order_number:'SH-DRIVER',delivery_status:'assigned'}
  await browser.go(origin,'/admin/drivers');await browser.wait('document.querySelector("table")')
  for (const width of [320,390,768,1280]) {await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<700});await browser.sleep(120);assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth'),true,'Admin overflow '+width)}
  await browser.click('button','إضافة مندوب');await browser.wait('document.querySelector("dialog[open]")')
  const fields=await browser.evaluate('[...document.querySelectorAll("dialog[open] .domain-form-fields input,dialog[open] .domain-form-fields select")].map(e=>({id:e.id,type:e.type}))')
  assert.ok(fields.length>=5)
  await browser.input('dialog[open] input[id$="-name"]','مندوب جديد');await browser.input('dialog[open] input[id$="-phone"]','+966551234999');await browser.input('dialog[open] input[type=password]','DriverPassword123');await browser.click('dialog[open] button','حفظ المندوب');await browser.wait('!document.querySelector("dialog[open]")')
  const createBody=requests.find(request=>request.method==='POST' && request.path.endsWith('/admin/drivers')).body
  assert.equal(createBody.branch_uuid,branch.id);assert.equal(createBody.enabled,true);assert.equal(createBody.name,'مندوب جديد')
  await browser.click('button','إيقاف');await browser.wait('document.querySelector("dialog.admin-confirm-dialog[open]")');await browser.click('dialog.admin-confirm-dialog[open] button','تأكيد');await browser.wait('document.querySelector("table")?.innerText.includes("موقوف")')
  await browser.click('table button','تفعيل');await browser.wait('document.querySelector("dialog.admin-confirm-dialog[open]")');await browser.click('dialog.admin-confirm-dialog[open] button','تأكيد');await browser.wait('document.querySelector("table")?.innerText.includes("غير متاح")')
  await browser.click('button','التفاصيل');await browser.wait('document.querySelector("dialog[open] .driver-collection-row")')
  await browser.click('dialog[open] button','إعادة إسناد الطلب');await browser.wait('document.querySelector("dialog[open] .assignment-form select")?.options.length>1')
  await browser.input('dialog[open] .assignment-form select',id(8));await browser.input('dialog[open] .assignment-form textarea','تغيير المناوبة');await browser.click('dialog[open] button','تأكيد الإسناد');await browser.wait('document.querySelector("dialog.admin-confirm-dialog[open]")');await browser.click('dialog.admin-confirm-dialog[open] button','تأكيد');await browser.wait('!document.querySelector("dialog[open] .driver-assignment")')
  assert.equal(requests.filter(request=>request.method==='POST' && request.path.endsWith('/reassign-driver')).length,1)
  await browser.evaluate('document.querySelector("dialog[open] .driver-collection-row input").click()')
  await browser.input('dialog[open] .driver-settlement-form input','85.00');await browser.input('dialog[open] .driver-settlement-form textarea','استلام العهدة');await browser.click('dialog[open] button','تأكيد استلام العهدة');await browser.wait('document.querySelector("dialog.admin-confirm-dialog[open]")');await browser.click('dialog.admin-confirm-dialog[open] button','تأكيد');await browser.wait('document.body.innerText.includes("تمت تسوية العهدة النقدية")')
  assert.equal(requests.filter(request=>request.method==='POST' && request.path.endsWith('/settlements')).length,1)
  checks.push('admin responsive list, creation, activation, reassignment, collections and settlement payload')
  mode='driver';detailFailure=401;await browser.go(origin,'/driver/orders/'+id(3));await browser.wait('location.pathname==="/login"');checks.push('401 returns to login')
  assert.deepEqual(diagnostics,[]);assert.deepEqual(browser.diagnostics,[])
  console.log(JSON.stringify({result:'passed',checks,transport:'API and map tile fixtures; Laravel contracts tested separately'}))
} finally {browser.close()}
