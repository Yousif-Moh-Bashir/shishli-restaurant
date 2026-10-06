import {browserClient} from './browser-client.mjs'
import {writeFile,mkdir} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
import assert from 'node:assert/strict'
const api='http://127.0.0.1:8000/api/v1',origin='http://127.0.0.1:5176',out=fileURLToPath(new URL('../audit/admin-ui-functional/',import.meta.url))
const report={live:[],fixtures:[],tabs:[],diagnostics:[],failures:[]},tokens=[]
let browser,activeToken
await mkdir(out,{recursive:true})
async function request(path,options={}){const response=await fetch(api+path,{...options,headers:{Accept:'application/json',...(activeToken?{Authorization:'Bearer '+activeToken}:{}),...options.headers}});assert.ok(response.ok,`${path} ${response.status}`);return response.json()}
async function login(email){const data=await request('/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password:'DemoTest123!'})});tokens.push(data.data.token);return data.data.token}
try{
 activeToken=await login('super_admin@demo.shishli.local')
 const product=(await request('/admin/products')).data[0],fullProduct=(await request('/admin/products/'+product.id)).data
 browser=await browserClient(9310,out+'/browser')
 let init=await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(activeToken)})`})
 const traffic=[],mutations=[],fixtureErrors=[]
 report.fixtureErrors=fixtureErrors
 report.networkFailures=[]
 report.fixtureRequests=[]
 await browser.send('Network.enable')
 browser.onEvent('Network.loadingFailed',event=>report.networkFailures.push({type:event.type,error:event.errorText,blocked:event.blockedReason,cors:event.corsErrorStatus}))
 browser.onEvent('Network.requestWillBeSent',({request})=>{if(request.url.startsWith(api)&&request.method==='GET')traffic.push(new URL(request.url))})
 const notificationId='11111111-2222-4333-8444-555555555555'
 const page=(rows,current=1)=>({success:true,data:rows,meta:{from:1,to:rows.length,total:40,current_page:current,last_page:2,per_page:20},links:{prev:current>1?api+'/admin/products?page=1':null,next:current<2?api+'/admin/products?page=2':null}})
 let mode='none'
 browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
  try{
   const url=new URL(request.url);let response,status=200
   report.fixtureRequests.push({path:url.pathname,method:request.method,mode})
   if(request.method==='OPTIONS'){
    await browser.send('Fetch.fulfillRequest',{requestId,responseCode:204,responseHeaders:[{name:'Access-Control-Allow-Origin',value:origin},{name:'Access-Control-Allow-Methods',value:'GET, POST, PATCH, DELETE, OPTIONS'},{name:'Access-Control-Allow-Headers',value:'Authorization, Content-Type, Accept, X-Requested-With'}]});return
   }
   if(!['GET','OPTIONS'].includes(request.method)){
    mutations.push({path:url.pathname,method:request.method,body:request.postData||'',headers:request.headers})
    status=422;response={success:false,message:'راجع البيانات المدخلة.',errors:url.pathname.endsWith('/images')?{'images.0':['صورة الاختبار مرفوضة.']}:{name:['اسم المنتج غير صالح للاختبار.']}}
   }else if(url.pathname.includes(notificationId)){
    response=url.pathname.endsWith('/attempts')?page([]):{success:true,data:{id:notificationId,type:'order_confirmed',channel:'email',status:'failed',recipient:'customer@example.com',recipient_name:'عميل اختبار',provider:'smtp',attempts:1,max_attempts:3,created_at:'2026-10-05T08:00:00Z',content:'رسالة اختبار للعرض فقط',subject:'حالة الطلب',available_actions:[],reference:null}}
   }else if(mode==='pagination'&&url.pathname==='/api/v1/admin/products')response=page([product],Number(url.searchParams.get('page')||1))
   else if(mode==='inventory'&&url.pathname==='/api/v1/admin/inventory')response=page([{id:notificationId,product:{id:product.id,name:product.name},branch:{id:notificationId,name:'فرع اختبار'},stock:{on_hand:'10',reserved:'1',available:'9'},tracking:true,is_low_stock:false,is_out_of_stock:false}])
   if(response){await browser.send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:origin}],body:Buffer.from(JSON.stringify(response)).toString('base64')})}
   else await browser.send('Fetch.continueRequest',{requestId})
  }catch(error){fixtureErrors.push(error.message||String(error))}
 })
 await browser.send('Fetch.enable',{patterns:[{urlPattern:api+'/*',requestStage:'Request'}]})
 await browser.go(origin,'/admin/products/'+product.id);await browser.wait('document.querySelector(".product-profile")')
 await browser.click('main button','تعديل المنتج');await browser.wait('document.querySelector("dialog[open] input[name=name]")')
 await browser.wait('document.querySelector("dialog[open] select[name=category_uuid]")?.options.length>1')
 const invalid=await browser.evaluate('[...document.querySelectorAll("dialog[open] input,dialog[open] select")].filter(input=>!input.checkValidity()).map(input=>({name:input.name,message:input.validationMessage}))')
 assert.deepEqual(invalid,[],'Existing product form passes native validation')
 await browser.evaluate('document.querySelector("dialog[open] form").requestSubmit()')
 await browser.wait('document.querySelector("dialog[open] input[name=name]")?.getAttribute("aria-invalid")==="true"')
 const body=JSON.parse(mutations[0].body)
 assert.equal(body.name,fullProduct.name);assert.equal(body.category_uuid,fullProduct.category.id);assert.equal(body.base_price,fullProduct.base_price)
 assert.deepEqual(Object.keys(body).sort(),['name','slug','sku','category_uuid','base_price','short_description','description','sort_order','preparation_time','is_active','is_available','is_featured','tracks_inventory'].sort())
 assert.equal(await browser.evaluate('document.querySelector("dialog[open] input[name=name]").closest("label").querySelector(".field-error").textContent.includes("اسم المنتج")'),true)
 report.fixtures.push('Product edit preserves all 13 payload fields; Laravel 422 stays attached to its input; no mutation reaches backend')
 await browser.click('dialog[open] .icon-button','')
 await browser.click('.admin-tabs button','الصور')
 await browser.evaluate(`(()=>{const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jJ9kAAAAASUVORK5CYII='),c=>c.charCodeAt(0));const transfer=new DataTransfer();transfer.items.add(new File([bytes],'ui-test.png',{type:'image/png'}));const input=document.querySelector('.catalog-image-grid+form input[type=file]')||[...document.querySelectorAll('main input[type=file]')].find(e=>e.getClientRects().length);input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}))})()`)
 await browser.click('main button','رفع الصور');await browser.wait('[...document.querySelectorAll("main [role=alert]")].some(e=>e.getClientRects().length&&e.textContent.includes("صورة الاختبار"))')
 const upload=mutations.find(row=>row.path.endsWith('/images'));assert.ok(upload)
 assert.ok(Object.entries(upload.headers).some(([key,value])=>key.toLowerCase()==='content-type'&&value.includes('multipart/form-data')))
 report.fixtures.push('Multipart image upload remains active inside Images tab; intercepted request receives field errors; no media written')
 mode='pagination'
 await browser.go(origin,'/admin/products');await browser.wait('document.querySelector(".api-pagination button:last-child")?.disabled===false')
 await browser.click('.api-pagination button','التالي');await browser.wait('document.querySelector(".api-pagination")?.textContent.includes("صفحة 2")')
 assert.ok(traffic.some(url=>url.pathname.endsWith('/admin/products')&&url.searchParams.get('page')==='2'))
 report.fixtures.push('Server pagination controls send page=2 and render returned metadata')
 mode='none'
 await browser.input('main input[type=search]','شيشلي');await browser.wait('document.querySelector(".filter-summary")');await browser.sleep(500)
 assert.ok(traffic.some(url=>url.searchParams.get('search')==='شيشلي'))
 await browser.wait('document.querySelector(".filter-summary button")?.disabled===false');await browser.click('.filter-summary button','مسح الفلاتر');await browser.wait('document.querySelector("main input[type=search]")?.value===""')
 report.live.push('Debounced search and clear filters still issue actual GET requests')
 // Native confirmation is replaced by the shared modal; cancel must send no request.
 await browser.go(origin,'/admin/offers');await browser.wait('document.querySelector("tbody button.danger")')
 const before=mutations.length;await browser.click('tbody button.danger','حذف');await browser.wait('document.querySelector(".admin-confirm-dialog[open]")')
 await browser.click('.admin-confirm-dialog[open] button','إلغاء');await browser.wait('!document.querySelector(".admin-confirm-dialog[open]")');assert.equal(mutations.length,before)
 report.live.push('Shared destructive confirmation cancels without sending a mutation')
 for(const [route,selector] of [['settings','.settings-tabs button'],['cms','.module-tabs button'],['inventory','.module-tabs button'],['customers','.module-tabs button']]){
  await browser.go(origin,'/admin/'+route);await browser.wait(`document.querySelectorAll(${JSON.stringify(selector)}).length>1`)
  const count=await browser.evaluate(`document.querySelectorAll(${JSON.stringify(selector)}).length`)
  for(let i=0;i<count;i++){
   await browser.evaluate(`document.querySelectorAll(${JSON.stringify(selector)})[${i}].click()`);await browser.sleep(800)
   for(const width of [1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await browser.sleep(50);const overflow=await browser.evaluate('document.documentElement.scrollWidth>innerWidth+1');report.tabs.push({route,index:i,width,overflow});assert.ok(!overflow,'Tab overflow '+route+' '+i+' '+width)}
  }
 }
 await browser.go(origin,'/admin/reports');await browser.wait('document.querySelectorAll(".report-tabs a").length>1')
 const reports=await browser.evaluate('[...document.querySelectorAll(".report-tabs a")].map(a=>a.getAttribute("href"))')
 for(const path of reports){await browser.go(origin,path);await browser.sleep(900);for(const width of [1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});const overflow=await browser.evaluate('document.documentElement.scrollWidth>innerWidth+1');report.tabs.push({route:'reports',path,width,overflow});assert.ok(!overflow,'Report overflow '+path)}}
 await browser.go(origin,'/admin/customers');await browser.wait('document.querySelector("main tbody tr button")');await browser.click('main tbody button','التفاصيل');await browser.wait('document.querySelector(".customer-details .module-tabs")')
 for(const label of ['الملف','الطلبات','العناوين']){await browser.click('.customer-details .module-tabs button',label);await browser.sleep(500);assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'));report.tabs.push({route:'customer-details',label,width:390,overflow:false})}
 mode='inventory';await browser.go(origin,'/admin/inventory');await browser.wait('document.querySelector("main button[popovertarget]")');await browser.evaluate('document.querySelector("main button[popovertarget]").click()');await browser.wait('document.querySelector(".admin-action-popover:popover-open")?.style.top');assert.ok(await browser.evaluate('(()=>{const r=document.querySelector(".admin-action-popover:popover-open").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight})()'));report.fixtures.push('Inventory action menu stays within viewport in native top layer; live stock list is empty');mode='none'
 await browser.go(origin,'/admin/notifications/'+notificationId);await browser.wait('document.querySelector(".notification-content")')
 for(const width of [1440,1024,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'));await writeFile(out+'notification-detail-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'))}
 report.fixtures.push('Notification detail route, message and attempt history checked at four widths with transport fixture because live list is empty')
 // Limited real role, with no invented permissions or role changes.
 activeToken=await login('kitchen@demo.shishli.local')
 await browser.send('Page.removeScriptToEvaluateOnNewDocument',{identifier:init.identifier})
 init=await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(activeToken)})`})
 await browser.go(origin,'/admin/kitchen');await browser.wait('document.querySelector(".sidebar-group-links a")')
 assert.equal(await browser.evaluate('[...document.querySelectorAll(".sidebar-group")].every(group=>group.querySelectorAll("a").length>0)'),true)
 await browser.go(origin,'/admin/products');await browser.wait('location.pathname==="/forbidden"')
 report.live.push('Kitchen role hides empty navigation groups and product route remains forbidden')
 assert.deepEqual(fixtureErrors,[])
 // 422 fixture responses intentionally generate Chrome network-error messages.
 report.diagnostics=browser.diagnostics.filter(message=>!message.includes('422 (Unprocessable Content)')&&!message.includes('422 (Unprocessable Entity)'))
 assert.deepEqual(report.diagnostics,[])
}catch(error){report.failures.push(error.message);if(browser)report.failures.push(await browser.evaluate('({pagination:document.querySelector(".api-pagination")?.textContent,buttons:[...document.querySelectorAll(".api-pagination button")].map(e=>({text:e.textContent,disabled:e.disabled})),errors:[...document.querySelectorAll("main [role=alert]")].map(e=>e.textContent)})'));process.exitCode=1}
finally{browser?.close();for(const token of tokens)await fetch(api+'/logout',{method:'POST',headers:{Accept:'application/json',Authorization:'Bearer '+token}}).catch(()=>{});await writeFile(out+'report.json',JSON.stringify(report,null,2));console.log(JSON.stringify({live:report.live.length,fixtures:report.fixtures.length,tabs:report.tabs.length,failures:report.failures,diagnostics:report.diagnostics,fixtureErrors:report.fixtureErrors}))}
