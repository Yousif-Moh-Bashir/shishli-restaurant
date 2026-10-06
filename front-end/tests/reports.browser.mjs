// Real HTTP checks and controlled browser scenarios are reported separately.
import { spawn } from 'node:child_process'
import { mkdir, writeFile, readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'
const output = fileURLToPath(new URL('../.reports-check/', import.meta.url))
await mkdir(output, { recursive: true })
const report = { live: [], browser: [], responsive: [], diagnostics: [], assetFailures: [] }
const api = process.env.REPORT_API_URL || 'http://127.0.0.1:8000/api/v1'
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
const keys = ['sales/trend','orders','branches','products','categories','payments','promotions','coupons','customers','delivery','order-statuses','cancellations','inventory']
const id = n => `${String(n).padStart(8,'0')}-1111-4111-8111-111111111111`
const period = { from: '2026-10-04', to: '2026-10-04', timezone: 'Asia/Riyadh' }
const summary = { orders_count: 28, completed_orders_count: 4, pending_orders_count: 4, cancelled_orders_count: 4, completed_sales: '250.00', average_order_value: '62.50', new_customers_count: 6, collected_revenue: '245.00', completion_rate: '14.29', cancellation_rate: '14.29' }
const rows = {
  'sales/trend': [{period:'2026-10-04',orders_count:28,completed_orders_count:4,completed_sales:'250.00',average_order_value:'62.50'}],
  orders: [{order_uuid:id(50),order_number:'TEST-ORDER',type:'delivery',status:'completed',payment_method:'cash',placed_at:'2026-10-04 10:00:00',total:'85.00'}],
  branches: [{branch_uuid:id(1),branch_name:'فرع الاختبار',orders_count:28,completed_orders:4,cancelled_orders:4,completed_sales:'250.00',average_order_value:'62.50',delivery_orders:14,pickup_orders:14}],
  products: [{product_uuid:id(4),product_name:'منتج تاريخي',quantity_sold:4,orders_count:3,gross_item_sales:'88.00',discount_total:'8.00',net_item_sales:'80.00'}],
  categories: [{category_uuid:null,category_name:null,quantity_sold:4,orders_count:3,gross_sales:'88.00',discount_total:'8.00',net_sales:'80.00'}],
  payments: [{method:'cash',provider:null,status:'paid',payments_count:4,transactions_count:4,collected_amount:'245.00'}],
  promotions: [{promotion_uuid:id(6),promotion_name:'عرض محفوظ',usage_count:2,orders_count:2,discount_total:'10.00',sales_before_discount:'100.00',sales_after_discount:'90.00'}],
  coupons: [{code:'TEST10',usage_count:2,orders_count:2,discount_total:'10.00'}],
  customers: [{customer_uuid:id(20),name:'عميل اختبار',orders_count:5,completed_orders_count:2,total_spent:'85.00',last_order_at:'2026-10-04 10:00:00'}],
  delivery: [{zone_uuid:id(8),zone_name:'منطقة محفوظة',orders_count:3,delivery_fees:'15.00'}],
  'order-statuses': [{status:'completed',orders_count:4},{status:'cancelled',orders_count:4}],
  cancellations: [{cancellation_reason:null,cancelled_orders_count:4,cancelled_sales_value:'85.00'}],
}
const summaries = { orders:summary, payments:{payments_count:4,successful_payments_count:4,collected_amount:'245.00',failed_attempts_count:0,refunds_supported:false,refunded_amount:null}, customers:{registered_customers_count:6,new_registered_customers:6,customers_with_orders:6,returning_customers_count:4,guest_orders_count:0}, delivery:{delivery_orders_count:14,pickup_orders_count:14,free_delivery_orders_count:0,delivery_fees_collected:'15.00'}, 'order-statuses':{sampled_orders_count:4,average_preparation_minutes:'10.00'}, cancellations:{cancelled_orders_count:4,cancelled_sales_value:'85.00'} }
const envelope = (data,status=200,errors=null) => ({success:status<400,message:status<400?'Success':'تعذر تنفيذ العملية',data,errors})
let role='manager', mode='', held, requests=[], socket, browser, token, cashierToken, customerToken
const permissions = () => role==='kitchen'?['orders.view']:role==='report-only'?['reports.orders.view']:role==='cashier'?['dashboard.view','reports.orders.view']:['dashboard.view','branches.view','reports.orders.view','reports.sales.view','reports.branches.view','reports.products.view','reports.payments.view','reports.promotions.view','reports.customers.view','reports.delivery.view','inventory.view','reports.export']
async function http(path, credential=token, options={}) {
  return fetch(api+path,{...options,headers:{Accept:'application/json',...(credential?{Authorization:'Bearer '+credential}:{}),...options.headers},signal:AbortSignal.timeout(20000)})
}
async function login(email,password) {
  const response=await http('/login',null,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password})})
  assert.equal(response.status,200,'Live login')
  return (await response.json()).data.token
}
try {
  token=await login(process.env.REPORT_TEST_EMAIL||'super_admin@demo.shishli.local',process.env.REPORT_TEST_PASSWORD||'DemoTest123!')
  const bootstrap=await (await http('/admin/bootstrap')).json()
  assert.ok(bootstrap.data.restaurant.currency)
  const dashboard=await (await http('/admin/dashboard')).json()
  assert.equal(dashboard.success,true)
  report.live.push({check:'Dashboard default',period:dashboard.data.period,orders:dashboard.data.summary.orders_count,sales:dashboard.data.summary.completed_sales,currency:bootstrap.data.restaurant.currency})
  if (!process.argv.includes('--browser-only')) {
  const dates='date_from=2026-10-01&date_to=2026-10-04'
  let liveBranch
  for(const key of keys){const response=await http('/admin/reports/'+key+(key==='inventory'?'':'?'+dates));assert.equal(response.status,200,key);const body=await response.json();assert.equal(body.success,true);if(key==='branches')liveBranch=body.data.data[0]?.branch_uuid;report.live.push({check:key,status:200,rows:body.data.data?.length,summary:body.data.summary})}
  for(const [from,to] of [['2026-10-04','2026-10-04'],['2026-09-28','2026-10-04'],['2026-10-01','2026-10-31'],['2020-01-01','2020-01-01']]){
    const response=await http(`/admin/dashboard?date_from=${from}&date_to=${to}`),body=await response.json();assert.equal(response.status,200);if(from==='2020-01-01'){assert.equal(body.data.summary.orders_count,0);assert.equal(body.data.summary.completed_sales,'0.00')}report.live.push({check:'Date period',from,to,orders:body.data.summary.orders_count})
  }
  if(liveBranch){const response=await http('/admin/reports/orders?'+dates+'&branch_uuid='+liveBranch);assert.equal(response.status,200);report.live.push({check:'Actual branch filter',status:200})}
  const exports=['orders','sales/trend','products','payments','customers','promotions']
  for(const key of exports){
    const query='?'+dates+(liveBranch?'&branch_uuid='+liveBranch:'')
    const response=await http('/admin/reports/'+key+'/export'+query);if(response.status===429){report.live.push({check:'Export rate limit',status:429});await sleep(30500);await sleep(30500)}
    const retry=response.status===429?await http('/admin/reports/'+key+'/export'+query):response
    assert.equal(retry.status,200,key+' CSV');assert.ok(retry.headers.get('content-type').startsWith('text/csv'));const csv=await retry.text();assert.ok(csv.length);const filename=retry.headers.get('content-disposition')?.match(/filename="?([^";]+)/)?.[1]||key.replace('/','-')+'.csv';await writeFile(output+filename,csv);report.live.push({check:'CSV '+key,status:200,filename,filter:dates,bytes:csv.length})
  }
  cashierToken=await login('cashier@demo.shishli.local','DemoTest123!')
  customerToken=await login('customer1@demo.shishli.local','DemoTest123!')
  assert.equal((await http('/admin/reports/orders/export',cashierToken)).status,403)
  assert.equal((await http('/admin/reports/payments',cashierToken)).status,403)
  assert.equal((await http('/admin/dashboard',customerToken)).status,403)
  assert.equal((await http('/admin/dashboard',null)).status,401)
  assert.equal((await http('/admin/reports/orders?branch_uuid='+id(999))).status,422)
  report.live.push({check:'Cashier export/finance forbidden; customer dashboard forbidden; anonymous 401; missing branch 422',passed:true})
  } else {
    report.live = JSON.parse(await readFile(output+'report.json','utf8')).live.filter(item => item.check !== 'Real dashboard in browser matches HTTP aggregate')
  }

  browser=spawn(process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--remote-debugging-port=9251',`--user-data-dir=${output}/profile`,'about:blank'],{windowsHide:true,stdio:'ignore'})
  let pages;for(let i=0;i<100;i++){try{pages=await(await fetch('http://127.0.0.1:9251/json')).json();if(pages.length)break}catch{}await sleep(100)}
  socket=new WebSocket(pages.find(page=>page.type==='page').webSocketDebuggerUrl);await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}))
  let seq=0;const pending=new Map()
  const send=(method,params={})=>new Promise((resolve,reject)=>{const n=++seq;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params}))})
  const fulfill=(requestId,status,body,type='application/json',headers=[])=>send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type'},{name:'Access-Control-Allow-Methods',value:'GET,POST,OPTIONS'},{name:'Access-Control-Expose-Headers',value:'Content-Disposition,Retry-After'},...headers],body:Buffer.from(type.startsWith('application/json')?JSON.stringify(body):body).toString('base64')})
  socket.addEventListener('message',async({data})=>{
    const message=JSON.parse(data);if(message.id){const p=pending.get(message.id);pending.delete(message.id);message.error?p.reject(message.error):p.resolve(message.result);return}
    if(message.method==='Runtime.exceptionThrown')report.diagnostics.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text)
    if(message.method==='Runtime.consoleAPICalled'&&['warning','error'].includes(message.params.type))report.diagnostics.push(message.params.args.map(arg=>arg.value??arg.description).join(' '))
    if(message.method==='Network.responseReceived'&&message.params.response.status>=400&&!message.params.response.url.includes('/api/'))report.assetFailures.push(message.params.response.status)
    if(message.method!=='Fetch.requestPaused')return
    const {requestId,request}=message.params,url=new URL(request.url),path=url.pathname
    if(request.method==='OPTIONS')return void await fulfill(requestId,200,{})
    requests.push({path,query:url.search})
    if(path.endsWith('/user'))return void await fulfill(requestId,200,envelope({uuid:id(20),name:'اختبار'}))
    if(path.endsWith('/admin/me'))return void await fulfill(requestId,200,envelope({id:id(20),is_active:true,roles:[role],permissions:permissions()}))
    if(path.endsWith('/admin/bootstrap'))return void await fulfill(requestId,200,envelope({restaurant:{currency:'SAR',timezone:'Asia/Riyadh'}}))
    if(['/api/v1/branches','/api/v1/admin/branches'].includes(path))return void await fulfill(requestId,200,{...envelope([{id:id(1),name:'الفرع الأول'},{id:id(2),name:'الفرع الثاني'}]),meta:{last_page:1},links:{}})
    if(path === '/api/v1/categories')return void await fulfill(requestId,200,envelope([]))
    if(path.endsWith('/admin/dashboard')){
      if(mode==='hold'){held={requestId,path};return}
      if(mode==='network')return void await send('Fetch.failRequest',{requestId,errorReason:'Failed'})
      if(/^error/.test(mode))return void await fulfill(requestId,Number(mode.slice(5)),envelope(null,Number(mode.slice(5)),{date_to:['نطاق غير صالح']}))
      const data=mode==='empty'?Object.fromEntries(Object.keys(summary).map(key=>[key,typeof summary[key]==='string'?'0.00':0])):summary
      const copy={...data};if(role==='cashier'){delete copy.collected_revenue;delete copy.completed_sales;delete copy.average_order_value}
      return void await fulfill(requestId,200,envelope({period,summary:copy,order_statuses:{pending:mode==='empty'?0:4,completed:mode==='empty'?0:4},order_types:{pickup:mode==='empty'?0:14,delivery:mode==='empty'?0:14},comparison:{period:{...period,from:'2026-10-03',to:'2026-10-03'},changes:{orders_count:{current:copy.orders_count,previous:0,percentage_change:mode==='empty'?'0.00':null,trend:mode==='empty'?'flat':'up'}}}}))
    }
    const key=path.split('/reports/')[1]
    if(key?.endsWith('/export')){
      if(mode==='exportError')return void await fulfill(requestId,422,envelope(null,422,{date_to:['تجاوز حد التصدير']}))
      if(mode==='exportJson')return void await fulfill(requestId,200,envelope({unexpected:true}))
      return void await fulfill(requestId,200,'\ufeffperiod,completed_sales\n2026-10-04,250.00\n','text/csv; charset=UTF-8',[{name:'Content-Disposition',value:'attachment; filename="server-report.csv"'}])
    }
    if(key){
      if(mode==='hold'){held={requestId,path};return}
      if(mode==='trendError'&&key==='sales/trend')return void await fulfill(requestId,500,envelope(null,500))
      if(mode==='inventory')return void await fulfill(requestId,200,envelope({as_of:'2026-10-04T10:00:00Z',tracked_products_count:2,tracked_inventories_count:3,out_of_stock_count:1,low_stock_count:2}))
      if(key==='inventory')return void await fulfill(requestId,200,envelope({as_of:'2026-10-04T10:00:00Z',tracked_products_count:0,tracked_inventories_count:0,out_of_stock_count:0,low_stock_count:0}))
      const result={period,summary:summaries[key]||{},data:mode==='empty'?[]:rows[key]||[],...(key==='sales/trend'?{group_by:url.searchParams.get('group_by')||'day'}:{meta:{current_page:Number(url.searchParams.get('page')||1),last_page:2,total:21,per_page:20}})}
      return void await fulfill(requestId,200,envelope(result))
    }
    return void await fulfill(requestId,200,envelope(null))
  })
  const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value}
  const waitFor=async expression=>{for(let i=0;i<150;i++){if(await evaluate(`Boolean(${expression})`))return;await sleep(100)}throw Error('Timeout '+expression)}
  const navigate=async path=>{await send('Page.navigate',{url:'http://127.0.0.1:5173'+path});await waitFor("document.querySelector('#app')?.__vue_app__");await evaluate("import('/src/stores/auth.js').then(a=>{window.auth=a.useAuthStore();window.router=document.querySelector('#app').__vue_app__.config.globalProperties.$router;return auth.initialize()})");await sleep(200)}
  const click=async text=>{await evaluate(`(()=>{const el=[...document.querySelectorAll('button')].find(button=>button.textContent.includes(${JSON.stringify(text)}));if(!el||el.disabled)throw Error('Missing button');el.click()})()`);await sleep(100)}
  const input=(name,value)=>evaluate(`(()=>{const el=document.querySelector('[name='+${JSON.stringify(name)}+']');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event(el.tagName==='SELECT'?'change':'input',{bubbles:true}))})()`)
  const responsive=async label=>{for(const width of [390,768,1440]){await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});await sleep(150);assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true,label+' '+width);report.responsive.push(label+' '+width)}}
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable')
  await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:output})
  await navigate('/login');await evaluate(`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`)
  await navigate('/admin');await waitFor('document.querySelector("[data-metric=orders_count]")')
  await waitFor('!document.querySelector(".report-loading")')
  if (await evaluate('Boolean(document.querySelector(".dashboard-overview"))')) {
    await waitFor('document.querySelector(".overview-orders tbody tr")')
    await waitFor('document.querySelector(".overview-trend .bar-row")')
    assert.equal(await evaluate('document.querySelectorAll(".overview-stats > .stat").length'), 4)
  }
  assert.ok((await evaluate('document.querySelector("[data-metric=orders_count] strong").textContent')).includes(String(dashboard.data.summary.orders_count)))
  assert.equal(await evaluate('Boolean(document.querySelector(".report-error,[role=alert]"))'),false)
  await responsive('Live dashboard')
  await writeFile(output+'dashboard-live.png',Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'))
  report.live.push({check:'Real dashboard in browser matches HTTP aggregate',passed:true})
  for(const key of keys){await navigate('/admin/reports?report='+encodeURIComponent(key));await waitFor('!document.querySelector(".report-loading") && document.querySelector(".report-table,.report-metrics")');assert.equal(await evaluate('Boolean(document.querySelector(".report-error,[role=alert]"))'),false,'Live '+key);report.live.push({check:'Live browser report '+key,passed:true});if(key==='orders'){await click('تصدير CSV');await waitFor('!document.querySelector(".panel-head .button")?.disabled');for(let attempt=0;attempt<50;attempt++){try{const csv=await readFile(output+'report.csv','utf8');assert.ok(csv.includes('order_number'));report.live.push({check:'Real authenticated CSV browser download',filename:'report.csv',note:'Cross-origin server header not exposed; safe fallback filename used',passed:true});break}catch{if(attempt===49)throw Error('Live CSV download missing');await sleep(100)}}await writeFile(output+'orders-live.png',Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'))}}
  await send('Fetch.enable',{patterns:[{urlPattern:'*:8000/api/*',requestStage:'Request'}]})
  await evaluate("localStorage.setItem('shishli_auth_token','fixture-token')")
  await navigate('/admin');await waitFor('document.querySelector("[data-metric=collected_revenue]")')
  assert.ok(await evaluate('document.querySelector("[data-metric=completed_sales] strong").textContent.includes("250")'))
  assert.ok(await evaluate('/سابقة/.test(document.querySelector("[data-metric=orders_count]").textContent)'))
  await responsive('Dashboard fixtures')
  for(const key of keys){mode='';await navigate('/admin/reports?report='+encodeURIComponent(key));await waitFor('!document.querySelector(".report-loading") && document.querySelector(".report-table,.report-metrics")');assert.equal(await evaluate('Boolean(document.querySelector(".report-error"))'),false,key);await responsive(key);report.browser.push('Rendered '+key)}
  await navigate('/admin/reports?report=products');await waitFor('document.querySelector(".report-table")');await input('sort_by','net_sales');await click('تطبيق الفلاتر');await waitFor('location.search.includes("sort_by=net_sales")');await waitFor('document.querySelector(".report-table")');await click('التالي');await waitFor('location.search.includes("page=2")');report.browser.push('Server sorting and pagination')
  await navigate('/admin/reports?report=branches');await waitFor('document.querySelector(".report-table")');await click('تقارير هذا الفرع');await waitFor('location.search.includes("branch_uuid=")');report.browser.push('Branch drilldown')
  await navigate('/admin/reports?report=sales%2Ftrend');await waitFor('document.querySelector(".report-table")');await input('date_from','2026-10-01');await input('date_to','2026-10-04');await input('branch_uuid',id(2));await input('group_by','week');await click('تطبيق الفلاتر');await waitFor('location.search.includes("group_by=week")');await waitFor('document.querySelector(".report-table")');await click('تصدير CSV');for(let i=0;i<50;i++){try{const csv=await readFile(output+'server-report.csv','utf8');assert.ok(csv.includes('250.00'));break}catch{if(i===49)throw Error('CSV was not downloaded');await sleep(100)}}
  const exported=requests.filter(request=>request.path.endsWith('/export')).at(-1);assert.ok(exported.query.includes('branch_uuid='+id(2)));assert.ok(exported.query.includes('group_by=week'));report.browser.push('Actual browser Blob download, backend filename and applied branch/granularity')
  mode='exportError';await click('تصدير CSV');await waitFor('document.querySelector(".report-error")');assert.ok(await evaluate('document.querySelector(".report-error").textContent.includes("تجاوز حد")'));mode='exportJson';await click('تصدير CSV');await waitFor('document.querySelector(".report-error")');report.browser.push('CSV error JSON decoded and 200 JSON rejected')
  mode='empty';await navigate('/admin');await waitFor('document.querySelector("[data-metric=orders_count]")');assert.ok(await evaluate('document.querySelector("[data-metric=orders_count] strong").textContent==="0"'));assert.equal(await evaluate('Boolean(document.querySelector(".report-error"))'),false);report.browser.push('Zero and empty state')
  for(const status of [403,404,422,429,500]){mode='error'+status;await navigate('/admin');await waitFor('document.querySelector(".report-error")');assert.equal(await evaluate('Boolean(document.querySelector("[data-metric=orders_count]"))'),false);report.browser.push('Dashboard error '+status)}
  mode='network';await navigate('/admin');await waitFor('document.querySelector(".report-error")');mode='';await click('إعادة المحاولة');await waitFor('document.querySelector("[data-metric=orders_count]")');report.browser.push('Network failure and retry')
  mode='trendError';await navigate('/admin');await waitFor('document.querySelector(".report-error")');assert.ok(await evaluate('Boolean(document.querySelector("[data-metric=orders_count]"))'));report.browser.push('Partial trend failure preserves dashboard')
  mode='hold';await navigate('/admin/reports?report=orders');await waitFor('document.querySelector(".report-loading")');await sleep(200);const stale=held;mode='';await evaluate("router.replace('/admin/reports?report=orders&order_status=completed')");await waitFor('document.querySelector(".report-table")');try{await fulfill(stale.requestId,200,envelope({period,summary:{orders_count:999},data:[],meta:{current_page:1,last_page:1,total:0,per_page:20}}))}catch{}await sleep(200);assert.equal(await evaluate('document.querySelector("[data-metric=orders_count] strong").textContent.includes("999")'),false);report.browser.push('Rapid filters cancel stale response')
  role='cashier';await navigate('/admin');await waitFor('document.querySelector("[data-metric=orders_count]")');assert.equal(await evaluate('Boolean(document.querySelector("[data-metric=completed_sales],[data-metric=collected_revenue]"))'),false)
  await navigate('/admin/reports?report=orders');await waitFor('document.querySelector(".report-table")');assert.equal(await evaluate('[...document.querySelectorAll("button")].some(button=>button.textContent.includes("تصدير CSV"))'),false)
  await evaluate("router.push('/admin/reports?report=payments')");await waitFor('location.pathname==="/forbidden"');report.browser.push('Financial and export permissions; direct report URL denied')
  role='report-only';await navigate('/admin/reports');await waitFor('document.querySelector(".report-table")');assert.ok(await evaluate('document.querySelector(".sidebar").textContent.includes("التقارير")'));report.browser.push('Report-only staff navigation')
  role='kitchen';await navigate('/admin/reports');await waitFor('location.pathname==="/forbidden"');assert.equal(await evaluate('document.querySelector(".sidebar")?.textContent.includes("التقارير")||false'),false);report.browser.push('Kitchen reporting denied')
  role='manager';mode='error401';await navigate('/admin');await waitFor('location.pathname === "/login"');assert.equal(await evaluate('localStorage.getItem("shishli_auth_token")'),null);report.browser.push('401 expires session and redirects to login')
  assert.deepEqual(report.diagnostics,[]);assert.deepEqual(report.assetFailures,[])
} catch(error) { report.failure=error.message; console.error(error);process.exitCode=1 }
finally {
  socket?.close();browser?.kill()
  for(const credential of [token,cashierToken,customerToken].filter(Boolean)){try{await http('/logout',credential,{method:'POST'})}catch{}}
  await writeFile(output+'report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2))
}
