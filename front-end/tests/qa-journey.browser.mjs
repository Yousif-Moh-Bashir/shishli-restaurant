import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {writeFile} from 'node:fs/promises'
import {browserClient} from './browser-client.mjs'
const api=process.env.DESIGN_API_URL, origin=process.env.DESIGN_APP_URL
assert.equal(new URL(api).port,'8017','Requires isolated QA API')
assert.equal(new URL(origin).port,'5178','Requires isolated QA frontend')
const report={checks:[],pages:[],responsive:[],responses:[],transport:'Real Vue / Axios / Laravel on isolated seeded SQLite; cash only'},tokens=[]
const browser=await browserClient(9391,resolve('.performance-check/qa-journey'))
async function call(path,method='GET',body,token){const response=await fetch(api+path,{method,headers:{Accept:'application/json',...(body?{'Content-Type':'application/json'}:{}),...(token?{Authorization:'Bearer '+token}:{})},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(20000)});const result=await response.json();assert.ok(response.ok,path+' '+response.status+' '+JSON.stringify(result.errors));return result.data}
async function login(email){const data=await call('/login','POST',{email,password:'DemoTest123!'});tokens.push(data.token);return data.token}
async function clickReady(selector,label){await browser.wait(`[...document.querySelectorAll(${JSON.stringify(selector)})].some(element=>element.textContent.includes(${JSON.stringify(label)})&&!element.disabled)`);await browser.click(selector,label)}
let init, branch, checkoutRequests=0
async function identity(token){if(init)await browser.send('Page.removeScriptToEvaluateOnNewDocument',{identifier:init});const result=await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});${token?`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)});`:''}`});init=result.identifier}
async function responsive(path){for(const width of [320,360,375,390,412,430,768,1024,1366,1920]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<700});await browser.sleep(80);assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true,path+' overflow '+width);report.responsive.push({path,width})}await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:1000,deviceScaleFactor:1,mobile:true})}
try{
 browser.onEvent('Network.requestWillBeSent',({request})=>{if(request.method==='POST'&&new URL(request.url).pathname==='/api/v1/checkout')checkoutRequests++})
 browser.onEvent('Network.responseReceived',({response})=>{if(response.url.startsWith(api))report.responses.push({path:new URL(response.url).pathname,status:response.status})})
 const admin=await login('super_admin@demo.shishli.local'),customer=await login('customer1@demo.shishli.local')
 const cleared=await fetch(api+'/cart/items',{method:'DELETE',headers:{Accept:'application/json',Authorization:'Bearer '+customer}});assert.ok([200,404].includes(cleared.status))
 branch=(await call('/branches'))[0]
 const menu=await call('/menu?branch='+branch.id),products=menu.categories.flatMap(item=>item.products),product=products.find(item=>item.available&&(!item.option_groups?.length))||products.find(item=>item.available)
 assert.ok(product,'Available seeded product')
 await call('/addresses','POST',{label:'QA journey address',recipient_name:'QA customer',phone:'0551234567',city:'تبوك',district:'المصيف',street:'QA street',latitude:branch.location.latitude,longitude:branch.location.longitude,is_default:true},customer)
 const email='qa.driver.'+Date.now()+'@example.test'
 const driver=await call('/admin/drivers','POST',{name:'QA driver',phone:'+96655'+String(Date.now()).slice(-7),email,password:'DemoTest123!',branch_uuid:branch.id,enabled:true,available:true},admin)
 const driverToken=await login(email)
 await identity(customer);await browser.go(origin,'/product/'+product.id);await browser.wait('document.querySelector("#product-detail")')
 await browser.evaluate(`(()=>{for(const group of document.querySelectorAll('.product-option-group')){const input=group.querySelector('input');if(input){input.click()}}})()`)
 await browser.input('#detail-quantity','3');await browser.wait('document.querySelector(".detail-order button")?.disabled===false');await browser.click('.detail-order button','إضافة للسلة');await browser.wait('document.querySelector(".cart-count")?.textContent==="3" || document.body.innerText.includes("تمت الإضافة")')
 report.checks.push('Product options/quantity and add-to-cart through UI')
 // SPA navigation retains the real customer cart; full reload initialization would clear storage.
 await browser.evaluate('document.querySelector("#app").__vue_app__.config.globalProperties.$router.push("/checkout")')
 await browser.wait('document.querySelector("input[value=delivery]")')
 await browser.evaluate('document.querySelector("input[value=delivery]").click()')
 await browser.input('[name=customer_name]','QA customer');await browser.input('[name=customer_phone]','0551234567')
 await browser.wait('[...document.querySelectorAll(".address-card")].some(e=>e.innerText.includes("QA journey address"))')
 await browser.evaluate('(()=>{const card=[...document.querySelectorAll(".address-card")].find(e=>e.innerText.includes("QA journey address"));[...card.querySelectorAll("button")].find(e=>e.textContent.includes("اختيار للتوصيل")||e.textContent.includes("العنوان المختار")).click()})()')
 await browser.wait('[...document.querySelectorAll("button")].some(button=>button.textContent.includes("التحقق من إمكانية التوصيل")&&!button.disabled)');await browser.click('button','التحقق من إمكانية التوصيل');await browser.wait('document.querySelector(".delivery-result-card")?.innerText.includes("التوصيل متاح") || document.body.innerText.includes("التوصيل متاح لهذا العنوان")')
 await responsive('/checkout')
 await browser.wait('document.querySelector("#checkout-submit-form button[type=submit]")?.disabled===false')
 await browser.evaluate('(()=>{const button=document.querySelector("#checkout-submit-form button[type=submit]");button.click();button.click()})()')
 await browser.wait('location.pathname.startsWith("/order-success/")')
 assert.equal(checkoutRequests,1,'Double click sends one checkout')
 const orderId=await browser.evaluate('location.pathname.split("/").at(-1)'),order=await call('/admin/orders/'+orderId,'GET',undefined,admin)
 assert.equal(order.status,'pending');assert.equal(order.payment.status,'pending')
 const current=await call('/admin/orders','GET',undefined,admin);assert.ok(current.some(row=>row.id===orderId))
 report.checks.push('Saved coordinate address, real delivery quote, cash checkout, duplicate-click guard and admin visibility')
 for(const action of ['confirm','start-preparing','mark-ready'])await call('/admin/orders/'+orderId+'/'+action,'POST',{},admin)
 await identity(driverToken);await browser.go(origin,'/driver/orders');await browser.wait('document.body.innerText.includes('+JSON.stringify(order.order_number)+')')
 assert.equal(await browser.evaluate('document.querySelector(".driver-order-grid").innerText.includes("QA customer")'),false)
 await browser.evaluate(`(()=>{const card=[...document.querySelectorAll('.driver-card')].find(e=>e.innerText.includes(${JSON.stringify(order.order_number)}));card.querySelector('button').click()})()`)
 await browser.wait('location.pathname.endsWith('+JSON.stringify(orderId)+') && document.body.innerText.includes("QA customer")')
 await browser.wait('document.querySelector(".leaflet-container")');await responsive('/driver/orders/:id')
 await clickReady('.driver-actions button','استلام من الفرع');await browser.wait('document.body.innerText.includes("بدء التوصيل")')
 await clickReady('.driver-actions button','بدء التوصيل');await browser.wait('document.body.innerText.includes("تسجيل التحصيل النقدي")')
 await clickReady('button','تسجيل التحصيل النقدي');await browser.wait('document.querySelector("input[inputmode=decimal]")')
 await clickReady('button','تأكيد استلام المبلغ');await browser.wait('document.body.innerText.includes("تأكيد التسليم")')
 await clickReady('.driver-actions button','تأكيد التسليم');await browser.wait('document.body.innerText.includes("تم التسليم للعميل")')
 assert.equal(await browser.evaluate('document.querySelector(".driver-actions")'),null)
 assert.equal(await browser.evaluate('document.querySelector(".driver-open-map")'),null)
 report.checks.push('Real kitchen transitions, ready driver queue, privacy, accept, pickup, on-way, cash and delivered read-only details')
 const completed=await call('/admin/orders/'+orderId,'GET',undefined,admin);assert.equal(completed.status,'completed');assert.equal(completed.payment.status,'paid')
 const collections=await call('/driver/collections?settled=0','GET',undefined,driverToken),collection=collections.find(item=>item.order.id===orderId);assert.ok(collection)
 await call('/admin/drivers/'+driver.id+'/settlements','POST',{collection_uuids:[collection.id],amount:collection.amount,reason:'QA cashier settlement'},admin)
 const unsettled=await call('/driver/collections?settled=0','GET',undefined,driverToken);assert.equal(unsettled.some(item=>item.id===collection.id),false)
 report.checks.push('Admin completion/payment verification, driver collections and cashier settlement')
 await browser.go(origin,'/driver/collections');await browser.wait('document.querySelector(".driver-main h1")');await responsive('/driver/collections')
 await identity(null)
 for(const path of ['/','/menu','/branches','/offers','/about','/gallery','/contact','/cart','/checkout','/login','/register','/orders','/track-order','/missing-qa']){await browser.go(origin,path);await browser.sleep(200);await responsive(path);report.pages.push(path)}
 await identity(customer)
 for(const path of ['/account/profile','/account/addresses','/account/orders','/account/orders/'+orderId]){await browser.go(origin,path);await browser.wait('document.querySelector("main")');await browser.sleep(300);await responsive(path);report.pages.push(path)}
 assert.deepEqual(browser.diagnostics,[]);report.result='passed'
 console.log(JSON.stringify({result:report.result,checks:report.checks,pages:report.pages.length,responsive:report.responsive.length}))
}catch(error){report.result='failed';report.error=error.message;throw error}finally{await writeFile(resolve('../docs/qa/journey-browser.json'),JSON.stringify(report,null,2));for(const token of tokens)await call('/logout','POST',undefined,token).catch(()=>{});browser.close()}
