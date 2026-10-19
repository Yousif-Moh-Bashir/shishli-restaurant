// Browser integration tests use actual Vue/Pinia/Axios with controlled HTTP responses.
// Fixtures never enter production. Report contains no contact details or credentials.
import { spawn } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'
const output=fileURLToPath(new URL('../.performance-check/checkout-addresses/',import.meta.url));await mkdir(output,{recursive:true})
const report={live:[],browser:[],responsive:[],diagnostics:[],assetFailures:[]}
const browser=spawn(process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--remote-debugging-port=9246',`--user-data-dir=${output}/profile`,'about:blank'],{windowsHide:true,stdio:'ignore'})
const sleep=ms=>new Promise(r=>setTimeout(r,ms)),id=n=>`${String(n).padStart(8,'0')}-1111-4111-8111-111111111111`
const branch={id:id(1),name:'فرع الاختبار',is_active:true,accepts_orders:true,supports_pickup:true,supports_delivery:true,location:{city:'مدينة الاختبار',district:'حي الاختبار',address:'عنوان الفرع'},delivery_coverage:{city:'مدينة الاختبار',districts:['حي الاختبار']}}
const saved={id:id(30),label:'عنوان اختبار',recipient_name:'مستلم محفوظ',phone:'0000000',city:'مدينة الاختبار',district:'حي الاختبار',street:'شارع محفوظ',is_default:true,location:{latitude:null,longitude:null}}
let addressFailure=false,addressDelay=0,savedAddresses=true,authMode=false,created=null,mode='success',held,checkoutCalls=0,cartCreates=0,guestReads=0,authReads=0,detailsPage=1,invalidCart=false,currentPrice='80.00'
const requests=[]
const cartData=()=>({id:id(2),branch:{id:branch.id,name:branch.name,is_active:true,accepts_orders:true},status:'active',items:[{id:id(3),product:{id:id(4),name:'منتج حالي',primary_image:null},quantity:1,options:[],pricing:{base_price:currentPrice,options_total:'0.00',unit_price:currentPrice,line_total:currentPrice,line_total_after_discount:currentPrice,discount_total:'0.00'},is_available:!invalidCart,issues:invalidCart?[{code:'PRODUCT_UNAVAILABLE',message:'المنتج غير متاح.'}]:[]}],summary:{items_count:1,quantity_total:1,subtotal:currentPrice,discount_total:'0.00',total:currentPrice},availability:{accepts_orders:true,pickup:{available:true},delivery:{available:true}},can_checkout:!invalidCart,issues:[]})
let socket
try{
 let pages;for(let i=0;i<100;i++){try{pages=await(await fetch('http://127.0.0.1:9246/json')).json();if(pages.length)break}catch{}await sleep(100)}
 socket=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);await new Promise(r=>socket.addEventListener('open',r,{once:true}))
 let seq=0;const pending=new Map();const send=(method,params={})=>new Promise((resolve,reject)=>{const n=++seq;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params}))})
 const envelope=(data,status=200,errors)=>({success:status<400,message:status<400?'Success':'تعذر تنفيذ العملية',data,errors:errors||null})
 const fulfill=(requestId,status,body)=>send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Expose-Headers',value:'Retry-After'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID,X-Order-Token'},{name:'Access-Control-Allow-Methods',value:'GET,POST,PATCH,DELETE,OPTIONS'},...(status===429?[{name:'Retry-After',value:'2'}]:[])],body:Buffer.from(JSON.stringify(body)).toString('base64')})
 const createOrder=body=>({id:id(50),order_number:'SH-SERVER-SNAPSHOT',type:body.type,status:'pending',order_timing:body.order_timing||'asap',scheduled_for:body.scheduled_for||null,payment:{method:body.payment_method,status:'pending'},customer:structuredClone(body.customer),branch:{id:branch.id,name:branch.name},items:[{id:id(51),product:{id:id(4),name:'اسم المنتج وقت الطلب',sku:'SNAPSHOT-SKU'},quantity:1,options:[{group:{id:id(52),name:'مجموعة وقت الطلب'},value:{id:id(53),name:'خيار وقت الطلب'},price_modifier:'2.50'}],pricing:{base_price:currentPrice,options_total:'0.00',unit_price:currentPrice,line_total:currentPrice,discount_total:'0.00',line_total_after_discount:currentPrice}}],address:body.type==='delivery'?{...structuredClone(body.address||saved),delivery_zone_name:'منطقة وقت الطلب'}:null,pricing:{subtotal:currentPrice,delivery_fee:body.type==='delivery'?'5.00':'0.00',discount_total:'0.00',tax_total:'0.00',total:body.type==='delivery'?String(Number(currentPrice)+5)+'.00':currentPrice,currency:'SAR'},notes:body.notes,placed_at:'2026-10-04T10:00:00Z'})
 socket.addEventListener('message',async({data})=>{const m=JSON.parse(data);if(m.id){const p=pending.get(m.id);if(!p)return;pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result);return}
 if(m.method==='Runtime.exceptionThrown')report.diagnostics.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text)
 if(m.method==='Runtime.consoleAPICalled'&&['warning','error'].includes(m.params.type))report.diagnostics.push(m.params.args.map(a=>a.value??a.description).join(' '))
 if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&!m.params.response.url.includes('/api/'))report.assetFailures.push({url:m.params.response.url,status:m.params.response.status})
 if(m.method!=='Fetch.requestPaused')return
 const {requestId,request}=m.params,url=new URL(request.url),path=url.pathname,method=request.method
 if(method==='OPTIONS'){await fulfill(requestId,200,{});return}
 const body=request.postData?JSON.parse(request.postData):{},has=name=>Object.keys(request.headers).some(k=>k.toLowerCase()===name.toLowerCase())
 requests.push({path,method,body,guest:has('X-Cart-Token'),uuid:has('X-Cart-UUID'),orderToken:has('X-Order-Token'),bearer:has('Authorization'),idempotency:has('Idempotency-Key')})
 if(path.endsWith('/user')){await fulfill(requestId,200,envelope({uuid:id(20),name:'عميل اختبار',phone:'0000000',email:'fixture@example.invalid'}));return}
 if(path.endsWith('/admin/me')){await fulfill(requestId,403,envelope(null,403));return}
 if(path.endsWith('/branches')){await fulfill(requestId,200,envelope([branch]));return}
 if(path.endsWith('/branches/'+branch.id)){await fulfill(requestId,200,envelope(branch));return}
 if(path.endsWith('/branches/'+branch.id+'/status')){await fulfill(requestId,200,envelope({is_open:true,accepts_orders:true,pickup:{available:true},delivery:{available:true},reason:null}));return}
 if(path.endsWith('/settings/public')){await fulfill(requestId,200,envelope({restaurant:{timezone:'Asia/Riyadh',currency:'SAR'},orders:{enabled:true,maintenance_mode:false,allow_guest_checkout:true,allow_scheduled_orders:true,max_scheduling_days:7},pickup:{enabled:true},delivery:{enabled:true}}));return}
 if(path.endsWith('/addresses')){if(addressDelay)await sleep(addressDelay);if(addressFailure){await fulfill(requestId,503,envelope(null,503));return}await fulfill(requestId,200,{...envelope(savedAddresses?[saved]:[]),meta:{current_page:1,last_page:1,total:1},links:{prev:null,next:null}});return}
 if(path.includes('/addresses/')){await fulfill(requestId,200,envelope(saved));return}
 if(path.endsWith('/cart/delivery/quote')){await fulfill(requestId,200,envelope({available:true,is_estimate:true,delivery_fee:'5.00',estimated_total:'85.00',zone:{id:id(8),name:'منطقة الاختبار'},minimum_order:'0.00',minimum_order_met:true,estimated_delivery:{min_minutes:30,max_minutes:45}}));return}
 if(path.endsWith('/cart/refresh')){await fulfill(requestId,200,envelope(cartData()));return}
 if(path.endsWith('/cart')){if(method==='POST')cartCreates++;await fulfill(requestId,created?404:200,envelope(created?null:cartData(),created?404:200));return}
 if(path.endsWith('/checkout')){
 checkoutCalls++
 if(created){await fulfill(requestId,200,envelope(created));return}
 if(mode==='validation'){await fulfill(requestId,422,envelope(null,422,{'customer.phone':['رقم الهاتف غير صالح.']}));return}
 if(mode==='invalidAddress'){await fulfill(requestId,422,envelope(null,422,{'address.street':['بيانات الشارع غير صالحة.']}));return}
 if(mode==='rate'){await fulfill(requestId,429,envelope(null,429));return}
 if(mode==='unavailable'){invalidCart=true;await fulfill(requestId,422,envelope(null,422,{cart:[{code:'PRODUCT_UNAVAILABLE',message:'المنتج غير متاح.'}]}));return}
 if(mode==='outside'){await fulfill(requestId,422,envelope(null,422,{delivery:[{code:'ADDRESS_OUTSIDE_DELIVERY_AREA',message:'العنوان خارج نطاق التوصيل.'}]}));return}
 if(mode==='missingAddress'){await fulfill(requestId,404,envelope(null,404));return}
 if(mode==='price')currentPrice='91.00'
 created=createOrder(body)
 if(mode==='networkAfterCommit'){await send('Fetch.failRequest',{requestId,errorReason:'Failed'});return}
 if(mode==='hold'){held=requestId;return}
 await fulfill(requestId,201,envelope({...created,...(!authMode?{access_token:'o'.repeat(64)}:{})}));return
 }
 if(path.endsWith('/orders')){detailsPage=Number(url.searchParams.get('page')||1);await fulfill(requestId,200,{...envelope(created?[{...created,items:undefined,address:undefined}]:[]),meta:{current_page:detailsPage,last_page:2,total:2,from:detailsPage,to:detailsPage,per_page:1},links:{prev:detailsPage>1?'prev':null,next:detailsPage<2?'next':null}});return}
 if(path.includes('/orders/')&&path.endsWith('/payments')){await fulfill(requestId,200,{...envelope(created?[{id:id(70),method:created.payment.method,status:created.payment.status,amount:created.pricing.total,currency:'SAR',created_at:created.placed_at,refunded_amount:'0.00'}]:[]),meta:{current_page:1,last_page:1,total:created?1:0},links:{prev:null,next:null}});return}
 if(path.includes('/orders/')&&path.endsWith('/tracking')){await fulfill(requestId,created?200:404,envelope(created?{order:{uuid:created.id,number:created.order_number,type:created.type,status:{code:created.status,label:created.status},placed_at:created.placed_at},timeline:[],items:created.items.map(item=>({uuid:item.id,name:item.product.name,quantity:item.quantity,options:item.options.map(option=>({group:option.group.name,name:option.value.name})),line_total_after_discount:item.pricing.line_total_after_discount})),summary:{total:created.pricing.total,currency:'SAR'},branch:created.branch,payment:created.payment,estimates:{},progress:{percentage:0}}:null,created?200:404));return}
 if(path.includes('/orders/')){if(path.endsWith('/guest'))guestReads++;else authReads++;const allowed=created&&path.includes(created.id)&&(path.endsWith('/guest')?has('X-Order-Token'):authMode);await fulfill(requestId,allowed?200:404,envelope(allowed?created:null,allowed?200:404));return}
 await fulfill(requestId,200,envelope(null))
 })
 const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value}
 const waitFor=async expression=>{for(let i=0;i<180;i++){if(await evaluate(`Boolean(${expression})`))return;await sleep(100)}throw Error('Timeout '+expression)}
 const navigate=async path=>{await send('Page.navigate',{url:(process.env.PUBLIC_UI_ORIGIN||'http://127.0.0.1:5176')+path});await waitFor("document.querySelector('#app')?.__vue_app__");await evaluate("Promise.all([import('/src/stores/cart.js'),import('/src/stores/auth.js')]).then(([c,a])=>{window.cart=c.useCartStore();window.auth=a.useAuthStore();window.router=document.querySelector('#app').__vue_app__.config.globalProperties.$router;return auth.initialize()})");await waitFor('!cart.isLoading');await sleep(150)}
 const input=async(name,value)=>{await waitFor(`document.querySelector('[name='+${JSON.stringify(name)}+']')`);return evaluate(`(()=>{const el=document.querySelector('[name='+${JSON.stringify(name)}+']');if(!el)throw Error('Missing input '+${JSON.stringify(name)});el.value=${JSON.stringify(value)};el.dispatchEvent(new Event(el.tagName==='SELECT'?'change':'input',{bubbles:true}));return true})()`)}
 const choose=value=>evaluate(`(()=>{const el=document.querySelector('input[value='+${JSON.stringify(value)}+']');if(!el)throw Error('Missing radio');el.click();return true})()`)
 const click=async text=>{await waitFor(`[...document.querySelectorAll('button')].some(b=>b.textContent.includes(${JSON.stringify(text)})&&!b.disabled)`);await evaluate(`(()=>{const el=[...document.querySelectorAll('button')].find(b=>b.textContent.includes(${JSON.stringify(text)}));if(!el||el.disabled)throw Error('Missing/disabled '+${JSON.stringify(text)});el.click();return true})()`);await sleep(100)}
 const contact=async()=>{await input('customer_name','عميل الطلب');await input('customer_phone','0551234567')}
 const manual=async()=>{await click('كتابة العنوان يدويًا');for(const [key,value]of Object.entries({city:'مدينة الاختبار',district:'حي الاختبار',street:'شارع وقت الطلب'}))await input(key,value)}
 const verifyAddress=async()=>{if(await evaluate('Boolean(document.querySelector(".location-confirm"))'))await click('تأكيد هذا الموقع');else await click('التحقق من إمكانية التوصيل')}
 const reset=async logged=>{await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true});authMode=logged;created=null;mode='success';invalidCart=false;currentPrice='80.00';held=null;checkoutCalls=0;await evaluate(`(()=>{localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});localStorage.setItem('shishli_cart_token','g'.repeat(64));if(${logged})localStorage.setItem('shishli_auth_token','fixture-token');return true})()`);await navigate('/checkout');await waitFor("document.querySelector('[name=customer_name]')");await contact()}
 const checkoutReq=()=>requests.filter(r=>r.path.endsWith('/checkout')).at(-1)
 const success=()=>waitFor("location.pathname.includes('/order-success/')&&document.querySelector('.checkout-confirmation')")
 await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/v1/*',requestStage:'Request'},{urlPattern:'*://*/api/user',requestStage:'Request'}]})
 await send('Page.navigate',{url:(process.env.PUBLIC_UI_ORIGIN||'http://127.0.0.1:5176')+'/checkout'});await waitFor("document.querySelector('#app')?.__vue_app__")

 savedAddresses=false
 await reset(false);await choose('delivery')
 assert.equal(await evaluate('Boolean(document.querySelector("[name=address_mode][value=saved]"))'),false)
 assert.equal(await evaluate('Boolean(document.querySelector(".checkout-location"))'),true)
 assert.equal(requests.some(row=>row.path.endsWith('/addresses')),false,'Guest never requests saved addresses')
 report.browser.push('Guest goes straight to a new address without fetching saved addresses')
 addressDelay=800
 await reset(true);await choose('delivery')
 await waitFor('document.querySelector(".checkout-address-section .app-skeleton")')
 assert.equal(await evaluate('Boolean(document.querySelector("[name=address_mode][value=saved]"))'),false)
 await waitFor('document.querySelector(".checkout-location")');addressDelay=0
 assert.equal(await evaluate('Boolean(document.querySelector("[name=address_mode][value=saved]"))'),false)
 assert.equal(await evaluate('Boolean(document.querySelector(".checkout-location"))'),true)
 await manual()
 await click('استخدام هذا العنوان');await waitFor('document.querySelector(".delivery-quote")')
 await click('تأكيد الطلب');await success()
 assert.ok(checkoutReq().body.address)
 assert.equal(Object.hasOwn(checkoutReq().body,'address_uuid'),false)
 report.browser.push('Signed-in customer without saved addresses enters a new address and completes cash checkout')
 savedAddresses=true;addressFailure=true
 await reset(true);await choose('delivery')
 await waitFor('document.querySelector(".checkout-address-section").textContent.includes("تعذر تحميل العناوين المحفوظة")')
 assert.equal(await evaluate('Boolean(document.querySelector(".checkout-location"))'),true)
 addressFailure=false
 await click('إعادة المحاولة')
 await waitFor('document.querySelector("[name=address_mode][value=saved]")?.checked')
 report.browser.push('Address loading hides saved choice; load errors allow a new address and retry recovers the saved list')
 await reset(true);await choose('delivery')
 await waitFor('document.querySelector("[name=address_mode][value=saved]")?.checked')
 await click('اختيار للتوصيل')
 assert.equal(await evaluate('document.querySelector(".address-card button")?.getAttribute("aria-pressed")'), 'true')
 await choose('manual');await waitFor('document.querySelector(".checkout-location")')
 await choose('saved');await waitFor('document.querySelector(".address-book").style.display!=="none"')
 await click('إضافة عنوان');await waitFor('document.querySelector(".address-book input[name=recipient_name]")')
 report.browser.push('Existing saved address remains selectable; manual and add-new address flows remain available')
 console.log(JSON.stringify({checks:report.browser}))
}finally{socket?.close();browser.kill()}
