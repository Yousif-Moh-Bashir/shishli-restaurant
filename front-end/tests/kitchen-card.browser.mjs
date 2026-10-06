import {fileURLToPath} from 'node:url'
import {writeFile} from 'node:fs/promises'
import assert from 'node:assert/strict'
import {browserClient} from './browser-client.mjs'
const output=fileURLToPath(new URL('../.kitchen-card-check/',import.meta.url))
const client=await browserClient(9362,output),report={checks:[],responsive:[],diagnostics:[]}
try{
 await client.go('http://127.0.0.1:5176','/')
 await client.evaluate(`(async()=>{
  const {createApp,h,reactive}=await import('/node_modules/.vite/deps/vue.js');const {default:KitchenCard}=await import('/src/components/orders/KitchenCard.vue');
  const oldApp=document.querySelector('#app').__vue_app__,router=oldApp.config.globalProperties.$router;oldApp.unmount();document.querySelector('#app').innerHTML='';const root=document.createElement('div');root.className='admin-layout';root.style.padding='20px';document.querySelector('#app').append(root);
  window.focusedIds=[];window.cardState=reactive({disabled:false});window.orders=['confirmed','preparing','ready'].map((status,index)=>({id:'kitchen-'+index,order_number:'01M44JFB28QD2WCD40KWYMGE24',status,type:index===1?'delivery':'pickup',branch:{name:'فرع المصيف'},confirmed_at:'2026-10-05T10:00:00Z',items:[{id:'a',quantity:2,product:{name:'دجاج تركي مع أرز'},options:[{group:{name:'الإضافات'},value:{name:'صوص إضافي'}}]},{id:'b',quantity:1,product:{name:'شيش طاووق'},options:[]}],customer_notes:index===1?'الصوص على الجانب، بدون بصل.':''}));
  const app=createApp({render:()=>h('div',{style:'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:20px'},orders.map(order=>h(KitchenCard,{order,elapsed:'12 دقيقة',disabled:cardState.disabled,onFocus:id=>focusedIds.push(id)})))});app.use(router);app.mount(root);
 })()`)
 await client.wait('document.querySelectorAll(".kitchen-order-card").length===3')
 assert.equal(await client.evaluate('document.querySelector(".order-number-short").textContent'),'KWYMGE24')
 assert.equal(await client.evaluate('document.querySelector(".kitchen-card-items-heading span").textContent'),'3 قطعة')
 assert.ok(await client.evaluate('document.body.textContent.includes("صوص إضافي")&&document.body.textContent.includes("بدون بصل")'))
 assert.equal(await client.evaluate('document.querySelectorAll(".kitchen-order-card")[1].dataset.status'),'preparing')
 assert.equal(await client.evaluate('new Set([...document.querySelectorAll(".kitchen-card-timing strong")].map(e=>getComputedStyle(e).color)).size'),1)
 assert.equal(await client.evaluate('new Set([...document.querySelectorAll(".kitchen-order-card .order-status-badge")].map(e=>getComputedStyle(e).backgroundColor)).size'),1)
 await client.click('.kitchen-card-actions button','إجراءات')
 assert.deepEqual(await client.evaluate('focusedIds'),['kitchen-0'])
 assert.ok(await client.evaluate('document.querySelector(".kitchen-card-actions a").getAttribute("href").endsWith("/admin/kitchen/kitchen-0")'))
 await client.evaluate('cardState.disabled=true')
 await client.wait('[...document.querySelectorAll(".kitchen-card-actions button")].every(e=>e.disabled)')
 report.checks.push('Short/full order number, stage styling, quantities, options, notes, correct action UUID and detail link, busy guard')
 await client.evaluate('cardState.disabled=false')
 for(const width of [390,768,1440]){
  await client.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390})
  assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true)
  const image=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(output+'/kitchen-'+width+'.png',Buffer.from(image.data,'base64'));report.responsive.push(width)
 }
 report.diagnostics=client.diagnostics;assert.deepEqual(report.diagnostics,[])
 await writeFile(output+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}finally{client.close()}
