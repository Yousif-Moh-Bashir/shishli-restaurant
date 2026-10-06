import {browserClient} from './browser-client.mjs'
const api='http://127.0.0.1:8000/api/v1',origin='http://127.0.0.1:5176'
const response=await fetch(api+'/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({email:'super_admin@demo.shishli.local',password:'DemoTest123!'})})
const token=(await response.json()).data.token
let browser
try{
 browser=await browserClient(9309,new URL('../audit/admin-ui-debug/',import.meta.url).pathname.replace(/^\/(\w:)/,'$1'))
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`})
 await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:false})
 for(const path of ['/admin','/admin/products','/admin/staff']){
  await browser.go(origin,path);await browser.sleep(2000)
  console.log(JSON.stringify({path,layout:await browser.evaluate(`({width:innerWidth,scroll:document.documentElement.scrollWidth,offenders:[...document.querySelectorAll('main *')].filter(e=>{const r=e.getBoundingClientRect();return e.getClientRects().length&&(r.left<-1||r.right>innerWidth+1)&&!e.closest('.table-wrap,.sidebar')}).map(e=>({tag:e.tagName,cls:e.className,rect:[e.getBoundingClientRect().left,e.getBoundingClientRect().right],display:getComputedStyle(e).display,min:getComputedStyle(e).minWidth})).slice(0,20),buttons:[...document.querySelectorAll('main .page-heading button')].map(e=>({text:e.textContent,disabled:e.disabled})),skeletons:document.querySelectorAll('[aria-busy=true]').length})`)}))
  if(path==='/admin/staff'){await browser.click('main button','إضافة موظف');await browser.sleep(1000);console.log(JSON.stringify({modal:await browser.evaluate('({open:!!document.querySelector("dialog[open]"),text:document.querySelector("dialog")?.textContent.slice(0,300)})'),diagnostics:browser.diagnostics}))}
 }
}finally{browser?.close();await fetch(api+'/logout',{method:'POST',headers:{Accept:'application/json',Authorization:'Bearer '+token}})}
