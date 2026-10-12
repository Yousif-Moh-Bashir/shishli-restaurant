import {browserClient} from './browser-client.mjs'
import {resolve} from 'node:path'
const api='http://127.0.0.1:8017/api/v1'
const response=await fetch(api+'/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({email:'customer1@demo.shishli.local',password:'DemoTest123!'})})
const {data}=await response.json(),browser=await browserClient(9392,resolve('.performance-check/qa-address'))
try{
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(data.token)})`})
 await browser.send('Emulation.setDeviceMetricsOverride',{width:320,height:1000,deviceScaleFactor:1,mobile:true})
 await browser.go('http://127.0.0.1:5178','/account/addresses');await browser.wait('document.querySelector(".address-card")');await browser.sleep(1000)
 console.log(await browser.evaluate('JSON.stringify([...document.querySelectorAll("main *")].map(e=>({tag:e.tagName,class:e.className,text:e.textContent.slice(0,60),left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right})).filter(e=>e.left<0||e.right>321))'))
}finally{await fetch(api+'/logout',{method:'POST',headers:{Accept:'application/json',Authorization:'Bearer '+data.token}});browser.close()}
