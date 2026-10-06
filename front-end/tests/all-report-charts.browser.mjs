import {browserClient} from './browser-client.mjs'
import {reportDefinitions} from '../src/constants/reports.js'
import {mkdir,writeFile} from 'node:fs/promises'
import {resolve} from 'node:path'
import assert from 'node:assert/strict'
const api='http://127.0.0.1:8000/api/v1',origin='http://127.0.0.1:5176',out=resolve('audit/all-report-charts'),report={checks:[],failures:[],diagnostics:[]}
let browser,token
await mkdir(out,{recursive:true})
try{
 const response=await fetch(api+'/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({email:'super_admin@demo.shishli.local',password:'DemoTest123!'})})
 token=(await response.json()).data.token
 browser=await browserClient(9314,out+'/browser')
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`})
 browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
  const headers=[{name:'Access-Control-Allow-Origin',value:origin},{name:'Content-Type',value:'application/json'}]
  if(request.method==='OPTIONS')return browser.send('Fetch.fulfillRequest',{requestId,responseCode:204,responseHeaders:[...headers,{name:'Access-Control-Allow-Headers',value:'Authorization, Content-Type, Accept'},{name:'Access-Control-Allow-Methods',value:'GET, OPTIONS'}]})
  const key=new URL(request.url).pathname.split('/reports/')[1],definition=reportDefinitions.find(item=>item.key===key)
  const rows=Array.from({length:3},(_,index)=>Object.fromEntries((definition.columns||[]).map(column=>[column.key,column.type==='number'||column.type==='money'?10*(index+1):column.type==='date'?'2026-10-0'+(index+1):column.type==='datetime'?'2026-10-01T10:00:00Z':column.type==='orderStatus'?['pending','completed','completed'][index]:column.type==='paymentStatus'?'pending':column.type==='method'?'cash':column.type==='orderType'?'pickup':'عنصر '+(index+1)])))
  const data=key==='inventory'?{as_of:'2026-10-01T10:00:00Z',tracked_products_count:20,tracked_inventories_count:30,low_stock_count:8,out_of_stock_count:3}:{period:{from:'2026-10-01',to:'2026-10-07',timezone:'Africa/Khartoum'},summary:{},data:rows,meta:{current_page:1,last_page:2,total:6}}
  await browser.send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:headers,body:Buffer.from(JSON.stringify({success:true,data})).toString('base64')})
 })
 await browser.send('Fetch.enable',{patterns:[{urlPattern:api+'/admin/reports/*',requestStage:'Request'}]})
 for(const definition of reportDefinitions.filter(item=>!process.env.CHART_FOCUS_ONLY||item.key==='products')){
  await browser.go(origin,'/admin/reports?report='+encodeURIComponent(definition.key));await browser.wait('document.querySelector(".report-chart")&&!document.querySelector(".report-loading")')
  assert.ok(await browser.evaluate('!!document.querySelector(".report-columns,.report-trend")'),definition.key)
  const values=await browser.evaluate('[...document.querySelectorAll(".report-chart-metric option")].map(option=>option.value)')
  for(const value of values){await browser.input('.report-chart-metric select',value);await browser.sleep(40);assert.ok(await browser.evaluate('!!document.querySelector(".report-columns,.report-trend")'))}
  if(definition.key==='orders'&&values.length){await browser.input('.report-chart-metric select','orders_count');await browser.sleep(40);assert.deepEqual(await browser.evaluate('[...document.querySelectorAll(".report-columns g[role=button]")].map(e=>e.getAttribute("data-value"))'),['1','2'])}
  for(const width of [1440,768,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),definition.key+' overflow');report.checks.push({key:definition.key,width,metrics:values.length})}
 }
 await browser.go(origin,'/admin/reports?report=products');await browser.wait('document.querySelector(".report-columns g[role=button]")')
 await browser.send('Emulation.setFocusEmulationEnabled',{enabled:true});await browser.evaluate('document.querySelector(".report-columns g[role=button]").focus()');await browser.wait('document.querySelector(".report-columns g[role=button]").getAttribute("aria-pressed")==="true"')
 for(const width of [1440,390]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await writeFile(out+'/products-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'))}
 report.diagnostics=browser.diagnostics;assert.deepEqual(report.diagnostics,[])
}catch(error){report.failures.push(error.message);process.exitCode=1}
finally{browser?.close();if(token)await fetch(api+'/logout',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json'}});await writeFile(out+(process.env.CHART_FOCUS_ONLY?'/interaction-report.json':'/report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report))}

