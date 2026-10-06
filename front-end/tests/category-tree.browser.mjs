import {fileURLToPath} from 'node:url'
import {writeFile} from 'node:fs/promises'
import assert from 'node:assert/strict'
import {browserClient} from './browser-client.mjs'
const output=fileURLToPath(new URL('../.category-tree-check/',import.meta.url))
const client=await browserClient(9356,output),report={checks:[],responsive:[],diagnostics:[]}
try{
 await client.go('http://127.0.0.1:5176','/')
 await client.evaluate(`(async()=>{
  const {createApp,h,reactive}=await import('/node_modules/.vite/deps/vue.js');const {default:CategoryTree}=await import('/src/components/admin/catalog/CategoryTree.vue');
  document.querySelector('#app').__vue_app__.unmount();document.querySelector('#app').innerHTML='';const root=document.createElement('div');root.className='admin-layout';root.style.padding='20px';document.querySelector('#app').append(root);
  window.treeEvents=[];window.treeState=reactive({nodes:[{id:'root',name:'القسم الرئيسي',is_active:true,children:[{id:'child',name:'المشويات',is_active:true,children:[{id:'leaf',name:'شيش طاووق',is_active:false,children:[]}]}]},{id:'other',name:'المشروبات',is_active:false,children:[]}],canEdit:true,canDelete:true,busy:false,loading:false,error:null});
  createApp({render:()=>h(CategoryTree,{...treeState,onEdit:node=>treeEvents.push(['edit',node.id]),onDelete:node=>treeEvents.push(['delete',node.id]),onRetry:()=>treeEvents.push(['retry'])})}).mount(root);
 })()`)
 await client.wait('document.querySelectorAll(".category-tree-row").length===4')
 await client.click('.category-tree-controls button','طي الكل')
 await client.wait('document.querySelectorAll(".category-tree-row").length===2')
 await client.input('.category-tree-search input','شيش')
 await client.wait('document.querySelectorAll(".category-tree-row").length===3')
 assert.equal(await client.evaluate('[...document.querySelectorAll(".category-tree-name strong")].map(e=>e.textContent).join("/")'),'القسم الرئيسي/المشويات/شيش طاووق')
 await client.input('.category-tree-search input','غير موجود')
 await client.wait('document.querySelector(".category-tree-empty")')
 await client.input('.category-tree-search input','')
 await client.click('.category-tree-controls button','توسيع الكل')
 await client.wait('document.querySelectorAll(".category-tree-row").length===4')
 await client.evaluate('[...document.querySelectorAll(".category-tree-actions button")].filter(button=>button.getAttribute("aria-label").endsWith("شيش طاووق")).forEach(button=>button.click())')
 assert.deepEqual(await client.evaluate('treeEvents'),[['edit','leaf'],['delete','leaf']])
 await client.evaluate('treeState.busy=true')
 await client.wait('[...document.querySelectorAll(".category-tree-actions button")].every(e=>e.disabled)')
 await client.evaluate('treeState.busy=false;treeState.canEdit=false;treeState.canDelete=false')
 await client.wait('!document.querySelector(".category-tree-actions")')
 report.checks.push('Nested hierarchy, collapse/expand, search with ancestor path, empty search, action UUIDs, busy and read-only permissions')
 for(const width of [390,768,1440]){
  await client.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390})
  await client.evaluate('treeState.canEdit=true;treeState.canDelete=true')
  await client.wait('document.querySelector(".category-tree-actions")')
  assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true)
  const image=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(output+'/tree-'+width+'.png',Buffer.from(image.data,'base64'))
  report.responsive.push(width)
 }
 await client.evaluate('treeState.nodes=[]')
 await client.wait('document.querySelector(".category-tree-empty").textContent.includes("لم تُضف")')
 report.checks.push('Empty tree and responsive layout; isolated component fixtures without API mutations')
 report.diagnostics=client.diagnostics;assert.deepEqual(report.diagnostics,[])
 await writeFile(output+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}finally{client.close()}
