import assert from 'node:assert/strict'
import {browserClient} from './browser-client.mjs'
const api='http://127.0.0.1:8000/api/v1';let token,browser
try{
 const response=await(await fetch(api+'/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({email:'super_admin@demo.shishli.local',password:'DemoTest123!'})})).json();token=response.data.token
 const counts=await(await fetch(api+'/admin/orders/counts',{headers:{Accept:'application/json',Authorization:'Bearer '+token}})).json();const statuses=['pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled'];const expected=statuses.map(s=>String(counts.data[s]))
 browser=await browserClient(9296,'C:/xampp/htdocs/projects/shishli/front-end/.reports-check/order-counts/'+Date.now());await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`});await browser.go('http://127.0.0.1:5175','/admin/orders');await browser.wait('document.querySelectorAll(".order-tabs button").length===7&&!document.querySelector(".order-tabs button:disabled")')
 const values=()=>browser.evaluate('[...document.querySelectorAll(".order-tabs button span")].map(e=>e.textContent)');assert.deepEqual(await values(),expected)
 for(let i=0;i<7;i++){await browser.evaluate(`document.querySelectorAll('.order-tabs button')[${i}].click()`);await browser.wait('document.querySelectorAll(".order-tabs button").length===7&&!document.querySelector(".order-tabs button:disabled")');assert.deepEqual(await values(),expected,'status tab '+i)}
 assert.deepEqual(browser.diagnostics,[]);console.log('PASS: all 7 status tabs preserve real server counts; no browser diagnostics')
}finally{browser?.close();if(token)await fetch(api+'/logout',{method:'POST',headers:{Accept:'application/json',Authorization:'Bearer '+token}})}
