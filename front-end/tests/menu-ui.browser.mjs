import { fileURLToPath } from 'node:url'
import { writeFile } from 'node:fs/promises'
import assert from 'node:assert/strict'
import { browserClient } from './browser-client.mjs'
const output=fileURLToPath(new URL('../.menu-ui-check/',import.meta.url))
const client=await browserClient(9351,output),report={checks:[],responsive:[],diagnostics:[]}
try {
  await client.go('http://127.0.0.1:5176','/')
  await client.evaluate(`(async()=>{
    const {useBranchStore}=await import('/src/stores/branch.js');const branch=useBranchStore();await branch.load();branch.branches=[{id:'11111111-1111-4111-8111-111111111111',name:'فرع الاختبار',is_active:true}];branch.initialized=true;branch.error=null;branch.select(branch.branches[0].id);
    const {categoriesApi}=await import('/src/api/categories.api.js');categoriesApi.publicList=async()=>[{id:'chicken',name:'الدجاج',children:[]},{id:'grills',name:'المشويات',children:[]}];
    const {menuApi}=await import('/src/api/products.api.js');window.menuCalls=[];window.menuFailure=false;
    menuApi.list=async params=>{menuCalls.push({...params});if(menuFailure)throw Error('تعذر تحميل المنيو');const products=[{id:'22222222-2222-4222-8222-222222222222',name:'دجاج الاختبار',price:'25.50',description:'وصف للاختبار',available:true,is_featured:true,category:'chicken'},{id:'33333333-3333-4333-8333-333333333333',name:'مشويات الاختبار',price:'45.00',available:false,category:'grills'}].filter(item=>(!params.search||item.name.includes(params.search))&&(!params.category||item.category===params.category)&&(!params.available||item.available===(params.available==='1'))&&(!params.featured||!!item.is_featured===(params.featured==='1')));return {categories:[{id:'test',products}],availability:{}}};
    await document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/menu');
  })()`)
  await client.wait('document.querySelectorAll(".site-menu .product-card").length===2')
  assert.equal(await client.evaluate('document.querySelectorAll(".site-menu .add-button")[1].disabled'),true)
  assert.ok(await client.evaluate('document.querySelector(".site-menu .price").textContent.includes("25.50")'))
  await client.input('#product-search','دجاج')
  await client.wait('document.querySelectorAll(".site-menu .product-card").length===1')
  assert.equal(await client.evaluate('menuCalls.at(-1).search'),'دجاج')
  await client.click('.menu-reset','مسح')
  await client.wait('document.querySelectorAll(".site-menu .product-card").length===2')
  await client.click('.category-filters button','المشويات')
  await client.wait('document.querySelectorAll(".site-menu .product-card").length===1')
  assert.equal(await client.evaluate('menuCalls.at(-1).category'),'grills')
  await client.input('#product-search','لا يوجد')
  await client.wait('document.querySelector(".menu-empty")')
  await client.click('.menu-empty button','عرض كل')
  await client.wait('document.querySelectorAll(".site-menu .product-card").length===2')
  report.checks.push('Server price preserved, unavailable button disabled, search/category params, reset and empty recovery')
  for(const width of [390,768,1440]) {
    await client.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390})
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true)
    const image=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})
    await writeFile(output+'/menu-'+width+'.png',Buffer.from(image.data,'base64'))
    report.responsive.push(width)
  }
  await client.evaluate('window.menuFailure=true')
  await client.input('#product-search','خطأ')
  await client.wait('document.querySelector(".site-menu [role=alert]")')
  await client.evaluate('window.menuFailure=false')
  await client.click('.site-menu [role=alert] button','إعادة المحاولة')
  await client.wait('document.querySelector(".menu-empty")')
  report.checks.push('API error retry recovers without fake products; read-only transport fixtures')
  report.diagnostics=client.diagnostics;assert.deepEqual(report.diagnostics,[])
  await writeFile(output+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report))
} finally {client.close()}
