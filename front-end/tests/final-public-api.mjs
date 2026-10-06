// Real local cash checkout. Restore hours, cancel test order, never send external messages.
import assert from 'node:assert/strict'
import {mkdir,writeFile} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
import {browserClient} from './browser-client.mjs'
const base='http://127.0.0.1:8000/api/v1',checks=[],undo=[]
let admin,customer,browser
async function call(path,method='GET',body,status=200,token=admin,headers={}){const response=await fetch(base+path,{method,headers:{Accept:'application/json',...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{}),...headers},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(20000)});const result=await response.json();assert.equal(response.status,status,path+' '+JSON.stringify(result.errors||result.message));return {data:result.data,headers:response.headers}}
try{
 admin=(await call('/login','POST',{email:'super_admin@demo.shishli.local',password:'DemoTest123!'},200,null)).data.token
 customer=(await call('/login','POST',{email:'customer1@demo.shishli.local',password:'DemoTest123!'},200,null)).data.token
 const branches=(await call('/branches','GET',undefined,200,null)).data;assert.ok(branches.length)
 const branch=branches.find(b=>b.supports_pickup&&b.accepts_orders);assert.ok(branch,'test branch accepting pickup')
 const old=(await call('/admin/branches/'+branch.id+'/business-hours')).data.days.flatMap(day=>day.periods.map(period=>({day_of_week:day.day_of_week,...period})))
 undo.push(()=>call('/admin/branches/'+branch.id+'/business-hours','PUT',{hours:old}))
 await call('/admin/branches/'+branch.id+'/business-hours','PUT',{hours:Array.from({length:7},(_,index)=>({day_of_week:index+1,opens_at:'00:00',closes_at:'23:59'}))})
 const menu=(await call('/menu?branch='+branch.id,'GET',undefined,200,null)).data,products=menu.categories.flatMap(c=>c.products),product=products.find(p=>p.is_available&&!p.tracks_inventory);assert.ok(product,'available product not tracking stock')
 const options=(product.option_groups||[]).filter(g=>g.min_select>0).map(g=>({option_group_uuid:g.id,option_value_uuids:(g.values||g.options).slice(0,g.min_select).map(o=>o.id)}))
 const cart=(await call('/cart','POST',{branch_uuid:branch.id},201,null)).data,headers={'X-Cart-Token':cart.token}
 undo.push(()=>call('/cart/items','DELETE',undefined,200,null,headers).catch(()=>{}))
 await call('/cart/items','POST',{product_uuid:product.id,quantity:1,options},201,null,headers);checks.push('real guest cart and product options')
 const name='AUDIT-CART-'+Date.now(),promotion=(await call('/admin/promotions','POST',{name,type:'coupon',application_type:'cart',discount_type:'percentage',discount_value:'10.00',is_active:true},201)).data
 undo.push(()=>call('/admin/promotions/'+promotion.id,'DELETE'))
 const code='CART'+Date.now();await call('/admin/promotions/'+promotion.id+'/coupons','POST',{code,is_active:true},201)
 const discounted=(await call('/cart/coupon','POST',{code},200,null,headers)).data;assert.equal(discounted.coupon.code,code);assert.notEqual(discounted.summary.discount_total,'0.00');checks.push('coupon evaluated by server with nonzero discount')
 await call('/cart/coupon','DELETE',undefined,200,null,headers);assert.equal((await call('/cart','GET',undefined,200,null,headers)).data.coupon,null);checks.push('coupon removal preserves server pricing')
 await call('/cart/coupon','POST',{code},200,null,headers)
 const body={type:'pickup',customer:{name:'اختبار تدقيق محلي',phone:'+966550999997'},payment_method:'cash',notes:'Local final audit; cancelled after verification',order_timing:'asap'}
 if(process.env.BROWSER_CHECKOUT==='1'){
  browser=await browserClient(9280,fileURLToPath(new URL('../.reports-check/final-checkout-real/',import.meta.url)))
  await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.removeItem('shishli_auth_token');localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});localStorage.setItem('shishli_cart_token',${JSON.stringify(cart.token)})`})
  const origin=process.env.DESIGN_APP_URL||'http://127.0.0.1:5175';await browser.go(origin,'/cart');await browser.wait('document.querySelector("main .cart-coupon")?.textContent.includes("'+code+'")')
  await browser.click('main .cart-coupon button','إزالة');await browser.wait('document.querySelector("main .cart-coupon input")');await browser.input('main .cart-coupon input',code);await browser.click('main .cart-coupon button','تطبيق');await browser.wait('document.querySelector("main .cart-coupon")?.textContent.includes("الكوبون:")');checks.push('real Vue guest cart coupon apply and remove')
  for(const width of [1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await browser.sleep(300);assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'cart overflow '+width)}
  await browser.go(origin,'/checkout');await browser.wait('document.querySelector("[name=customer_name]")');await browser.input('[name=customer_name]',body.customer.name);await browser.input('[name=customer_phone]',body.customer.phone);await browser.input('[name=order_notes]',body.notes);await browser.wait('!document.querySelector("main button.auth-submit").disabled');await browser.click('main button','تأكيد الطلب');await browser.wait('location.pathname.startsWith("/order-success/")&&document.querySelector(".checkout-confirmation")');checks.push('real Vue cash checkout success')
 }
 const checkout=await call('/checkout','POST',body,process.env.BROWSER_CHECKOUT==='1'?200:201,null,headers),order=checkout.data;assert.ok(order.id);assert.equal(order.payment.method,'cash');assert.match(checkout.headers.get('cache-control'),/private.*no-store|no-store.*private/)
 if(browser)order.tracking={token:await browser.evaluate(`sessionStorage.getItem('shishli_order_access:${order.id}')`)}
 assert.equal(order.tracking.token.length,64)
 const orderHeaders={'X-Order-Token':order.tracking.token};undo.push(()=>call('/orders/'+order.id+'/guest/cancel','POST',{reason:'إلغاء طلب التدقيق المحلي'},200,null,orderHeaders))
 assert.equal((await call('/checkout','POST',body,200,null,headers)).data.id,order.id);checks.push('cash checkout and idempotent same-cart replay')
 const guest=(await call('/orders/'+order.id+'/guest','GET',undefined,200,null,orderHeaders)).data;assert.equal(guest.id,order.id);assert.equal(guest.promotions[0].coupon_code,code);checks.push('historical order promotion snapshot')
 await call('/orders/'+order.id+'/guest','GET',undefined,404,null,{'X-Order-Token':'x'.repeat(64)});await call('/orders/'+order.id,'GET',undefined,404,customer);checks.push('wrong guest credential and other customer receive 404')
 const tracking=(await call('/orders/track','POST',{order_number:order.order_number,token:order.tracking.token},200,null)).data;assert.equal(tracking.order.uuid,order.id);assert.ok(tracking.customer.phone_masked);checks.push('guest tracking returns masked customer summary')
 if(browser){await browser.click('main a','تتبع الطلب');await browser.wait('document.querySelector(".tracking-panel")');for(const width of [1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await browser.sleep(300);assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'tracking overflow '+width)}assert.deepEqual(browser.diagnostics,[]);checks.push('real Vue guest tracking and responsive cart / tracking')}
 await call('/admin/kitchen/orders');await call('/admin/orders/'+order.id+'/history');checks.push('admin and kitchen read real test order')
 console.log(JSON.stringify({checks:checks.length}))
}finally{
 browser?.close();const errors=[];for(const restore of undo.reverse())try{await restore()}catch(e){errors.push(e.message)}for(const token of [admin,customer].filter(Boolean))await call('/logout','POST',undefined,200,token).catch(()=>{})
 await mkdir('.reports-check/final-public-api',{recursive:true});await writeFile('.reports-check/final-public-api/report.json',JSON.stringify({checks,cleanupFailures:errors},null,2));if(errors.length)throw Error(errors.join(';'))
}
