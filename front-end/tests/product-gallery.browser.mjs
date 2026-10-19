// Real Chrome layout checks with isolated API/image fixtures; no restaurant data is changed.
import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {writeFile} from 'node:fs/promises'
import {browserClient} from './browser-client.mjs'
const browser=await browserClient(9396,resolve('.performance-check/product-gallery'))
const failures=[]
browser.onEvent('Network.loadingFailed',event=>failures.push(event.errorText))
const origin=process.env.FRONTEND_ORIGIN||'http://127.0.0.1:5176'
const uuid='11111111-1111-4111-8111-111111111111'
const branch={id:uuid,name:'المصيف',is_active:true,location:{},supports_pickup:true}
const photo=(id,width,height,primary=false)=>({id:String(id),sort_order:id,is_primary:primary,alt_text:'صورة '+id,url:'data:image/svg+xml,'+encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="#eadfd6"/><text x="20" y="50" font-size="36">${id}</text></svg>`)})
const photos=[photo(5,4000,1000),photo(2,800,4000),photo(8,5000,3000),photo(1,3000,3000),photo(4,4000,2200,true),photo(7,900,3500),photo(6,3500,900),photo(3,2000,1800)]
let images=photos
const success=data=>({success:true,message:'Success',data,errors:null})
browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
 try{
 const path=new URL(request.url).pathname
 let data=[]
 if(path.includes('/products/'))data={id:uuid,name:'منتج متعدد الصور',images,primary_image:images.find(item=>item.is_primary)||null,option_groups:[],price:'25.00',available:true,category:{name:'أطباق'}}
 else if(path.endsWith('/branches'))data=[branch]
 else if(path.endsWith('/settings/public'))data={restaurant:{name:'شيشلي',currency:'SAR',timezone:'Asia/Riyadh'},orders:{enabled:true}}
 else if(path.endsWith('/status'))data={accepts_orders:true,pickup:{available:true},delivery:{available:false}}
 else if(path.endsWith('/business-hours'))data={days:[]}
 else if(path.endsWith('/cart'))data={id:uuid,token:'gallery-fixture',branch,status:'active',items:[],summary:{quantity_total:0,items_count:0,total:'0.00'}}
 await browser.send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID'},{name:'Access-Control-Allow-Methods',value:'GET,POST,OPTIONS'}],body:Buffer.from(JSON.stringify(success(data))).toString('base64')})
 }catch(error){failures.push(JSON.stringify(error))}
})
try{
 await browser.send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/v1/*'},{urlPattern:'*://*/api/user'}]})
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:'localStorage.clear();sessionStorage.clear()'})
 await browser.go(origin,'/product/'+uuid)
 await browser.wait('document.querySelectorAll(".product-image-thumbnails button").length===8')
 assert.deepEqual(await browser.evaluate('[...document.querySelectorAll(".product-image-thumbnails img")].map(image=>image.alt)'),['صورة 4','صورة 1','صورة 2','صورة 3','صورة 5','صورة 6','صورة 7','صورة 8'])
 for(const width of [320,375,390,768,1440]){
  await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<700})
  await browser.wait('[...document.querySelectorAll(".product-detail-image img")].every(image=>image.complete&&image.naturalWidth>0)')
  const before=await browser.evaluate(`(()=>{const main=document.querySelector('.product-detail-image .product-photo').getBoundingClientRect();return {width:main.width,height:main.height}})()`)
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true,'Page overflow at '+width)
  assert.equal(await browser.evaluate('[...document.querySelectorAll(".product-image-thumbnails button")].every(button=>{const r=button.getBoundingClientRect();return r.width<=64&&r.height<=64&&r.width>=44})'),true)
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".product-photo>img")).objectFit'), 'contain')
  await browser.evaluate('document.querySelectorAll(".product-image-thumbnails button")[2].click()')
  await browser.wait('document.querySelector(".product-photo>img")?.alt==="صورة 2"')
  const after=await browser.evaluate(`(()=>{const main=document.querySelector('.product-detail-image .product-photo').getBoundingClientRect();return {width:main.width,height:main.height}})()`)
  assert.deepEqual(after,before,'Image selection must not change gallery dimensions')
  assert.equal(await browser.evaluate('document.querySelectorAll(".product-image-thumbnails button[aria-pressed=true]").length'),1)
  await browser.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9})
  const shot=await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})
  await writeFile(resolve('.performance-check/product-gallery',width+'.png'),Buffer.from(shot.data,'base64'))
 }
 for(const count of [1,0]){
  images=photos.slice(0,count)
  await browser.go(origin,'/product/'+uuid)
  await browser.wait('document.querySelector("#product-detail")')
  assert.equal(await browser.evaluate('document.querySelectorAll(".product-image-thumbnails").length'),0)
  if(!count)assert.equal(await browser.evaluate('document.querySelector(".api-no-image")?.textContent'),'لا توجد صورة للمنتج')
 }
 assert.deepEqual(browser.diagnostics,[])
 console.log('PASS: ordered gallery, bounded thumbnails, no overflow at 320/375/390/768/1440px, stable image switching, single/no image states')
}catch(error){console.error({failures,page:await browser.evaluate('({ready:document.readyState,html:document.body.innerHTML.slice(0,400)})')});throw error}finally{browser.close()}
