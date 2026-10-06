// Real HTTP transports are intercepted only in this test; production has no fixtures.
import {spawn} from 'node:child_process'
import {mkdir,writeFile} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
import assert from 'node:assert/strict'
const output=fileURLToPath(new URL('../.delivery-check/',import.meta.url));await mkdir(output,{recursive:true})
const report={live:[],browser:[],responsive:[],diagnostics:[],assetFailures:[]}
try{const r=await fetch('http://127.0.0.1:8000/api/v1/branches',{signal:AbortSignal.timeout(15000)});report.live.push({endpoint:'GET /branches',status:r.status});if(r.ok)report.live.push('Live server reachable; browser scenarios below use controlled transport')}catch{report.live.push('Laravel unavailable; no live CRUD certified')}
const browser=spawn(process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--remote-debugging-port=9243',`--user-data-dir=${output}/profile`,'about:blank'],{windowsHide:true,stdio:'ignore'})
const sleep=ms=>new Promise(r=>setTimeout(r,ms));let socket
const id=n=>`${String(n).padStart(8,'0')}-1111-4111-8111-111111111111`
const branch={id:id(1),name:'فرع الاختبار',is_active:true,accepts_orders:true,supports_pickup:true,supports_delivery:true,location:{city:'مدينة الاختبار',district:'حي الاختبار',address:'عنوان الفرع'}}
const availability=()=>({is_active:true,is_open:true,accepts_orders:branch.accepts_orders,pickup:{available:branch.supports_pickup&&branch.accepts_orders},delivery:{available:branch.supports_delivery&&branch.accepts_orders},reason:branch.accepts_orders?null:{code:'BRANCH_NOT_ACCEPTING_ORDERS',message:'الفرع لا يستقبل الطلبات'}})
const cart=()=>({id:id(2),branch:{id:branch.id,name:branch.name},status:'active',items:[{id:id(3),product:{id:id(4),name:'وجبة اختبار',primary_image:null},quantity:1,options:[],pricing:{base_price:'80.00',options_total:'0.00',unit_price:'80.00',line_total:'80.00',line_total_after_discount:'80.00',discount_total:'0.00'},is_available:true,issues:[]}],summary:{items_count:1,quantity_total:1,subtotal:'80.00',discount_total:'0.00',total:'80.00'},availability:availability(),can_checkout:branch.accepts_orders,issues:[]})
let addresses=[],zones=[],authMode=false,permissions=[],quoteMode='eligible',holdQuote,quoteCalls=0,geoCalls=0
const requests=[]
try{
 let pages;for(let i=0;i<100;i++){try{pages=await(await fetch('http://127.0.0.1:9243/json')).json();if(pages.length)break}catch{}await sleep(100)}
 socket=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);await new Promise(r=>socket.addEventListener('open',r,{once:true}))
 let seq=0;const pending=new Map();const send=(method,params={})=>new Promise((resolve,reject)=>{const n=++seq;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params}))})
 const envelope=(data,status=200,errors)=>({success:status<400,message:status<400?'Success':'تعذر تنفيذ العملية',data,errors:errors||null})
 const paginated=data=>({...envelope(data),meta:{current_page:1,last_page:1,total:data.length,from:data.length?1:null,to:data.length,per_page:20},links:{prev:null,next:null}})
 const quote=()=>({is_estimate:true,available:quoteMode==='eligible',zone:quoteMode==='outside'?null:{id:id(8),name:'منطقة الاختبار'},subtotal:'80.00',current_subtotal:'80.00',delivery_fee:'5.00',estimated_total:'85.00',minimum_order:'20.00',minimum_order_met:true,remaining_amount:'0.00',free_delivery_applied:false,estimated_delivery:{min_minutes:30,max_minutes:45},distance_km:null,issue:quoteMode==='eligible'?null:{code:'ADDRESS_OUTSIDE_DELIVERY_AREA',message:'العنوان خارج نطاق التوصيل.'}})
 const fulfill=(requestId,status,body)=>send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID'},{name:'Access-Control-Allow-Methods',value:'GET,POST,PATCH,DELETE,OPTIONS'}],body:Buffer.from(JSON.stringify(body)).toString('base64')})
 socket.addEventListener('message',async({data})=>{const m=JSON.parse(data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result);return}
 if(m.method==='Runtime.exceptionThrown')report.diagnostics.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text)
 if(m.method==='Runtime.consoleAPICalled'&&['warning','error'].includes(m.params.type))report.diagnostics.push(m.params.args.map(a=>a.value??a.description).join(' '))
 if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&!m.params.response.url.includes('/api/'))report.assetFailures.push({url:m.params.response.url,status:m.params.response.status})
 if(m.method!=='Fetch.requestPaused')return
 const {requestId,request}=m.params,path=new URL(request.url).pathname,method=request.method
 if(method==='OPTIONS'){await fulfill(requestId,200,{});return}
 const body=request.postData?JSON.parse(request.postData):{}
 requests.push({path,method,keys:Object.keys(body),token:Boolean(request.headers['X-Cart-Token']),uuid:Boolean(request.headers['X-Cart-UUID'])})
 if(path.endsWith('/cart/delivery/quote')){quoteCalls++;if(quoteMode==='network'){await send('Fetch.failRequest',{requestId,errorReason:'Failed'});return}if(quoteMode==='hold'){holdQuote=requestId;return}if(body.address_uuid&&!addresses.some(a=>a.id===body.address_uuid)){await fulfill(requestId,404,envelope(null,404));return}await fulfill(requestId,200,envelope(quote()));return}
 if(path.endsWith('/user')){await fulfill(requestId,200,envelope({uuid:id(20),name:'عميل اختبار',phone:null,email:'fixture@example.invalid'}));return}
 if(path.endsWith('/admin/me')){await fulfill(requestId,permissions.length?200:403,envelope({id:id(20),name:'عميل اختبار',is_active:true,roles:['manager'],permissions},permissions.length?200:403));return}
 if(path.endsWith('/logout')){await fulfill(requestId,200,envelope(null));return}
 if(path.endsWith('/branches')){await fulfill(requestId,200,path.includes('/admin/')?paginated([branch]):envelope([branch]));return}
 if(path.endsWith('/cart')){await fulfill(requestId,200,envelope({...cart(),...(method==='POST'&&!authMode?{token:'g'.repeat(64)}:{})}));return}
 if(path.includes('/addresses')){
 const match=path.match(/\/addresses\/([^/]+)(\/default)?$/),item=match?addresses.find(a=>a.id===match[1]):null
 if(!match&&method==='GET'){await fulfill(requestId,200,paginated(addresses));return}
 if(method==='POST'){const record={...body,id:id(30+addresses.length),location:{latitude:body.latitude,longitude:body.longitude},is_default:addresses.length===0||body.is_default};delete record.latitude;delete record.longitude;if(record.is_default)addresses.forEach(a=>a.is_default=false);addresses.push(record);await fulfill(requestId,201,envelope(record));return}
 if(!item){await fulfill(requestId,404,envelope(null,404));return}
 if(method==='GET'){await fulfill(requestId,200,envelope(item));return}
 if(method==='PATCH'){if(match[2]||body.is_default)addresses.forEach(a=>a.is_default=false);Object.assign(item,match[2]?{is_default:true}:body);if(Object.hasOwn(body,'latitude'))item.location={latitude:body.latitude,longitude:body.longitude};await fulfill(requestId,200,envelope(item));return}
 if(method==='DELETE'){addresses=addresses.filter(a=>a!==item);if(item.is_default&&addresses.length)addresses.at(-1).is_default=true;await fulfill(requestId,200,envelope(null));return}
 }
 if(path.includes('/delivery-zones')){
 const match=path.match(/\/delivery-zones\/([^/]+)$/),item=match?zones.find(z=>z.id===match[1]):null
 if(!match&&method==='GET'){await fulfill(requestId,200,paginated(zones));return}
 if(method==='POST'||method==='PATCH'){
 if(method==='PATCH'&&!item){await fulfill(requestId,404,envelope(null,404));return}
 const record={...(item||{}),...body,id:item?.id||id(50+zones.length)}
 if(record.type==='radius'){record.radius=Object.hasOwn(body,'center_latitude')?{center_latitude:body.center_latitude,center_longitude:body.center_longitude,radius_km:body.radius_km}:item?.radius;delete record.districts}else delete record.radius
 if(item)Object.assign(item,record);else zones.push(record)
 await fulfill(requestId,method==='POST'?201:200,envelope(record));return}
 if(!item){await fulfill(requestId,404,envelope(null,404));return}
 if(method==='GET'){await fulfill(requestId,200,envelope(item));return}
 if(method==='DELETE'){zones=zones.filter(z=>z!==item);await fulfill(requestId,200,envelope(null));return}
 }
 await fulfill(requestId,200,envelope(null))
 })
 const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value}
 const waitFor=async expression=>{for(let i=0;i<160;i++){if(await evaluate(`Boolean(${expression})`))return;await sleep(100)}console.log(JSON.stringify({requests,diagnostics:report.diagnostics,state:await evaluate('({cartError:cart.error,busy:cart.isMutating,quoteError:document.querySelector("main")?.__vueParentComponent?.setupState.quoteError})')}));throw Error('Timeout '+expression)}
 const navigate=async path=>{await send('Page.navigate',{url:'http://127.0.0.1:5173'+path});await waitFor("document.querySelector('#app')?.__vue_app__");await evaluate("Promise.all([import('/src/stores/cart.js'),import('/src/stores/auth.js'),import('/src/stores/branch.js')]).then(([c,a,b])=>{window.cart=c.useCartStore();window.auth=a.useAuthStore();window.branch=b.useBranchStore();window.router=document.querySelector('#app').__vue_app__.config.globalProperties.$router;return cart.initializeCart()})");await waitFor('!cart.isLoading')}
 const input=(name,value)=>evaluate(`(()=>{const el=document.querySelector('[name='+${JSON.stringify(name)}+']');if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));return true})()`)
 const click=async text=>{await evaluate(`(()=>{const el=[...document.querySelectorAll('button')].find(b=>b.textContent.includes(${JSON.stringify(text)}));if(!el)throw Error('Missing button');if(el.disabled)throw Error('Disabled button');el.click();return true})()`);await sleep(100)}
 await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Fetch.enable',{patterns:[{urlPattern:'*:8000/api/*',requestStage:'Request'}]})
 const init=await send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch.id)});localStorage.setItem('shishli_cart_token','g'.repeat(64));window.__geoCalls=0;Object.defineProperty(navigator,'geolocation',{value:{getCurrentPosition(){window.__geoCalls++}}})`})
 await navigate('/checkout');await send('Page.removeScriptToEvaluateOnNewDocument',{identifier:init.identifier})
 assert.equal(await evaluate("Boolean(document.querySelector('[name=city]'))"),false)
 await evaluate("document.querySelector('[value=delivery]').click()")
 await waitFor("document.querySelector('[name=city]')")
 assert.equal(await evaluate('window.__geoCalls'),0)
 for(const [name,value] of [['recipient_name','مستلم اختبار'],['phone','0500000000'],['city','مدينة الاختبار'],['district','حي الاختبار']])await input(name,value)
 await click('التحقق من إمكانية التوصيل');await waitFor("document.querySelector('.delivery-quote')")
 assert.ok(await evaluate("document.querySelector('.checkout-summary').textContent.includes('85')"))
 assert.deepEqual(requests.find(r=>r.path.endsWith('/cart/delivery/quote')).keys,['address'])
 assert.equal(requests.find(r=>r.path.endsWith('/cart/delivery/quote')).token,true)
 await input('district','حي آخر');assert.equal(await evaluate("Boolean(document.querySelector('.delivery-quote'))"),false)
 quoteMode='outside';await click('التحقق من إمكانية التوصيل');await waitFor("document.querySelector('.delivery-quote')")
 assert.ok(await evaluate("document.querySelector('.delivery-quote').textContent.includes('خارج نطاق')"))
 assert.equal(await evaluate("document.querySelector('.auth-submit').disabled"),true)
 quoteMode='network';await click('التحقق من إمكانية التوصيل');await waitFor("document.querySelector('main').textContent.includes('تعذر التحقق')")
 assert.equal(await evaluate("Boolean(document.querySelector('.delivery-quote'))"),false)
 report.browser.push('Guest manual address: no auto-geolocation, real quote contract/headers, backend fee/total, address invalidation, outside-zone distinct from network error')
 quoteMode='eligible';await click('التحقق من إمكانية التوصيل');await waitFor("document.querySelector('.delivery-quote')")
 await evaluate('cart.cart={...cart.cart,summary:{...cart.cart.summary,total:"90.00"}}')
 assert.equal(await evaluate("Boolean(document.querySelector('.delivery-quote'))"),false)
 await evaluate("document.querySelector('[value=pickup]').click()")
 assert.equal(await evaluate("Boolean(document.querySelector('[name=city]'))"),false)
 report.browser.push('Cart changes invalidate quote; pickup asks no delivery address')
 await evaluate("localStorage.setItem('shishli_auth_token','fixture-token')");authMode=true
 await navigate('/account/addresses')
 await click('إضافة عنوان');await waitFor("document.querySelector('.address-editor')")
 for(const [name,value] of [['label','منزل'],['recipient_name','مستلم اختبار'],['phone','0500000000'],['city','مدينة الاختبار'],['district','حي الاختبار']])await input(name,value)
 await evaluate("document.querySelector('.address-editor').requestSubmit()")
 await waitFor("document.querySelector('.address-card')&&!document.querySelector('.address-editor')")
 assert.equal(addresses.length,1);assert.equal(addresses[0].is_default,true)
 await click('تعديل');await waitFor("document.querySelector('.address-editor')");await input('label','عنوان محدث');await evaluate("document.querySelector('.address-editor').requestSubmit()");await waitFor("!document.querySelector('.address-editor')")
 assert.equal(addresses[0].label,'عنوان محدث')
 await click('إضافة عنوان');await input('recipient_name','مستلم ثان');await input('phone','0500000000');await input('city','مدينة الاختبار');await input('district','حي الاختبار');await evaluate("document.querySelector('.address-editor').requestSubmit()");await waitFor("document.querySelectorAll('.address-card').length===2")
 await click('تعيين افتراضي');await waitFor("document.querySelectorAll('.address-card .badge').length===1")
 assert.equal(addresses.filter(a=>a.is_default).length,1)
 await click('حذف');await waitFor("document.querySelector('dialog[open]')");await evaluate("document.querySelector('dialog[open] button').click()");await waitFor("document.querySelectorAll('.address-card').length===1")
 assert.equal(addresses.length,1)
 assert.ok(requests.filter(r=>r.path.includes('/addresses')).every(r=>!r.keys.includes('user_id')))
 report.browser.push('Saved address UI list/create/show/edit/default/delete confirmation; no ownership IDs submitted')
 await evaluate("router.push('/checkout').then(()=>true)");await waitFor("document.querySelector('[value=delivery]')");await evaluate("document.querySelector('[value=delivery]').click()");await waitFor("document.querySelector('.address-card')");await click('اختيار للتوصيل');await waitFor("document.querySelector('.delivery-quote')")
 const savedQuote=requests.filter(r=>r.path.endsWith('/cart/delivery/quote')).at(-1);assert.deepEqual(savedQuote.keys,['address_uuid']);assert.equal(savedQuote.uuid,true);assert.equal(savedQuote.token,false)
 report.browser.push('Saved address quote sends address_uuid with owned cart selector, never guest token')
 // Checkout now disables all fields while quoting; force a source change to exercise the stale-response guard.
 quoteMode='hold';await click('التحقق من إمكانية التوصيل');await waitFor('cart.isMutating');await evaluate("(()=>{const el=document.querySelector('[value=manual]');el.checked=true;el.dispatchEvent(new Event('change',{bubbles:true}));return true})()");quoteMode='eligible';await fulfill(holdQuote,200,envelope(quote()));await waitFor('!cart.isMutating');assert.equal(await evaluate("Boolean(document.querySelector('.delivery-quote'))"),false)
 report.browser.push('Late quote response after address-source change discarded')
 branch.supports_delivery=false;await evaluate('cart.loadCart()');await waitFor('!cart.isLoading');assert.equal(await evaluate("Boolean(document.querySelector('[value=delivery]'))"),false)
 branch.accepts_orders=false;await evaluate('cart.loadCart()');await waitFor('!cart.isLoading');assert.equal(await evaluate("document.querySelector('.auth-submit').disabled"),true)
 branch.supports_delivery=true;branch.accepts_orders=true
 report.browser.push('Delivery-disabled and closed branch operational capabilities respected')
 permissions=['delivery_zones.view','delivery_zones.create','delivery_zones.update','delivery_zones.delete','branches.view'];await navigate('/admin/delivery-zones')
 await waitFor("document.querySelector('.toolbar select')?.value")
 await click('إضافة منطقة');await input('name','منطقة اختبار');await input('delivery_fee','5.00');await input('districts','حي الاختبار\nحي ثان');await evaluate("document.querySelector('.catalog-subform').requestSubmit()");await waitFor("document.querySelector('tbody tr')")
 assert.equal(zones.length,1);assert.equal(zones[0].districts.length,2)
 await click('تعديل / تفاصيل');await waitFor("document.querySelector('.catalog-subform')");await evaluate("(()=>{const s=document.querySelector('[name=type]');s.value='radius';s.dispatchEvent(new Event('change',{bubbles:true}));return true})()");await input('center_latitude','0');await input('center_longitude','0');await input('radius_km','5.00');await evaluate("document.querySelector('.catalog-subform').requestSubmit()");await waitFor("!document.querySelector('.catalog-subform')")
 assert.equal(zones[0].type,'radius');assert.equal(zones[0].radius.center_latitude,0)
 await click('تعطيل');await waitFor("document.querySelector('tbody').textContent.includes('متوقفة')")
 for(const width of [390,768,1440]){await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});await sleep(100);assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);report.responsive.push('Admin zones '+width)}
 await evaluate("document.querySelector('tbody .danger').click()");await waitFor("document.querySelector('dialog[open]')");await evaluate("document.querySelector('dialog[open] button').click()");await waitFor("!document.querySelector('tbody tr')");assert.equal(zones.length,0)
 report.browser.push('Admin zones list/create/show/update district-to-radius with zero coordinates/deactivate/delete; scoped API paths')
 permissions=[];await navigate('/admin/delivery-zones');await waitFor("location.pathname==='/forbidden'");assert.equal(await evaluate("Boolean(document.querySelector('a[href=\"/admin/delivery-zones\"]'))"),false)
 report.browser.push('Admin direct route denied without actual zone permission')
 await navigate('/account/addresses');await click('إضافة عنوان')
 for(const width of [390,768,1440]){await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});await sleep(100);assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);report.responsive.push('Customer address '+width)}
 assert.equal(requests.some(r=>r.path.endsWith('/checkout')),false)
 assert.equal(await evaluate("localStorage.getItem('shishli.cart.v1')"),null)
 report.browser.push('No final checkout/order/payment request; no address localStorage persistence')
 await writeFile(output+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));assert.deepEqual(report.diagnostics,[]);assert.deepEqual(report.assetFailures,[])
}finally{socket?.close();browser.kill()}




