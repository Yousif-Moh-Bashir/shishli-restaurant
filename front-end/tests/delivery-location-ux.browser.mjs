import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { mkdir, writeFile } from 'node:fs/promises'
import { browserClient } from './browser-client.mjs'
const origin=process.env.DELIVERY_UX_ORIGIN||'http://127.0.0.1:8088',before=process.env.DELIVERY_UX_BEFORE==='1'
const output=resolve('.delivery-ux-check'),browser=await browserClient(9394,output),id=n=>`${String(n).padStart(8,'0')}-1111-4111-8111-111111111111`
const branch={id:id(1),name:'فرع الاختبار',is_active:true,accepts_orders:true,supports_pickup:true,supports_delivery:true,location:{city:'تبوك',latitude:28.4374,longitude:36.5458},delivery_coverage:{city:'تبوك',districts:['المصيف','المروج'],has_radius_zones:true}}
const status={is_open:true,accepts_orders:true,pickup:{available:true},delivery:{available:true}}
const cart={id:id(2),branch,status:'active',items:[{id:id(3),product:{id:id(4),name:'وجبة اختبار'},quantity:1,options:[],pricing:{unit_price:'80.00',line_total:'80.00',line_total_after_discount:'80.00'},issues:[]}],summary:{items_count:1,quantity_total:1,subtotal:'80.00',discount_total:'0.00',total:'80.00'},availability:status,can_checkout:true,issues:[]}
const settings={restaurant:{name:'شيشلي',timezone:'Asia/Riyadh',currency:'SAR'},orders:{enabled:true,maintenance_mode:false,allow_guest_checkout:true,allow_scheduled_orders:true},pickup:{enabled:true},delivery:{enabled:true,require_coordinates:false}}
const reverse={display_name:'حي المصيف، تبوك، شارع الملك خالد',address:{city:'تبوك',suburb:'المصيف',road:'شارع الملك خالد',postcode:'47311'}}
const quote=()=>({available:!outside,delivery_fee:'5.00',estimated_total:String(Number(cart.summary.total)+5)+'.00',minimum_order:'75.00',minimum_order_met:true,zone:outside?null:{id:id(8),name:'منطقة التوصيل'},issue:outside?{code:'ADDRESS_OUTSIDE_DELIVERY_AREA',message:'العنوان خارج نطاق التوصيل.'}:null})
let outside=false,geocodeFailure=false,quoteFailure=false,addresses=[],checkoutBody,geoMode='success',geoAccuracy=30,holdQuote=false,heldQuotes=[],holdReverse=false,heldReverse=[]
const requests=[],failures=[],report={origin,transport:'controlled browser fixtures against Docker frontend',checks:[],viewports:[],screenshots:[]}
const record=message=>{report.checks.push(message);console.log(message)}
const fulfill=(requestId,status,data,raw=false)=>browser.send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID'}],body:Buffer.from(JSON.stringify(raw?data:{success:status<400,data,message:status<400?'Success':'تعذر تنفيذ العملية',errors:{}})).toString('base64')})
const screenshot=async name=>{await mkdir(output,{recursive:true});const result=await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(resolve(output,name),Buffer.from(result.data,'base64'));report.screenshots.push(name)}
const geoScript=`window.__geoMode='success';window.__geoAccuracy=30;window.__geoCalls=0;Object.defineProperty(navigator,'geolocation',{configurable:true,value:{getCurrentPosition(ok,fail){window.__geoCalls++;const mode=window.__geoMode;setTimeout(()=>mode==='success'?ok({coords:{latitude:28.4374,longitude:36.5458,accuracy:window.__geoAccuracy}}):fail({code:mode==='denied'?1:mode==='timeout'?3:2}),80)}}});`
async function reset(auth=false,mode='success'){
 geoMode=mode;outside=false;geocodeFailure=false;quoteFailure=false;holdQuote=false;holdReverse=false
 await browser.evaluate(`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});localStorage.setItem('shishli_cart_token','g'.repeat(64));${auth?"localStorage.setItem('shishli_auth_token','fixture-token');":''}`)
 await browser.go(origin,'/checkout');await browser.wait('document.querySelector("[value=delivery]")');await browser.evaluate(`window.__geoMode=${JSON.stringify(mode)};window.__geoAccuracy=${geoAccuracy};document.querySelector('[value=delivery]').click()`)
 await browser.wait('document.querySelector(".checkout-address-section")')
 await browser.input('[name=customer_name]','عميل اختبار');await browser.input('[name=customer_phone]','0551234567')
}
async function gps(){await browser.click('.location-actions button',before?'تحديد موقعي الحالي':'استخدام موقعي الحالي');await browser.wait('document.querySelector(".selected-address")')}
async function modalQuote(){await browser.wait('document.querySelector(".sheet-delivery-status")?.textContent.includes("التوصيل متاح")')}
async function dragMap(){if(await browser.evaluate('Boolean(document.querySelector(".map-frame.is-locked"))'))await browser.click('.location-map-picker button','تعديل الموقع على الخريطة');if(await browser.evaluate('Boolean(document.querySelector(".map-frame.is-locked"))'))await browser.click('.location-map-picker button','تعديل الموقع على الخريطة');const rect=await browser.evaluate('(()=>{const r=document.querySelector(".location-sheet .location-map").getBoundingClientRect();return{x:r.x+r.width*.65,y:r.y+r.height*.6}})()');await browser.send('Input.dispatchMouseEvent',{type:'mouseMoved',...rect});await browser.send('Input.dispatchMouseEvent',{type:'mousePressed',...rect,button:'left',clickCount:1});await browser.send('Input.dispatchMouseEvent',{type:'mouseMoved',x:rect.x-60,y:rect.y-20,button:'left',buttons:1});await browser.sleep(50);assert.equal(await browser.evaluate('Boolean(document.querySelector(".delivery-quote"))'),false,'Quote disappears as soon as the map moves');await browser.send('Input.dispatchMouseEvent',{type:'mouseReleased',x:rect.x-60,y:rect.y-20,button:'left',clickCount:1})}
try{
 browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
  try{
   const url=new URL(request.url),path=url.pathname,body=request.postData?JSON.parse(request.postData):{},method=request.method
   if(url.hostname==='tile.openstreetmap.org'){await browser.send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'image/png'}],body:'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jG1kAAAAASUVORK5CYII='});return}
   if(url.hostname==='nominatim.openstreetmap.org'||path.startsWith('/map-geocoding/')){
    requests.push({path,method})
    if(path.endsWith('/autocomplete')){await fulfill(requestId,200,{features:[{geometry:{coordinates:[36.55,28.44]},properties:{osm_id:80,osm_type:'W',name:'حي المصيف',street:'شارع الملك خالد',district:'المصيف',city:'تبوك',countrycode:'SA'}},{geometry:{coordinates:[44.4,33.3]},properties:{name:'المصيف في العراق',city:'بغداد',countrycode:'IQ'}},{geometry:{coordinates:[46.7,24.7]},properties:{name:'المصيف في الرياض',city:'الرياض',countrycode:'SA'}}]},true);return}
    if(path.endsWith('/search')){await fulfill(requestId,200,[{...reverse,place_id:80,lat:'28.44',lon:'36.55'}],true);return}
    if(holdReverse){heldReverse.push(requestId);return}
    await fulfill(requestId,200,geocodeFailure?{error:'unavailable'}:reverse,true);return
   }
   if(method==='OPTIONS'){await fulfill(requestId,200,null);return}
   requests.push({path,method,body});let data=[],code=200
   if(path==='/api/user')data={uuid:id(20),name:'عميل اختبار',phone:'0551234567',is_active:true}
   else if(path.endsWith('/admin/me'))code=403
   else if(path.endsWith('/settings/public'))data=settings
   else if(path.endsWith('/branches'))data=[branch]
   else if(path.endsWith('/branches/'+branch.id))data=branch
   else if(path.endsWith('/status'))data=status
   else if(path.endsWith('/cart'))data=cart
   else if(path.endsWith('/cart/delivery/quote')){
    if(holdQuote){heldQuotes.push({requestId,data:quote()});return}
    if(quoteFailure){await browser.send('Fetch.failRequest',{requestId,errorReason:'ConnectionFailed'});return}
    data=quote()
   }else if(path.includes('/addresses')){
    const match=path.match(/\/addresses\/([^/]+)(\/default)?$/),item=match?addresses.find(item=>item.id===match[1]):null
    if(method==='POST'){data={...body,id:id(30+addresses.length),location:{latitude:body.latitude,longitude:body.longitude},is_default:!addresses.length||body.is_default};addresses.push(data);code=201}
    else if(match&&!item)code=404
    else if(method==='DELETE'){addresses=addresses.filter(address=>address!==item);data=null}
    else if(method==='PATCH'){if(match[2]){addresses.forEach(address=>address.is_default=false);item.is_default=true}else Object.assign(item,body,{location:{latitude:body.latitude,longitude:body.longitude}});data=item}
    else data=item||addresses
   }else if(path.endsWith('/checkout')){
    checkoutBody=body;code=201;data={id:id(50),order_number:'SH-UX',type:body.type,status:'pending',customer:body.customer,address:body.address,items:cart.items,branch,placed_at:new Date().toISOString(),pricing:{subtotal:'80.00',total:'85.00',delivery_fee:'5.00'},payment:{method:'cash',status:'pending'},access_token:'o'.repeat(64)}
   }else if(path.endsWith('/orders/'+id(50)+'/guest'))data={id:id(50),order_number:'SH-UX',type:'delivery',status:'pending',customer:checkoutBody.customer,address:checkoutBody.address,items:cart.items,branch,pricing:{total:'85.00'},payment:{method:'cash',status:'pending'}}
   await fulfill(requestId,code,data)
  }catch(error){if(!String(error?.message||error).includes('Invalid InterceptionId'))failures.push(error.message)}
 })
 await browser.send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/v1/*'},{urlPattern:'*://*/api/user'},{urlPattern:'*://nominatim.openstreetmap.org/*'},{urlPattern:'*://*/map-geocoding/*'},{urlPattern:'*://tile.openstreetmap.org/*'}]})
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:geoScript})
 await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
 await browser.go(origin,'/');await reset();await gps()
 if(before){await screenshot('before-mobile.png');record('Before screenshot captured from current Docker frontend');}
 else{
  await modalQuote();assert.ok(await browser.evaluate('document.querySelector("dialog.location-sheet").open'));assert.ok(await browser.evaluate('Boolean(document.querySelector(".fixed-map-pin"))'));await screenshot('after-mobile.png');record('GPS accepted; fixed center pin; automatic coverage and fee; mobile bottom sheet')
  const counts=requests.filter(r=>r.path.endsWith('/autocomplete')).length
  assert.equal(await browser.evaluate('[...document.querySelectorAll(".delivery-result-details dt")].some(e=>e.textContent.includes("الحد الأدنى"))'),false,'Minimum order hidden by default')
  settings.delivery.show_minimum_order=true;await reset();await gps();await modalQuote()
  assert.equal(await browser.evaluate('[...document.querySelectorAll(".delivery-result-details dt")].some(e=>e.textContent.includes("الحد الأدنى"))'),true,'Minimum order shown when enabled')
  settings.delivery.show_minimum_order=false;await reset();await gps();await modalQuote()
  assert.equal(await browser.evaluate('[...document.querySelectorAll(".delivery-result-details dt")].some(e=>e.textContent.includes("الحد الأدنى"))'),false,'Minimum order hidden when disabled')
  record('Minimum order visibility follows public delivery setting; default hidden, enabled shown, disabled hidden')
  assert.ok(await browser.evaluate('Boolean(document.querySelector(".map-frame.is-locked"))'),'GPS locks map')
  const lockedQuotes=requests.filter(r=>r.path.endsWith('/cart/delivery/quote')).length
  const lockedRect=await browser.evaluate('(()=>{const r=document.querySelector(".location-map").getBoundingClientRect();return{x:r.x+r.width*.6,y:r.y+r.height*.5}})()')
  await browser.send('Input.dispatchMouseEvent',{type:'mousePressed',...lockedRect,button:'left',clickCount:1})
  await browser.send('Input.dispatchMouseEvent',{type:'mouseMoved',x:lockedRect.x-60,y:lockedRect.y-20,button:'left',buttons:1})
  await browser.send('Input.dispatchMouseEvent',{type:'mouseReleased',x:lockedRect.x-60,y:lockedRect.y-20,button:'left',clickCount:1})
  await browser.sleep(1300)
  assert.equal(requests.filter(r=>r.path.endsWith('/cart/delivery/quote')).length,lockedQuotes,'Accidental drag does not change location or quote')
  assert.ok(await browser.evaluate('document.querySelector(".sheet-delivery-status").textContent.includes("التوصيل متاح")'))
  record('GPS locks map; accidental dragging preserves location and current coverage')
  await browser.input('#location-search-input','حي');await browser.sleep(800);assert.equal(requests.filter(r=>r.path.endsWith('/autocomplete')).length,counts,'Short queries do not search')
  await browser.input('#location-search-input','شارع الملك');await browser.sleep(150);await browser.input('#location-search-input','شارع الملك خالد');await browser.wait('document.querySelector(".location-search-results button")');assert.equal(await browser.evaluate('document.querySelectorAll(".location-search-results button").length'),1,'Foreign/other-city results excluded');assert.equal(requests.filter(r=>r.path.endsWith('/autocomplete')).length,counts+1,'Typing is debounced');assert.equal(requests.filter(r=>r.path.endsWith('/search')).length,0,'No Nominatim autocomplete')
  await browser.input('#location-search-input','');assert.equal(await browser.evaluate('Boolean(document.querySelector(".location-search-results button"))'),false,'Clearing query clears results immediately')
  await browser.input('#location-search-input','شارع الملك خالد');await browser.wait('document.querySelector(".location-search-results button")');await browser.click('.location-search-results button','حي المصيف');await modalQuote();record('Automatic Photon suggestions while typing; debounce, Saudi/branch-city scope, short-query guard and result selection; no Nominatim autocomplete')

  holdQuote=true;await dragMap();await browser.wait('document.querySelector(".selected-address") && document.querySelector(".sheet-delivery-status")?.textContent.includes("جارٍ التحقق")');await browser.wait('true');for(let i=0;i<80&&!heldQuotes.length;i++)await browser.sleep(100);assert.ok(heldQuotes.length)
  await dragMap();holdQuote=false;outside=true
  for(const pending of heldQuotes.splice(0)){try{await fulfill(pending.requestId,200,pending.data)}catch{}}
  await browser.wait('document.querySelector(".sheet-delivery-status")?.textContent.includes("خارج نطاق")');assert.equal(await browser.evaluate('Boolean(document.querySelector(".delivery-result-fee"))'),false);record('Map drag clears old coverage immediately; late old quote ignored; outside coverage shown')
  outside=false;await browser.click('.sheet-gps-actions button','العودة إلى موقعي');await modalQuote();record('Recenter returns to GPS location and recalculates coverage')
  await browser.send('Emulation.setTouchEmulationEnabled',{enabled:true,maxTouchPoints:5})
  assert.ok(await browser.evaluate('Boolean(document.querySelector(".map-frame.is-locked"))'),'Recenter locks map')
  await browser.click('.location-map-picker button','تعديل الموقع على الخريطة')
  const touch=await browser.evaluate('(()=>{const r=document.querySelector(".location-sheet .location-map").getBoundingClientRect();return{x:r.x+r.width*.7,y:r.y+r.height*.6}})()')
  await browser.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[touch]});await browser.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:touch.x-50,y:touch.y-15}]});await browser.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await modalQuote();await browser.send('Emulation.setTouchEmulationEnabled',{enabled:false});record('Touch drag on mobile map settles and recalculates delivery')
  for(const [width,height] of [[320,568],[360,800],[375,812],[390,844],[430,932],[768,1024],[1280,900]]){
   await browser.send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<768});await browser.sleep(180)
   assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'page overflow '+width)
   assert.ok(await browser.evaluate('(()=>{const d=document.querySelector(".location-sheet");return d.scrollWidth<=d.clientWidth+1&&d.getBoundingClientRect().left>=-1&&d.getBoundingClientRect().right<=innerWidth+1})()'),'sheet overflow '+width)
   report.viewports.push({width,height,overflow:false})
  }
  await screenshot('after-desktop.png');await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
  await modalQuote();await browser.click('.location-sheet-footer button','تأكيد هذا الموقع');await browser.wait('!document.querySelector(".location-sheet").open')
  await browser.click('.location-actions button','اختيار الموقع');await browser.wait('document.querySelector(".map-frame.is-locked")');record('Confirming location and reopening the map keeps it locked')
  await browser.click('.sheet-close','×')
  await browser.wait('!document.querySelector(".auth-submit").disabled');await browser.click('.auth-submit','تأكيد الطلب');await browser.wait('location.pathname.includes("order-success")')
  assert.equal(checkoutBody.payment_method,'cash');assert.equal(typeof checkoutBody.address.latitude,'number');assert.equal('delivery_fee' in checkoutBody,false);record('Complete cash delivery checkout through browser fixtures; server fee is not sent by client')
  await reset(false,'denied');await browser.click('.location-actions button','استخدام موقعي الحالي');await browser.wait('document.querySelector(".location-sheet").textContent.includes("تم رفض إذن")');record('GPS permission denial has explicit recovery message')
  await reset(false,'timeout');await browser.click('.location-actions button','استخدام موقعي الحالي');await browser.wait('document.querySelector(".location-sheet").textContent.includes("انتهت مهلة")');record('GPS timeout has explicit recovery message')
  geoAccuracy=450;await reset();await gps();await browser.wait('document.querySelector(".gps-accuracy")?.textContent.includes("450")');await modalQuote();record('Low GPS accuracy displayed in meters');geoAccuracy=30
  geocodeFailure=true;await dragMap();await browser.wait('document.querySelector(".location-sheet").textContent.includes("تعذر جلب وصف")');assert.equal(await browser.evaluate('document.querySelector(".location-sheet-footer button").disabled'),true)
  await browser.input('.location-sheet [name=city]','تبوك');await browser.input('.location-sheet [name=district]','المصيف');await modalQuote();record('Reverse failure preserves point and allows manual description before quoting')
  geocodeFailure=false;await browser.click('.sheet-close','×');await browser.click('.location-actions button','كتابة العنوان يدويًا');await browser.input('[name=city]','تبوك');await browser.input('[name=district]','المصيف');await browser.wait('document.querySelector(".delivery-quote")')
  assert.equal(requests.filter(r=>r.path.endsWith('/cart/delivery/quote')).at(-1).body.address.latitude,null)
  await browser.input('[name=district]','المروج');assert.equal(await browser.evaluate('Boolean(document.querySelector(".delivery-quote"))'),false);await browser.wait('document.querySelector(".delivery-quote")');record('Manual covered district selection; changing address invalidates previous quote')
  settings.delivery.require_coordinates=true;await reset();assert.equal(await browser.evaluate('[...document.querySelectorAll(".location-actions button")].some(b=>b.textContent.includes("يدويًا"))'),false);record('Coordinates requirement cannot be bypassed by text-only entry');settings.delivery.require_coordinates=false
  await reset(true);await browser.wait('document.querySelector(".address-book")');await browser.click('.address-book button','إضافة عنوان');await browser.wait('document.querySelector(".address-editor")');await browser.input('[name=recipient_name]','مستلم اختبار');await browser.input('[name=phone]','0551234567');await browser.click('.address-editor .location-actions button','استخدام موقعي الحالي');await browser.wait('document.querySelector(".location-sheet-footer button")&&!document.querySelector(".location-sheet-footer button").disabled');await browser.click('.location-sheet-footer button','تأكيد هذا الموقع');await browser.click('.address-editor [type=submit]','حفظ العنوان');await browser.wait('document.querySelector(".address-card")&&!document.querySelector(".address-editor")');await browser.wait('document.querySelector(".delivery-quote")');assert.ok(requests.filter(r=>r.path.endsWith('/cart/delivery/quote')).at(-1).body.address_uuid)
  await browser.click('.address-card button','تعديل');await browser.wait('document.querySelector(".address-editor")');await browser.click('.address-editor .location-actions button','اختيار الموقع');await browser.wait('document.querySelector(".location-sheet").open');await browser.evaluate('document.querySelector(".location-sheet .address-text-details").open=true');await browser.input('.location-sheet [name=street]','شارع جديد');await browser.click('.location-sheet-footer button','تأكيد هذا الموقع');await browser.click('.address-editor [type=submit]','حفظ العنوان');await browser.wait('!document.querySelector(".address-editor")');assert.equal(addresses[0].street,'شارع جديد');record('Saved address create/edit and quote by UUID preserved')
  assert.deepEqual(failures,[])
  const unexpected=browser.diagnostics.filter(m=>!m.includes('Failed to load resource')&&!m.includes('ERR_CONNECTION_FAILED')&&!m.includes('ERR_ABORTED'))
  assert.deepEqual(unexpected,[]);record('No JavaScript exceptions; responsive checks at seven viewport sizes')
 }
 await writeFile(resolve(output,before?'before-report.json':'report.json'),JSON.stringify(report,null,2));console.log('Delivery UX browser checks passed')
}finally{browser.close()}
