import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {writeFile} from 'node:fs/promises'
import {browserClient} from './browser-client.mjs'
const api=process.env.DESIGN_API_URL,origin=process.env.DESIGN_APP_URL
assert.equal(new URL(api).port,'8017');assert.equal(new URL(origin).port,'5178')
const browser=await browserClient(9393,resolve('.performance-check/qa-remaining-pages')),tokens=[],report={pages:[],responsive:[]}
async function request(path,token,body){const response=await fetch(api+path,{method:body?'POST':'GET',headers:{Accept:'application/json',...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});assert.ok(response.ok,path+' '+response.status);return (await response.json()).data}
let init
async function identity(token){if(init)await browser.send('Page.removeScriptToEvaluateOnNewDocument',{identifier:init});init=(await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`})).identifier}
try{
 const admin=(await request('/login',null,{email:'super_admin@demo.shishli.local',password:'DemoTest123!'})).token;tokens.push(admin)
 const customer=(await request('/login',null,{email:'customer1@demo.shishli.local',password:'DemoTest123!'})).token;tokens.push(customer)
 const products=await request('/products'),orders=await request('/orders',customer),notifications=await request('/admin/notifications',admin)
 assert.ok(products[0]&&orders[0])
 const cases=[[admin,'/admin/drivers'],[customer,'/product/'+products[0].slug],[customer,'/forbidden'],[customer,'/track-order/'+orders[0].id],[customer,'/order-success/'+orders[0].id]]
 if(notifications[0])cases.push([admin,'/admin/notifications/'+notifications[0].id])
 for(const [token,path] of cases){await identity(token);await browser.go(origin,path);await browser.sleep(1400);for(const width of [320,360,375,390,412,430,768,1024,1366,1920]){await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<700});await browser.sleep(100);assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true,path+' '+width);report.responsive.push({path,width})}report.pages.push(path)}
 assert.deepEqual(browser.diagnostics,[]);report.result='passed';console.log(JSON.stringify({result:report.result,pages:report.pages.length,responsive:report.responsive.length}))
}catch(error){report.result='failed';report.error=error.message;throw error}finally{await writeFile(resolve('../docs/qa/remaining-pages-browser.json'),JSON.stringify(report,null,2));for(const token of tokens)await request('/logout',token,{}).catch(()=>{});browser.close()}
