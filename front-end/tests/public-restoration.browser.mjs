import {browserClient} from './browser-client.mjs'
import {createServer} from 'node:http'
import {readFile,writeFile,mkdir} from 'node:fs/promises'
import {resolve,extname,sep} from 'node:path'
import assert from 'node:assert/strict'
const origin='http://127.0.0.1:5176',api='http://127.0.0.1:8000/api/v1',out=resolve('audit/public-restoration'),legacy=resolve('../Şişli'),report={pages:[],visual:[],boundaries:[],diagnostics:[],assetFailures:[],failures:[]}
const server=createServer(async(request,response)=>{try{const path=resolve(legacy,'.'+decodeURIComponent(new URL(request.url,'http://localhost').pathname));if(!path.startsWith(legacy+sep))throw Error('Invalid path');response.setHeader('Content-Type',({'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript','.png':'image/png','.jpeg':'image/jpeg'})[extname(path)]||'application/octet-stream');response.end(await readFile(path))}catch{response.writeHead(404);response.end()}})
await new Promise(resolve=>server.listen(5177,'127.0.0.1',resolve));await mkdir(out,{recursive:true})
let browser,mode='missing'
const get=async path=>{const response=await fetch(api+path,{headers:{Accept:'application/json'}});assert.ok(response.ok,path+' '+response.status);return response.json()}
const metrics=`(()=>{const style=selector=>{const element=document.querySelector(selector);if(!element)return null;const css=getComputedStyle(element);return {fontSize:css.fontSize,color:css.color,padding:css.padding,backgroundSize:css.backgroundSize,backgroundPosition:css.backgroundPosition,minHeight:css.minHeight,height:element.getBoundingClientRect().height}};return {header:style('.header-inner'),hero:style('.hero-inner'),art:style('.hero-art'),h1:style('.hero h1'),h2:style('.hero h2'),features:style('.features-grid'),brand:style('.brand-image')}})()`
try{
 const branch=(await get('/branches')).data[0],menu=(await get('/menu?branch='+branch.id)).data,product=menu.categories.flatMap(category=>category.products)[0]
 browser=await browserClient(9320,out+'/browser')
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`if(location.port==='5176')localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)})`})
 await browser.send('Network.enable');browser.onEvent('Network.responseReceived',({type,response})=>{if(['Image','Font','Stylesheet','Script'].includes(type)&&response.status>=400)report.assetFailures.push({url:response.url,status:response.status})})
 browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
  try{
  const path=new URL(request.url).pathname;let status=200,data
  if(!['GET','OPTIONS'].includes(request.method)){status=422;data={success:false,message:'Isolated UI check; no business mutation',errors:{}}}
  else if(path.endsWith('/home')&&mode!=='live'){
   if(mode==='override')data={success:true,data:{page:{title:'شيشلي',sections:[{key:'hero',title:'عنوان منشور',subtitle:'عنوان ثانوي',content:'وصف منشور',data:{}}]},banners:[],active_offers:[],secondary_banners:[]}}
   else {status=mode==='cms-error'?503:404;data={success:false,message:'CMS unavailable'}}
  }else if(mode==='products-error'&&path.endsWith('/menu')){status=503;data={success:false,message:'Products unavailable'}}
  else if(mode==='gallery-error'&&path.endsWith('/gallery')){status=503;data={success:false,message:'Gallery unavailable'}}
  else if(mode==='operations-error'&&path.endsWith('/status')){status=503;data={success:false,message:'Operations unavailable'}}
  else if(['hours','hours24'].includes(mode)&&path.endsWith('/business-hours'))data={success:true,data:{timezone:'Asia/Riyadh',days:Array.from({length:7},(_,day_of_week)=>({day_of_week,periods:mode==='hours24'?[{opens_at:'00:00:00',closes_at:'12:00:00'},{opens_at:'12:00:00',closes_at:'00:00:00'}]:[{opens_at:'11:00:00',closes_at:'02:00:00'},{opens_at:'11:00:00',closes_at:'02:00:00'}]}))}}
  if(data)await browser.send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:origin}],body:Buffer.from(JSON.stringify(data)).toString('base64')});else await browser.send('Fetch.continueRequest',{requestId})
  }catch(error){if(!/Invalid InterceptionId|Invalid.*id/i.test(error.message||''))report.failures.push(error.message||JSON.stringify(error))}
 })
 await browser.send('Fetch.enable',{patterns:[{urlPattern:api+'/*',requestStage:'Request'}]})
 for(const width of [1920,1440,768,390]){
  await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false})
  await browser.go('http://127.0.0.1:5177','/index.html');await browser.evaluate('document.fonts.ready');const original=await browser.evaluate(metrics)
  await writeFile(out+'/original-home-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'))
  await browser.go(origin,'/');await browser.wait('document.querySelector(".hero h1")');await browser.evaluate('document.fonts.ready');const current=await browser.evaluate(metrics)
  for(const key of Object.keys(original))for(const property of ['fontSize','color','padding','backgroundSize','backgroundPosition','minHeight'])assert.equal(current[key][property],original[key][property],key+' '+property+' '+width)
  assert.ok(Math.abs(original.hero.height-current.hero.height)<1)
  assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
  report.visual.push({width,original,current,equal:true})
  await browser.wait('document.querySelector("#site-branch")?.value&&!document.querySelector("#site-branch").disabled');await browser.wait('!document.querySelector(".popular .app-skeleton,.public-business-hours .app-skeleton")');await writeFile(out+'/restored-home-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'))
 }
 const routes=['/','/menu','/product/'+product.id,'/cart','/checkout','/login','/register','/about','/gallery','/contact','/branches','/offers','/track-order','/order-success']
 mode='live'
 for(const route of routes){await browser.go(origin,route);await browser.sleep(600);for(const width of [1920,1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Overflow '+route+' '+width);report.pages.push({route,width,overflow:false})}}
 for(const scenario of ['cms-error','products-error','gallery-error','operations-error','override','hours','hours24']){
  mode=scenario;await browser.go(origin,'/');await browser.wait('document.querySelector(".hero h1")');await browser.sleep(1000)
  assert.ok(await browser.evaluate('!!document.querySelector(".hero-art")&&!!document.querySelector(".site-header")&&!!document.querySelector(".site-footer")'))
  assert.equal(await browser.evaluate('document.body.innerText.includes("تحديث الحالة")'),false)
  if(scenario==='override')assert.equal(await browser.evaluate('document.querySelector(".hero h1").textContent'),'عنوان منشور')
  if(scenario==='hours'){await browser.wait('document.querySelector(".hours-group")');assert.equal(await browser.evaluate('document.querySelectorAll(".hours-group").length'),1);assert.equal(await browser.evaluate('document.querySelector(".hours-group p").textContent.split("11:00").length-1'),1)}
  if(scenario==='hours24'){await browser.wait('document.querySelector(".hours-group p")?.textContent==="على مدار 24 ساعة"');assert.equal(await browser.evaluate('document.querySelectorAll(".hours-group").length'),1)}
  if(scenario==='operations-error'){await browser.wait('document.querySelector(".branch-operation-note")?.textContent.includes("تعذر التحقق")');assert.equal(await browser.evaluate('document.querySelector(".branch-operation-note").textContent.includes("مفتوح الآن")'),false)}
  report.boundaries.push(scenario)
 }
 report.diagnostics=browser.diagnostics;assert.deepEqual(report.diagnostics,[]);assert.deepEqual(report.assetFailures,[])
}catch(error){report.failures.push(error.message);process.exitCode=1}
finally{browser?.close();server.close();await writeFile(out+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify({pages:report.pages.length,visual:report.visual.length,boundaries:report.boundaries,diagnostics:report.diagnostics,assetFailures:report.assetFailures,failures:report.failures}))}
