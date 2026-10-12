import {fileURLToPath} from 'node:url'
import {writeFile} from 'node:fs/promises'
import assert from 'node:assert/strict'
import {browserClient} from './browser-client.mjs'
const output=fileURLToPath(new URL('../.kitchen-page-check/',import.meta.url)),client=await browserClient(9372,output)
try{
 await client.go('http://127.0.0.1:5176','/')
 await client.evaluate(`(async()=>{
  const source=await(await fetch('/src/views/admin/KitchenView.vue')).text();const vueUrl=source.match(/from ["']([^"']*vue[.]js[^"']*)["']/)[1];const {createApp,h}=await import(vueUrl);const routerUrl=source.match(/from ["']([^"']*vue-router[^"']*)["']/)[1];const {createRouter,createMemoryHistory,RouterView}=await import(routerUrl);const {default:Kitchen}=await import('/src/views/admin/KitchenView.vue');const {useAuthStore}=await import('/src/stores/auth.js');useAuthStore().can=()=>true;
  const {adminOrdersApi}=await import('/src/api/orders.api.js');const {branchesApi}=await import('/src/api/branches.api.js');branchesApi.list=async()=>({data:[{id:'branch-a',name:'فرع المصيف'}],meta:{last_page:1}});window.kitchenCalls=[];window.showMode='success';window.operationCalls=0;
  adminOrdersApi.show=async()=>{if(showMode==='error')throw Error('تعذر تحميل إجراءات الطلب');if(showMode==='slow')await new Promise(resolve=>window.releaseShow=resolve);return {id:'test',order_number:'01M44JFB28QD2WCD40KWYMGE24',status:'preparing',available_actions:['mark_ready','cancel']}};adminOrdersApi.operate=async()=>{operationCalls++;throw Error('No live mutation allowed')};
  adminOrdersApi.kitchen=async params=>{kitchenCalls.push({...params});return {data:params.type==='delivery'?[]:[{id:'test',order_number:'01M44JFB28QD2WCD40KWYMGE24',status:params.status||'confirmed',type:'pickup',placed_at:new Date().toISOString(),branch:{name:'فرع المصيف'},items:[{id:'a',quantity:2,product:{name:'دجاج تركي'},options:[]}]}],meta:{total:params.type==='delivery'?0:1,current_page:1,last_page:1,per_page:20},links:{}}};
  const old=document.querySelector('#app').__vue_app__,pinia=old.config.globalProperties.$pinia;old.unmount();document.querySelector('#app').innerHTML='';const root=document.createElement('div');root.className='admin-layout';document.querySelector('#app').append(root);const router=createRouter({history:createMemoryHistory(),routes:[{path:'/admin/kitchen',component:Kitchen},{path:'/admin/kitchen/:id',component:{render:()=>h('div')}}]});await router.push('/admin/kitchen');const app=createApp({render:()=>h(RouterView)});app.use(pinia);app.use(router);app.mount(root);
 })()`)
 await client.wait('document.querySelector(".kitchen-order-card")')
 assert.equal(await client.evaluate('kitchenCalls.at(-1).period'),'current')
 await client.click('.admin-order-period button','الطلبات السابقة');await client.wait('window.kitchenCalls.at(-1).period==="history"')
 await client.click('.admin-order-period button','يوم العمل الحالي');await client.wait('window.kitchenCalls.at(-1).period==="current"')
 await client.click('.kitchen-stage-tabs button','جاهزة')
 await client.wait('document.querySelector(".kitchen-order-card")?.dataset.status==="ready"')
 assert.equal(await client.evaluate('kitchenCalls.at(-1).status'),'ready')
 await client.input('[name=kitchen_type]','delivery')
 await client.click('.kitchen-filter-actions button','تطبيق')
 await client.wait('document.querySelector(".kitchen-empty")')
 assert.equal(await client.evaluate('kitchenCalls.at(-1).type'),'delivery')
 await client.click('.kitchen-empty button','عرض الطلبات')
 await client.wait('document.querySelector(".kitchen-order-card")?.dataset.status==="confirmed"')
 assert.equal(await client.evaluate('kitchenCalls.at(-1).status'),'')
 assert.equal(await client.evaluate('kitchenCalls.at(-1).type'),'')
 for(const width of [390,768,1440]){await client.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);const image=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(output+'/page-'+width+'.png',Buffer.from(image.data,'base64'))}
 await client.click('.kitchen-card-actions button','إجراءات')
 await client.wait('document.querySelector(".kitchen-actions[open] .button-success")')
 assert.equal(await client.evaluate('document.querySelectorAll(".kitchen-actions[open]>.form-actions>button").length'),2)
 await client.click('.kitchen-actions .button-success','جاهز')
 await client.wait('document.querySelectorAll("dialog[open]").length===2')
 assert.equal(await client.evaluate('document.querySelector(".kitchen-actions>.kitchen-focused-heading button").disabled'),true)
 await client.click('.kitchen-actions dialog[open] button','رجوع')
 await client.wait('document.querySelectorAll("dialog[open]").length===1')
 await client.click('.kitchen-actions>.kitchen-focused-heading button','إغلاق')
 await client.wait('!document.querySelector(".kitchen-actions[open]")');await client.sleep(100)
 await client.evaluate('window.showMode="error"')
 await client.click('.kitchen-card-actions button','إجراءات')
 await client.wait('document.querySelector(".kitchen-actions[open] [role=alert]")')
 await client.click('.kitchen-actions>.kitchen-focused-heading button','إغلاق')
 await client.wait('!document.querySelector(".kitchen-actions[open]")');await client.sleep(100)
 await client.evaluate('window.showMode="slow"')
 await client.click('.kitchen-card-actions button','إجراءات')
 await client.wait('document.querySelector(".kitchen-actions[open]")&&typeof releaseShow==="function"')
 await client.click('.kitchen-actions>.kitchen-focused-heading button','إغلاق')
 await client.evaluate('window.releaseShow()')
 await client.wait('!document.querySelector(".kitchen-actions[open]")');await client.sleep(100)
 assert.equal(await client.evaluate('operationCalls'),0)
 assert.deepEqual(client.diagnostics,[])
 const report={checks:['Phase query, type filter, empty result and reset, responsive page','Action dialog opens, allowed operations shown, nested confirmation cancel, active close guard, visible API error, stale response ignored after close'],responsive:[390,768,1440],diagnostics:client.diagnostics};await writeFile(output+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}finally{client.close()}
