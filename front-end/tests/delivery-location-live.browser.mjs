import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { mkdir, writeFile } from 'node:fs/promises'
import { browserClient } from './browser-client.mjs'
const origin='http://127.0.0.1:8088',api=origin+'/api/v1',output=resolve('.delivery-ux-check/live')
const captureOnly=process.argv.includes('--capture-only')
const report={origin,transport:'real Docker Vue/Axios/Laravel/MySQL; browser GPS coordinates supplied by CDP',checks:[]}
let cartToken,orderToken,orderId,browser
async function call(path,method='GET',body,extraHeaders={}){
 const response=await fetch(api+path,{method,headers:{Accept:'application/json',...(body?{'Content-Type':'application/json'}:{}),...extraHeaders},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(20000)})
 const result=await response.json();assert.ok(response.ok,`${method} ${path}: ${response.status} ${result.message} ${JSON.stringify(result.errors||{})}`);return result.data
}
try{
 const branches=await call('/branches'),branch=branches.find(b=>b.supports_delivery&&b.accepts_orders);assert.ok(branch,'Delivery branch available')
 const status=await call('/branches/'+branch.id+'/status');assert.ok(status.delivery.available,'Branch open for real delivery')
 const menu=await call('/menu?branch='+branch.id),product=menu.categories.flatMap(c=>c.products).find(p=>p.available);assert.ok(product,'Available real menu item')
 const cart=await call('/cart','POST',{branch_uuid:branch.id});cartToken=cart.token;assert.ok(cartToken)
 await call('/cart/items','POST',{product_uuid:product.id,quantity:1,options:[]},{'X-Cart-Token':cartToken})
 const point={latitude:Number(branch.location.latitude),longitude:Number(branch.location.longitude)}
 const quote=await call('/cart/delivery/quote','POST',{address:{city:branch.location.city,district:branch.location.district||'المصيف',...point}},{'X-Cart-Token':cartToken});assert.ok(quote.available,'Actual location within coverage')
 const outside=await call('/cart/delivery/quote','POST',{address:{city:branch.location.city,district:branch.location.district||'المصيف',latitude:0,longitude:0}},{'X-Cart-Token':cartToken});assert.equal(outside.available,false);report.checks.push('Live API quotes accept branch location and reject an outside point')
 browser=await browserClient(9396,output)
 await browser.send('Browser.grantPermissions',{origin,permissions:['geolocation']})
 await browser.send('Emulation.setGeolocationOverride',{...point,accuracy:20})
 await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
 await browser.go(origin,'/');await browser.evaluate(`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});localStorage.setItem('shishli_cart_token',${JSON.stringify(cartToken)})`)
 await browser.go(origin,'/checkout');await browser.wait('document.querySelector("[value=delivery]")');await browser.evaluate('document.querySelector("[value=delivery]").click()')
 assert.equal(await browser.evaluate('window.isSecureContext'),true,'127.0.0.1 is treated as a secure context')
 await browser.input('[name=customer_name]','اختبار تجربة التوصيل');await browser.input('[name=customer_phone]','0550000000')
 await browser.input('[name=order_notes]','طلب اختبار تقني؛ يرجى عدم التجهيز. سيتم إلغاؤه مباشرة بعد التحقق.')
 await browser.click('.location-actions button','استخدام موقعي الحالي')
 await browser.wait('document.querySelector(".fixed-map-pin")')
 // This is real geolocation permission handling. CDP supplies a deterministic
 // test position; it does not claim hardware GPS accuracy on Android/iPhone.
 await browser.wait('!document.querySelector(".location-sheet").textContent.includes("جارٍ جلب وصف")&&!document.querySelector(".location-sheet").textContent.includes("جارٍ تحديد موقعي…")')
 await browser.sleep(1800)
 if(await browser.evaluate('!document.querySelector(".location-sheet [name=city]")?.value || !document.querySelector(".location-sheet [name=district]")?.value')){
  await browser.evaluate('document.querySelector(".location-sheet .address-text-details").open=true')
  await browser.input('.location-sheet [name=city]',branch.location.city);await browser.input('.location-sheet [name=district]',branch.location.district||'المصيف')
  report.checks.push('Real reverse description needed manual city/district completion')
 }else report.checks.push('Real reverse geocoding returned city/district')
 await browser.wait('document.querySelector(".sheet-delivery-status")?.textContent.includes("التوصيل متاح")')
 const screen=await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await mkdir(output,{recursive:true});await writeFile(resolve(output,'actual-map-mobile.png'),Buffer.from(screen.data,'base64'))
 if(captureOnly){
  await call('/cart/items','DELETE',undefined,{'X-Cart-Token':cartToken})
  report.checks.push('Updated real map screenshot captured; test cart emptied via normal API')
 }else{
 await browser.click('.location-sheet-footer button','استخدام هذا العنوان');await browser.wait('!document.querySelector(".auth-submit").disabled')
 await browser.click('.auth-submit','تأكيد الطلب');await browser.wait('location.pathname.includes("order-success")')
 orderId=await browser.evaluate('location.pathname.split("/").filter(Boolean).at(-1)');orderToken=await browser.evaluate(`localStorage.getItem('shishli_order_access:'+${JSON.stringify(orderId)})`)
 assert.ok(orderId&&orderToken,'Actual guest order credentials remain private')
 const order=await call('/orders/'+orderId+'/guest','GET',undefined,{'X-Order-Token':orderToken})
 assert.equal(order.type,'delivery');assert.equal(order.payment.method,'cash');assert.equal(order.pricing.delivery_fee,quote.delivery_fee)
 report.order={number:order.order_number,paymentMethod:order.payment.method,deliveryFee:order.pricing.delivery_fee}
 report.checks.push('Actual cash delivery order created through the updated Vue UI and Laravel; fee confirmed server-side')
 const cancelled=await call('/orders/'+orderId+'/guest/cancel','POST',{reason:'انتهى اختبار تجربة تحديد موقع التوصيل؛ طلب اختبار وليس طلب عميل.'},{'X-Order-Token':orderToken});assert.equal(cancelled.status,'cancelled');report.order.finalStatus=cancelled.status;report.checks.push('Test order cancelled through normal guest API; no volumes/data deleted')
 orderId=null
 await browser.send('Browser.setPermission',{origin,permission:{name:'geolocation'},setting:'denied'})
 const denied=await browser.evaluate('new Promise(resolve=>navigator.geolocation.getCurrentPosition(()=>resolve(0),error=>resolve(error.code),{timeout:2000}))');assert.equal(denied,1);report.checks.push('Native browser permission denial verified; loopback secure context verified')
 assert.deepEqual(browser.diagnostics.filter(m=>!m.includes('Failed to load resource')&&!m.includes('ERR_CONNECTION')&&!m.includes('ERR_ABORTED')),[])
 }
 await writeFile(resolve(output,captureOnly?'capture-report.json':'report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}finally{
 if(orderId&&orderToken){try{await call('/orders/'+orderId+'/guest/cancel','POST',{reason:'تنظيف طلب اختبار تجربة التوصيل.'},{'X-Order-Token':orderToken})}catch{console.error('The test order could not be cancelled; inspect it in administration.')}}
 browser?.close()
}
