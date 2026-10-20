// Run against Vite preview on port 5177. All API writes are intercepted fixtures.
import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {tmpdir} from 'node:os'
import {mkdir,writeFile} from 'node:fs/promises'
import {browserClient} from './browser-client.mjs'
const origin=process.env.FRONTEND_ORIGIN||'http://127.0.0.1:5177'
const output=resolve('.performance-check/cms-gallery');await mkdir(output,{recursive:true})
const browser=await browserClient(9399,resolve(tmpdir(),'shishli-cms-gallery-browser'))
const uuid='11111111-1111-4111-8111-111111111111'
const row={id:uuid,title:'صورة محفوظة',description:'وصف محفوظ',alt_text:'طبق مشويات',image_url:'/apple-touch-icon.png',sort_order:0,is_active:true,published_at:null}
let mode='error',held,rows=[row]
const writes=[]
async function fulfill(requestId,data,status=200,paginated=false){
 await browser.send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID'},{name:'Access-Control-Allow-Methods',value:'GET,POST,PATCH,OPTIONS'}],body:Buffer.from(JSON.stringify(status===422?{success:false,message:'تحقق من الصورة',errors:{image:['رسالة تحقق الصورة من الخادم']}}:{success:true,message:'Success',data,...(paginated?{meta:{current_page:1,last_page:1,per_page:20,total:data.length},links:{prev:null,next:null}}:{})})).toString('base64')})
}
browser.onEvent('Fetch.requestPaused',async({requestId,request})=>{
 try{
  const path=new URL(request.url).pathname
  if(request.method==='OPTIONS')return fulfill(requestId,{})
  if(path.endsWith('/admin/gallery')&&request.method==='POST'){
   writes.push(request)
   if(mode==='hold'){held=requestId;return}
   return fulfill(requestId,row,mode==='error'?422:201)
  }
  if(path.endsWith('/admin/gallery/'+uuid)&&request.method==='PATCH'){writes.push(request);return fulfill(requestId,row)}
  if(path.endsWith('/admin/gallery/'+uuid))return fulfill(requestId,row)
  if(path.endsWith('/admin/gallery'))return fulfill(requestId,rows,200,true)
  if(path==='/api/user')return fulfill(requestId,{uuid,name:'مدير الاختبار',is_driver:false})
  if(path.endsWith('/admin/me'))return fulfill(requestId,{id:uuid,name:'مدير الاختبار',is_active:true,roles:['manager'],permissions:['cms.gallery.view','cms.gallery.create','cms.gallery.update']})
  return fulfill(requestId,[])
 }catch{/* A request can be cancelled when its editor closes. */}
})
const dialog='dialog.admin-editor-dialog[open]'
async function file(kind='valid',method='drop'){
 return browser.evaluate(`(async()=>{
  const canvas=document.createElement('canvas');canvas.width=800;canvas.height=600;canvas.getContext('2d').fillRect(0,0,800,600);
  const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
  const selected=${JSON.stringify(kind)}==='invalid'?new File(['bad'],'not-an-image.txt',{type:'text/plain'}):${JSON.stringify(kind)}==='large'?new File([new Uint8Array(5*1024*1024+1)],'large.png',{type:'image/png'}):new File([blob],'gallery-photo.png',{type:'image/png'});
  const transfer=new DataTransfer();transfer.items.add(selected);
  if(${JSON.stringify(method)}==='drop')document.querySelector('.gallery-upload-zone').dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:transfer}));
  else{const input=document.querySelector('.gallery-file-input');input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}))}
 })()`)
}
try{
 await browser.send('Network.setBlockedURLs',{urls:['*://fonts.googleapis.com/*','*://fonts.gstatic.com/*']})
 await browser.send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/v1/*'},{urlPattern:'*://*/api/user'}]})
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:"localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli_auth_token','cms-fixture');window.revokedImages=[];const revoke=URL.revokeObjectURL.bind(URL);URL.revokeObjectURL=url=>{revokedImages.push(url);revoke(url)}"})
 await browser.go(origin,'/admin/cms')
 await browser.wait('document.querySelector("table")?.textContent.includes("صورة محفوظة")')
 await browser.click('button','إضافة صورة')
 await browser.wait('document.querySelector("dialog[open] .cms-gallery-editor")')
 assert.equal(await browser.evaluate('document.querySelectorAll("dialog[open] input[type=file]").length'),1)
 await browser.click(dialog+' button','حفظ المحتوى')
 await browser.wait('document.querySelector(".gallery-media-panel").textContent.includes("اختر صورة للمعرض قبل الحفظ")')
 assert.equal(writes.length,0)
 await file('invalid');await browser.wait('document.querySelector(".gallery-media-panel").textContent.includes("اختر صورة بصيغة")')
 await file('large');await browser.wait('document.querySelector(".gallery-media-panel").textContent.includes("حجم الصورة")')
 await file();await browser.wait('document.querySelector(".gallery-image-preview img")?.src.startsWith("blob:")')
 const first=await browser.evaluate('document.querySelector(".gallery-image-preview img").src')
 await browser.click('.gallery-selected-file button','مسح الاختيار')
 await browser.wait('!document.querySelector(".gallery-image-preview")')
 assert.equal(await browser.evaluate(`revokedImages.includes(${JSON.stringify(first)})`),true)
 await file('valid','input')
 await browser.wait('document.querySelector(".gallery-image-preview img")?.complete')
 for(const width of [320,390,768,1440]){
  await browser.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<700})
  assert.equal(await browser.evaluate('document.querySelector("dialog[open]").scrollWidth<=document.querySelector("dialog[open]").clientWidth+1'),true,'Modal overflow '+width)
  assert.equal(await browser.evaluate('getComputedStyle(document.querySelector("dialog[open]")).direction'),'rtl')
  const shot=await browser.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})
  await writeFile(resolve(output,width+'.png'),Buffer.from(shot.data,'base64'))
 }
 await browser.click(dialog+' button','حفظ المحتوى')
 await browser.wait('document.querySelector(".gallery-media-panel").textContent.includes("رسالة تحقق الصورة من الخادم")')
 assert.equal(writes[0].method,'POST')
 assert.match(Object.entries(writes[0].headers).find(([name])=>name.toLowerCase()==='content-type')[1],/multipart\/form-data/)
 assert.equal(await browser.evaluate('document.querySelector(".gallery-image-preview img").src.startsWith("blob:")'),true)
 mode='hold';await browser.click(dialog+' button','حفظ المحتوى')
 await browser.wait('document.querySelector(".gallery-file-input").disabled')
 const previous=await browser.evaluate('document.querySelector(".gallery-image-preview img").src')
 await file('valid')
 assert.equal(await browser.evaluate('document.querySelector(".gallery-image-preview img").src'),previous)
 while(!held)await browser.sleep(20)
 await fulfill(held,row,201)
 await browser.wait('!document.querySelector("dialog.admin-editor-dialog[open]")')
 await browser.click('table button','تعديل')
 await browser.wait('document.querySelector(".gallery-preview-label")?.textContent==="الصورة الحالية"')
 assert.equal(await browser.evaluate('document.querySelector(".gallery-image-preview img").getAttribute("src")'),row.image_url)
 await file();await browser.wait('document.querySelector(".gallery-image-preview img").src.startsWith("blob:")')
 await browser.click('.gallery-selected-file button','التراجع عن الاستبدال')
 await browser.wait('document.querySelector(".gallery-preview-label").textContent==="الصورة الحالية"')
 await browser.click(dialog+' button','حفظ المحتوى')
 await browser.wait('!document.querySelector("dialog.admin-editor-dialog[open]")')
 assert.equal(writes.at(-1).method,'PATCH')
 assert.equal(Object.hasOwn(JSON.parse(writes.at(-1).postData),'image'),false)
 assert.deepEqual(browser.diagnostics,[])
 console.log('PASS: required image, drag/drop and file picker preview, type/size validation, URL cleanup, responsive RTL modal, multipart save, 422 retention, busy guards, existing image and replacement undo')
}finally{browser.close()}
