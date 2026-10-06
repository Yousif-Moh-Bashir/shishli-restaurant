import {browserClient} from './browser-client.mjs'
import {mkdir,writeFile} from 'node:fs/promises'
import assert from 'node:assert/strict'
import {resolve} from 'node:path'
const api='http://127.0.0.1:8000/api/v1',origin='http://127.0.0.1:5176',out=resolve('audit/admin-report-charts'),report={checks:[],failures:[],diagnostics:[]}
let token,browser,mode='series'
await mkdir(out,{recursive:true})
try{
 const login=await fetch(api+'/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({email:'super_admin@demo.shishli.local',password:'DemoTest123!'})})
 token=(await login.json()).data.token
 browser=await browserClient(9312,out+'/browser')
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`})
 browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
  const rows=mode==='empty'?[]:Array.from({length:7},(_,index)=>({period:'2026-10-0'+(index+1),completed_sales:mode==='zero'?0:[100,240,180,330,0,280,450][index],orders_count:index+2,completed_orders_count:index+1,average_order_value:50+index*5}))
  if(request.method==='OPTIONS')return browser.send('Fetch.fulfillRequest',{requestId,responseCode:204,responseHeaders:[{name:'Access-Control-Allow-Origin',value:origin},{name:'Access-Control-Allow-Headers',value:'Authorization, Content-Type, Accept'},{name:'Access-Control-Allow-Methods',value:'GET, OPTIONS'}]})
  await browser.send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:origin}],body:Buffer.from(JSON.stringify({success:true,data:{period:{from:'2026-10-01',to:'2026-10-07',timezone:'Africa/Khartoum'},summary:{completed_sales:1580,orders_count:35},data:rows}})).toString('base64')})
 })
 await browser.send('Fetch.enable',{patterns:[{urlPattern:api+'/admin/reports/sales/trend*',requestStage:'Request'}]})
 await browser.go(origin,'/admin/reports?report=sales/trend');await browser.wait('document.querySelectorAll(".report-trend g[role=button]").length===7');await browser.wait('!document.querySelector(".report-chart-selection")?.textContent.includes("غير متاح")')
 for(const width of [1440,1024,768,390]){
  await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false})
  assert.ok(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
  await writeFile(out+'/sales-'+width+'.png',Buffer.from((await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'))
  report.checks.push({width,overflow:false,points:7})
 }
 await browser.send('Emulation.setFocusEmulationEnabled',{enabled:true})
 await browser.evaluate('document.querySelector(".report-trend g[role=button]").focus()')
 await browser.sleep(200)
 assert.equal(await browser.evaluate('document.querySelector(".report-trend g[role=button]").getAttribute("aria-pressed")'), 'true')
 for(const metric of ['orders_count','completed_orders_count','average_order_value','completed_sales']){await browser.input('.report-chart-metric select',metric);await browser.sleep(80);assert.ok(await browser.evaluate('document.querySelector(".report-chart-selection").textContent.length>5'));report.checks.push({metric})}
 mode='zero';await browser.click('.admin-page-header button','تحديث');await browser.wait('document.querySelector(".report-chart .empty")');assert.equal(await browser.evaluate('!!document.querySelector(".report-trend")'),false);report.checks.push({state:'zero'})
 mode='empty';await browser.click('.admin-page-header button','تحديث');await browser.wait('document.querySelector(".report-chart .empty")');report.checks.push({state:'empty'})
 report.diagnostics=browser.diagnostics;assert.deepEqual(report.diagnostics,[])
}catch(error){report.failures.push(error.message);process.exitCode=1}
finally{browser?.close();if(token)await fetch(api+'/logout',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json'}});await writeFile(out+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report))}
