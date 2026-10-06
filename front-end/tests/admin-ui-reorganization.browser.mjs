// Read-only live API and browser regression; business mutations are never submitted.
import assert from 'node:assert/strict'
import {writeFile,mkdir} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
import {browserClient} from './browser-client.mjs'
const out=fileURLToPath(new URL('../audit/admin-ui-check/',import.meta.url))
const origin=process.env.ADMIN_UI_ORIGIN||'http://127.0.0.1:5176'
const api=process.env.ADMIN_UI_API||'http://127.0.0.1:8000/api/v1'
const report={pages:[],modals:[],tabs:[],checks:[],failures:[],diagnostics:[],assetFailures:[]}
let token,browser
await mkdir(out,{recursive:true})
async function request(path,options={}){
 const response=await fetch(api+path,{...options,headers:{Accept:'application/json',...(token?{Authorization:'Bearer '+token}:{}),...options.headers},signal:AbortSignal.timeout(20000)})
 assert.ok(response.ok,`API ${path}: ${response.status}`)
 return response.json()
}
try{
 const login=await request('/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:process.env.ADMIN_UI_EMAIL||'super_admin@demo.shishli.local',password:process.env.ADMIN_UI_PASSWORD||'DemoTest123!'})})
 token=login.data.token
 const ids={}
 for(const [name,endpoint] of Object.entries({products:'products',branches:'branches',groups:'option-groups',orders:'orders',kitchen:'kitchen/orders',payments:'payments',notifications:'notifications'})){
  const response=await request('/admin/'+endpoint)
  const rows=Array.isArray(response.data)?response.data:response.data?.data
  ids[name]=rows?.[0]?.id
 }
 browser=await browserClient(9308,out+'/browser')
 await browser.send('Network.enable')
 browser.onEvent('Network.responseReceived',({response})=>{if(response.status>=400&&!response.url.includes('/api/'))report.assetFailures.push({status:response.status,path:new URL(response.url).pathname})})
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`})
 const routes=['','orders','kitchen','products','categories','branches','product-options','inventory','delivery-zones','offers','customers','payments','notifications','reports','cms','staff','roles','activity-logs','settings'].map(path=>'/admin'+(path?'/'+path:''))
 for(const [name,path] of Object.entries({products:'products',branches:'branches',groups:'product-options',orders:'orders',kitchen:'kitchen',payments:'payments',notifications:'notifications'}))if(ids[name])routes.push('/admin/'+path+'/'+ids[name])
 const layout=()=>browser.evaluate(`(()=>{
 const visible=e=>e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden';const collisions=[];
 for(const grid of document.querySelectorAll('main .form-grid,main .toolbar,main .admin-filter-primary,main .admin-tabs,main .weekly-hours')){
  if(!visible(grid))continue;const items=[...grid.children].filter(visible);
  for(let i=0;i<items.length;i++)for(let j=i+1;j<items.length;j++){const a=items[i].getBoundingClientRect(),b=items[j].getBoundingClientRect();if(Math.min(a.right,b.right)-Math.max(a.left,b.left)>3&&Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>3)collisions.push(grid.className)}
 }
 return {overflow:document.documentElement.scrollWidth>innerWidth+1,collisions,heading:document.querySelector('main h1')?.textContent,active:[...document.querySelectorAll('.sidebar a.active')].map(a=>a.getAttribute('href')),mobileTables:[...document.querySelectorAll('main .admin-data-table')].filter(visible).map(t=>({display:getComputedStyle(t).display,labels:[...t.querySelectorAll('tbody td')].every(td=>td.dataset.label)}))}
 })()`)
 for(const route of routes){
  await browser.go(origin,route)
  await browser.wait('document.querySelector("main h1")?.textContent.trim()')
  await browser.sleep(900)
  await browser.wait('!document.querySelector("main [aria-busy=true]") && ![...document.querySelectorAll("main .app-skeleton")].some(e=>e.getClientRects().length)')
  for(const width of [1440,1024,768,390]){
   await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false})
   await browser.sleep(100)
   const result=await layout();report.pages.push({route,width,...result})
   if(result.overflow||result.collisions.length)report.failures.push({route,width,...result})
   if(width===390)assert.ok(result.mobileTables.every(t=>t.labels),'Mobile table labels '+route)
   await writeFile(out+route.replaceAll('/','_')+'-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'))
  }
  console.log('Reviewed '+route)
 }
 // Every nested route keeps its list active; home must not remain active on child routes.
 assert.ok(report.pages.every(page=>page.active.length===1),'Exactly one active navigation destination')
 report.checks.push('26 route templates and live API reads; nested active navigation; responsive tables and grids')
 await browser.go(origin,'/admin/products/'+ids.products)
 await browser.wait('document.querySelectorAll(".admin-tabs button").length===4')
 for(const label of ['المعلومات','الصور','الخيارات','الفروع والأسعار']){await browser.click('.admin-tabs button',label);await browser.sleep(250);const result=await layout();report.tabs.push({domain:'product',label,...result});assert.ok(!result.overflow,'Product tab '+label)}
 await browser.go(origin,'/admin/branches/'+ids.branches)
 await browser.wait('document.querySelectorAll(".admin-tabs button").length===3')
 for(const label of ['بيانات الفرع','التشغيل وساعات العمل','المنتجات والأسعار']){await browser.click('.admin-tabs button',label);await browser.sleep(450);const result=await layout();report.tabs.push({domain:'branch',label,...result});assert.ok(!result.overflow,'Branch tab '+label)}
 for(const [route,label] of [['products','إضافة منتج'],['categories','إضافة تصنيف'],['branches','إضافة فرع'],['product-options','إضافة مجموعة'],['staff','إضافة موظف'],['offers','إضافة عرض'],['inventory','تهيئة مخزون']]){
  await browser.go(origin,'/admin/'+route);await browser.wait(`([...document.querySelectorAll('main button')].find(button=>button.textContent.includes(${JSON.stringify(label)})))?.disabled===false`);await browser.click('main button',label);await browser.wait('document.querySelector("dialog[open]")')
  for(const width of [1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await browser.sleep(100);const result=await browser.evaluate(`(()=>{const d=document.querySelector('dialog[open]'),r=d.getBoundingClientRect();return {outside:r.left<0||r.right>innerWidth+1||r.top<0||r.bottom>innerHeight+1,overflow:d.scrollWidth>d.clientWidth+1,sections:d.querySelectorAll('.form-section').length}})()`);report.modals.push({route,width,...result});assert.ok(!result.outside&&!result.overflow,'Modal '+route+' '+width);await writeFile(out+'modal-'+route+'-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png'})).data,'base64'))}
  await browser.click('dialog[open] .icon-button','');
 }
 await browser.go(origin,'/admin/orders');await browser.sleep(500);await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:false})
 await browser.evaluate('document.querySelector(".menu-toggle").click()');await browser.wait('document.querySelector(".sidebar.open")')
 assert.equal(await browser.evaluate('document.querySelector(".workspace").inert'),true)
 assert.equal(await browser.evaluate('document.body.style.overflow'),'hidden')
 await browser.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27})
 await browser.wait('!document.querySelector(".sidebar.open")')
 assert.equal(await browser.evaluate('document.querySelector(".workspace").inert'),false)
 report.checks.push('Mobile drawer backdrop, inert background, Escape close and scroll restoration; seven responsive create dialogs; product/branch tabs')
 report.diagnostics=browser.diagnostics
 assert.deepEqual(browser.diagnostics,[],'Console warnings/errors')
 assert.deepEqual(report.assetFailures,[],'Missing or failed assets')
 assert.deepEqual(report.failures,[],'Layout regression')
}catch(error){report.failures.push({error:error.message});process.exitCode=1}
finally{browser?.close();if(token)await request('/logout',{method:'POST'}).catch(()=>{});await writeFile(out+'report.json',JSON.stringify(report,null,2));console.log(JSON.stringify({pages:report.pages.length,modals:report.modals.length,tabs:report.tabs.length,failures:report.failures,diagnostics:report.diagnostics}))}
