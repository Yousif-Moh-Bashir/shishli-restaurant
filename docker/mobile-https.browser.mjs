import assert from 'node:assert/strict'
import fs from 'node:fs/promises'
import { resolve } from 'node:path'
import https from 'node:https'
import { X509Certificate } from 'node:crypto'
import { browserClient } from '../front-end/tests/browser-client.mjs'

const state=JSON.parse(await fs.readFile('.local-mobile-https/state.json','utf8')),origin=state.origin
const ca=new X509Certificate(await fs.readFile('.local-mobile-https/shishli-rootCA.crt')).toString()
const agent=new https.Agent({ca,rejectUnauthorized:true})
const readJson=path=>new Promise((resolve,reject)=>{https.get(origin+path,{agent},res=>{let data='';res.on('data',chunk=>data+=chunk);res.on('end',()=>{try{assert.equal(res.statusCode,200);resolve(JSON.parse(data).data)}catch(error){reject(error)}})}).on('error',reject)})
const branch=(await readJson('/api/v1/branches'))[0]
const menu=await readJson('/api/v1/menu?branch='+branch.id),product=menu.categories.flatMap(category=>category.products)[0]
assert.ok(branch&&product)
const id='88888888-1111-4111-8111-111111111111'
const status={is_open:true,accepts_orders:true,pickup:{available:true},delivery:{available:true}}
const cart={id,branch,status:'active',items:[{id:'99999999-1111-4111-8111-111111111111',product,quantity:1,options:[],pricing:{unit_price:product.price,line_total:product.price,line_total_after_discount:product.price},issues:[]}],summary:{items_count:1,quantity_total:1,subtotal:product.price,discount_total:'0.00',total:product.price},availability:status,can_checkout:true,issues:[],token:'t'.repeat(64)}
const browser=await browserClient(9479,resolve('.local-mobile-https/browser-test'))
const report={origin,transport:'real public HTTPS GET routes; cart/quote writes blocked and supplied only as in-memory browser fixtures',pages:[],insecureRequests:[],blockedWrites:[],apiRequests:[],tileResponses:[],exceptions:[],securityStates:[]}
const failures=[]
try{
 await browser.send('Security.enable')
 browser.onEvent('Security.visibleSecurityStateChanged',event=>report.securityStates.push(event.visibleSecurityState.securityState))
 browser.onEvent('Network.requestWillBeSent',({request})=>{if(request.url.startsWith('http://'))report.insecureRequests.push(request.url);if(request.url.startsWith(origin+'/api/'))report.apiRequests.push({path:new URL(request.url).pathname,method:request.method})})
 browser.onEvent('Network.responseReceived',({response})=>{if(response.url.startsWith('https://tile.openstreetmap.org/'))report.tileResponses.push({status:response.status,protocol:response.protocol})})
 browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
  try{
   const path=new URL(request.url).pathname
   const isCart=path==='/api/v1/cart'
   const isStatus=path==='/api/v1/branches/'+branch.id+'/status'
   if(request.method==='GET'&&!isCart&&!isStatus){await browser.send('Fetch.continueRequest',{requestId});return}
   if(request.method!=='GET')report.blockedWrites.push({path,method:request.method})
   const data=isCart?cart:isStatus?status:path.endsWith('/cart/delivery/quote')?{available:false,delivery_fee:'0.00',estimated_total:product.price,issue:{code:'ADDRESS_OUTSIDE_DELIVERY_AREA',message:'Read-only browser test'}}:null
   await browser.send('Fetch.fulfillRequest',{requestId,responseCode:isCart&&request.method==='POST'?201:data?200:409,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({success:!!data,data,message:data?'Test fixture':'Writes disabled by test',errors:{}})).toString('base64')})
  }catch(error){failures.push(error.message||String(error))}
 })
 await browser.send('Fetch.enable',{patterns:[{urlPattern:origin+'/api/v1/*'},{urlPattern:origin+'/api/user'}]})
 await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
 for(const path of ['/','/menu','/checkout']){
  await browser.go(origin,path)
  await browser.wait('document.readyState==="complete"')
  const result=await browser.evaluate('({secure:window.isSecureContext,geolocation:typeof navigator.geolocation?.getCurrentPosition==="function",overflow:document.documentElement.scrollWidth>innerWidth+1})')
  assert.equal(result.secure,true);assert.equal(result.geolocation,true);assert.equal(result.overflow,false);report.pages.push({path,...result})
 }
 await browser.wait('document.querySelector("[value=delivery]")')
 await browser.evaluate('document.querySelector("[value=delivery]").click()')
 await browser.click('.location-actions button','اختيار الموقع على الخريطة')
 await browser.wait('document.querySelector(".location-sheet .leaflet-container")')
 for(let i=0;i<50&&!report.tileResponses.some(response=>response.status===200);i++)await browser.sleep(100)
 assert.ok(report.tileResponses.some(response=>response.status===200),'Actual map tiles loaded over HTTPS')
 assert.equal(await browser.evaluate('document.querySelector(".location-sheet").scrollWidth<=document.querySelector(".location-sheet").clientWidth+1'),true)
 const screen=await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})
 await fs.mkdir('docs/docker/evidence/local-mobile-https',{recursive:true})
 await fs.writeFile('docs/docker/evidence/local-mobile-https/https-map-mobile.png',Buffer.from(screen.data,'base64'))
 await browser.click('.sheet-close','×')
 assert.deepEqual(report.insecureRequests,[],'No HTTP/mixed-content requests from browser')
 assert.deepEqual(failures,[])
 report.exceptions=browser.diagnostics.filter(message=>!message.includes('Failed to load resource'))
 assert.deepEqual(report.exceptions,[])
 assert.ok(report.securityStates.includes('secure'),'Chrome accepted the trusted local certificate without bypass flags')
 report.physicalGPS='Not tested: no physical phone/geolocation call used'
 await fs.writeFile('docs/docker/evidence/local-mobile-https/browser-report.json',JSON.stringify(report,null,2))
 console.log(JSON.stringify(report,null,2))
}finally{browser.close();agent.destroy()}
