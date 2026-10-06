import {fileURLToPath} from 'node:url'
import {writeFile} from 'node:fs/promises'
import assert from 'node:assert/strict'
import {browserClient} from './browser-client.mjs'
const output=fileURLToPath(new URL('../.kitchen-detail-check/',import.meta.url))
const client=await browserClient(9368,output),report={checks:[],responsive:[],diagnostics:[]}
try{
 await client.go('http://127.0.0.1:5176','/')
 await client.evaluate(`(async()=>{
  const {createApp,h,reactive}=await import('/node_modules/.vite/deps/vue.js');const {default:Details}=await import('/src/components/orders/KitchenOrderDetails.vue');
  document.querySelector('#app').__vue_app__.unmount();document.querySelector('#app').innerHTML='';const root=document.createElement('div');root.className='admin-layout';root.style.padding='20px';document.querySelector('#app').append(root);
  window.detailState=reactive({order:{id:'test',order_number:'01M44JFB28QD2WCD40KWYMGE24',status:'preparing',type:'delivery',branch:{name:'فرع المصيف'},placed_at:'2026-10-05T10:00:00Z',timeline:{confirmed_at:'2026-10-05T10:05:00Z'},scheduled_for:'2026-10-05T11:00:00Z',items:[{id:'a',quantity:2,product:{name:'دجاج تركي مع أرز'},options:[{group:{name:'الإضافات'},value:{name:'صوص إضافي'}}]},{id:'b',quantity:1,product:{name:'شيش طاووق'},options:[]}],notes:'بدون بصل، الصوص على الجانب.'},syncedAt:'2026-10-05T10:15:00Z'});
  window.detailAction=0;createApp({render:()=>h(Details,{...detailState},{default:()=>h('div',{class:'form-actions'},[h('button',{class:'button',onClick:()=>detailAction++},'جاهز للتسليم')])})}).mount(root);
 })()`)
 await client.wait('document.querySelector(".kitchen-detail-layout")')
 assert.equal(await client.evaluate('document.querySelector(".kitchen-detail-count strong").textContent'),'3')
 assert.equal(await client.evaluate('document.querySelector(".order-number-short").textContent'),'KWYMGE24')
 assert.ok(await client.evaluate('document.body.textContent.includes("صوص إضافي")&&document.body.textContent.includes("بدون بصل")&&document.body.textContent.includes("وقت التأكيد")&&document.body.textContent.includes("الموعد المحدد")'))
 await client.click('.kitchen-detail-operations button','جاهز')
 assert.equal(await client.evaluate('detailAction'),1)
 report.checks.push('Order number, quantities, option snapshots, customer notes, timeline confirmation, schedule and operation slot preserved')
 for(const width of [390,768,1440]){
  await client.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390})
  assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true)
  const image=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(output+'/details-'+width+'.png',Buffer.from(image.data,'base64'));report.responsive.push(width)
 }
 await client.evaluate('detailState.order.notes="";detailState.order.scheduled_for=null;detailState.order.timeline.confirmed_at=null')
 await client.wait('!document.querySelector(".kitchen-notes")')
 assert.equal(await client.evaluate('document.body.textContent.includes("وقت التأكيد")||document.body.textContent.includes("الموعد المحدد")'),false)
 report.checks.push('Missing optional notes, schedule and confirmation omit sections without fake values; isolated fixtures')
 report.diagnostics=client.diagnostics;assert.deepEqual(report.diagnostics,[])
 await writeFile(output+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}finally{client.close()}
