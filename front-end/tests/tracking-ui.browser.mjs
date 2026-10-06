import {fileURLToPath} from 'node:url'
import assert from 'node:assert/strict'
import {browserClient} from './browser-client.mjs'
const client=await browserClient(9382,fileURLToPath(new URL('../.checkout-check/tracking-number-profile/',import.meta.url)))
try{
 await client.go('http://127.0.0.1:5176','/')
 await client.evaluate(`(async()=>{
 const {createApp,h}=await import('/node_modules/.vite/deps/vue.js');const {createPinia}=await import('/node_modules/.vite/deps/pinia.js');const {useAuthStore}=await import('/src/stores/auth.js');const {ordersApi}=await import('/src/api/orders.api.js');const {default:Panel}=await import('/src/components/orders/OrderTrackingPanel.vue');
 document.querySelector('#app').__vue_app__.unmount();document.querySelector('#app').innerHTML='';const root=document.createElement('div');root.className='site-layout';root.style.padding='20px';document.querySelector('#app').append(root);const pinia=createPinia();const app=createApp({render:()=>h(Panel,{orderUuid:'9135ca59-f7c9-4c69-9e6b-051be49598a0',customerOnly:true})});app.use(pinia);const auth=useAuthStore(pinia);auth.token='fixture';auth.user={id:'fixture'};
 window.trackingCalls=0;window.trackingFailure=false;window.trackingFixture={order:{uuid:'9135ca59-f7c9-4c69-9e6b-051be49598a0',number:'SH-0033',type:'pickup',status:{code:'preparing'},placed_at:'2026-10-06T01:30:00Z'},timeline:[{status:'pending',state:'completed',occurred_at:'2026-10-06T01:30:00Z'},{status:'preparing',state:'current',occurred_at:'2026-10-06T01:35:00Z'},{status:'ready',state:'upcoming'}],progress:{percentage:50},estimates:{ready_at:'2026-10-06T02:00:00Z'},branch:{name:'فرع المصيف',address:'عنوان الفرع',phone:'05555552144'},payment:{method:'network',status:'pending'},items:[{uuid:'item',name:'نصف دجاج تركي مع أرز واسم طويل لاختبار التفاف النص',quantity:2,options:[{group:'إضافات',name:'صوص'}],line_total_after_discount:'34.00'}],summary:{total:'34.00'},last_updated_at:'2026-10-06T01:35:00Z'};
 ordersApi.tracking=async()=>{trackingCalls++;if(trackingFailure)throw {response:{status:500,data:{message:'تعذر الاتصال'}}};return structuredClone(trackingFixture)};app.mount(root);
 })()`)
 await client.wait('document.querySelector(".tracking-number")')
 assert.equal(await client.evaluate('document.querySelector(".tracking-number").textContent'),'SH-0033')
 assert.equal(await client.evaluate('document.querySelector("progress").value'),50)
 assert.ok(await client.evaluate('document.querySelector(".tracking-panel").textContent.includes("شبكة")'))
 for(const width of [360,390,768,1024,1440]){
  await client.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500})
  assert.ok(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
  assert.deepEqual(await client.evaluate(`(()=>{const cards=[...document.querySelectorAll('.tracking-section')];return cards.flatMap((card,i)=>{const r=card.getBoundingClientRect();return cards.slice(i+1).filter(next=>{const n=next.getBoundingClientRect();return Math.min(r.right,n.right)-Math.max(r.left,n.left)>1&&Math.min(r.bottom,n.bottom)-Math.max(r.top,n.top)>1}).map(next=>next.className)})})()`),[])
 }
 await client.evaluate("trackingFixture.order.status.code='ready'")
 await client.click('.tracking-refresh','تحديث')
 await client.wait('document.querySelector(".tracking-status-card").textContent.includes("جاهز للاستلام")')
 await client.evaluate('trackingFailure=true')
 await client.click('.tracking-refresh','تحديث')
 await client.wait('document.querySelector("[role=alert]")')
 assert.ok(await client.evaluate('document.querySelector(".tracking-number").textContent.includes("SH-0033")'))
 assert.deepEqual(client.diagnostics,[])
 console.log('Passed: tracking number, network payment, server progress, ready-state refresh, retained data on error, five responsive sizes without overlap; no browser errors. Isolated API fixtures only.')
}finally{client.close()}
