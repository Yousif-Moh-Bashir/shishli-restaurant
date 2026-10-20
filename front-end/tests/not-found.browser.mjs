// Run against Vite preview on port 5177; API fixtures never change application data.
import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {writeFile,mkdir} from 'node:fs/promises'
import {tmpdir} from 'node:os'
import {browserClient} from './browser-client.mjs'
const output=resolve('.performance-check/not-found')
await mkdir(output,{recursive:true})
const browser=await browserClient(9398,resolve(tmpdir(),'shishli-not-found-browser'))
const origin=process.env.FRONTEND_ORIGIN||'http://127.0.0.1:5177'
const unknown='/not-a-real-page/nested'
const success=data=>({success:true,message:'Success',data,errors:null})
browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
 try{
  const path=new URL(request.url).pathname
  let data=[]
  if(path.endsWith('/settings/public'))data={restaurant:{name:'شيشلي فرش',currency:'SAR',timezone:'Asia/Riyadh'},orders:{enabled:true}}
  else if(path.endsWith('/home'))data={page:{title:'الرئيسية',sections:[]},banners:[],featured_products:[],active_offers:[],gallery_preview:[]}
  else if(path.endsWith('/cart'))data={id:'404-fixture',token:'404-fixture',status:'active',items:[],summary:{quantity_total:0,items_count:0,total:'0.00'}}
  await browser.send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID'},{name:'Access-Control-Allow-Methods',value:'GET,POST,OPTIONS'}],body:Buffer.from(JSON.stringify(success(data))).toString('base64')})
 }catch{/* Requests cancelled by navigation no longer have a valid interception ID. */}
})
try{
 // Layout assertions stay deterministic when external font services are unavailable.
 await browser.send('Network.setBlockedURLs',{urls:['*://fonts.googleapis.com/*','*://fonts.gstatic.com/*']})
 await browser.send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/v1/*'},{urlPattern:'*://*/api/user'}]})
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:'localStorage.clear();sessionStorage.clear()'})
 await browser.go(origin,unknown)
 await browser.wait('document.querySelector("#not-found-title")')
 await browser.wait('getComputedStyle(document.querySelector(".not-found-card")).opacity==="1"')
 assert.equal(await browser.evaluate('document.querySelector("#not-found-title").textContent'),'عذرًا، هذه الصفحة غير موجودة!')
 assert.equal(await browser.evaluate('document.querySelector("#not-found-description").textContent'),'يبدو أن الرابط الذي تبحث عنه غير متاح، لكن أشهى المشويات بانتظارك!')
 assert.equal(await browser.evaluate('document.querySelector("meta[name=robots]").content'),'noindex, follow')
 assert.equal(await browser.evaluate('document.querySelectorAll("main").length'),1)
 assert.equal(await browser.evaluate('document.querySelectorAll("header").length'),1)
 assert.equal(await browser.evaluate('document.querySelectorAll("footer").length'),1)
 assert.equal(await browser.evaluate('document.querySelectorAll("main h1").length'),1)
 assert.equal(await browser.evaluate('document.querySelector("#restaurant-jsonld")'),null)
 assert.deepEqual(await browser.evaluate('[...document.querySelectorAll(".not-found-actions a")].map(a=>a.getAttribute("href"))'),['/menu','/'])
 for(const width of [320,375,390,768,1440]){
  await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<700})
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true,'Overflow at '+width)
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("main")).direction'),'rtl')
  assert.equal(await browser.evaluate('[...document.querySelectorAll(".not-found-actions a")].every(a=>a.getBoundingClientRect().height>=44)'),true)
  const screenshot=await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})
  await writeFile(resolve(output,width+'.png'),Buffer.from(screenshot.data,'base64'))
 }
 await browser.send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]})
 assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".not-found-card")).animationName'),'none')
 await browser.evaluate('document.querySelector(".not-found-actions a").focus()')
 assert.equal(await browser.evaluate('document.activeElement.getAttribute("href")'),'/menu')
 await browser.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9})
 await browser.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9})
 assert.equal(await browser.evaluate('document.activeElement.getAttribute("href")'),'/')
 await browser.click('.not-found-actions a','استعرض قائمة الطعام')
 await browser.wait('location.pathname==="/menu"&&!document.querySelector("#not-found-title")')
 await browser.wait('document.querySelector("meta[name=robots]").content==="index, follow"')
 await browser.go(origin,unknown+'?from=test')
 await browser.wait('document.querySelector("#not-found-title")')
 await browser.click('.not-found-actions a','العودة للرئيسية')
 await browser.wait('location.pathname==="/"&&document.querySelector("#hero-title")')
 assert.deepEqual(browser.diagnostics,[])
 console.log('PASS: unknown nested routes, noindex, shared header/footer, RTL, keyboard navigation, reduced motion, menu/home links, no overflow at 320/375/390/768/1440px')
}finally{browser.close()}
