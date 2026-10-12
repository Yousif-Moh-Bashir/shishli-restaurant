// Local fixture accounts only. Upload temporary records and delete them in finally.
import assert from 'node:assert/strict'
import {mkdir,writeFile} from 'node:fs/promises'
import {fileURLToPath} from 'node:url'
import {browserClient} from './browser-client.mjs'
const origin=process.env.DESIGN_APP_URL||'http://127.0.0.1:5175',api=process.env.DESIGN_API_URL,out=fileURLToPath(new URL('../.reports-check/final-auth-media/',import.meta.url)),checks=[],cleanup=[],failures=[]
assert.equal(new URL(api).port,'8017','This mutation suite requires the isolated QA API on port 8017')
await mkdir(out,{recursive:true});let browser,token
async function request(path,method='GET',body){const r=await fetch(api+path,{method,headers:{Accept:'application/json',...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(20000)});const data=await r.json();assert.ok(r.ok,path+' '+JSON.stringify(data.errors||data.message));return data.data}
try{
 browser=await browserClient(9291,out+'/fresh-'+Date.now())
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:"localStorage.removeItem('shishli_auth_token')"})
 await browser.go(origin,'/login');await browser.wait('document.querySelector("#login-phone")')
 await browser.input('#login-phone','customer1@demo.shishli.local');await browser.input('#login-password','IncorrectPassword123');await browser.click('#login-form button[type=submit]','تسجيل الدخول');await browser.wait('document.querySelector("#login-form [role=alert]")');assert.equal(await browser.evaluate('document.querySelector("#login-password").value'),'');checks.push('invalid login UI clears password and shows 401')
 await browser.input('#login-password','DemoTest123!');await browser.click('#login-form button[type=submit]','تسجيل الدخول');await browser.wait('location.pathname!=="/login"&&document.querySelector(".account-logout")');const customerToken=await browser.evaluate('localStorage.getItem("shishli_auth_token")');assert.ok(customerToken);checks.push('valid customer login through UI')
 await browser.click('.account-logout','خروج');await browser.wait('!localStorage.getItem("shishli_auth_token")');const revoked=await fetch(api+'/profile',{headers:{Accept:'application/json',Authorization:'Bearer '+customerToken}});assert.equal(revoked.status,401);checks.push('logout UI revokes actual token')
 token=(await request('/login','POST',{email:'super_admin@demo.shishli.local',password:'DemoTest123!'})).token
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('shishli_auth_token',${JSON.stringify(token)})`})
 await browser.go(origin,'/admin/cms');await browser.wait('document.querySelector("main .table-wrap")')
 for(const [kind,label]of [['gallery','معرض الصور'],['banners','البانرات']]){
  console.log('Starting '+kind);await browser.click('.module-tabs button',label);await browser.wait('document.querySelector("main .table-wrap")');console.log(kind+' list ready');await browser.click('main .page-heading button','إضافة');await browser.wait('document.querySelector("dialog[open]")');console.log(kind+' modal ready')
  const title='MEDIA-AUDIT-'+kind+'-'+Date.now();await browser.input('dialog[open] [id$="-title"]',title)
  const document=await browser.send('DOM.getDocument');const {nodeId}=await browser.send('DOM.querySelector',{nodeId:document.root.nodeId,selector:'dialog[open] input[id$="-image"]'});assert.ok(nodeId)
  await browser.send('DOM.setFileInputFiles',{nodeId,files:[fileURLToPath(new URL('../src/assets/images/logo/shishli-logo.png',import.meta.url))]});console.log(kind+' file selected')
  await browser.click('dialog[open] button','حفظ المحتوى');try{await browser.wait('!document.querySelector("dialog[open]")')}catch(error){throw Error(error.message+' '+await browser.evaluate('document.querySelector("dialog[open]")?.innerText'))}
  const records=await request('/admin/'+kind+'?search='+title);const row=records[0];assert.ok(row?.image_url);cleanup.push(()=>request('/admin/'+kind+'/'+row.id,'DELETE'))
  assert.ok((await fetch(row.image_url)).ok);checks.push(kind+' real UI image upload and served media')
 }
 assert.deepEqual(browser.diagnostics,[])
}finally{
 for(const undo of cleanup.reverse())try{await undo()}catch(e){failures.push(e.message)}
 if(token)await request('/logout','POST').catch(e=>failures.push(e.message));browser?.close()
 await writeFile(out+'/report.json',JSON.stringify({checks,diagnostics:browser?.diagnostics||[],cleanupFailures:failures},null,2));assert.deepEqual(failures,[])
}
console.log(JSON.stringify({checks:checks.length,diagnostics:browser.diagnostics.length}))
