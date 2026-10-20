// Chrome integration with API fixtures, isolated from restaurant data.
import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {browserClient} from './browser-client.mjs'
import {homeSeo} from '../src/utils/seo.js'
const browser = await browserClient(9395, resolve('.performance-check/seo'))
const origin = process.env.FRONTEND_ORIGIN || 'http://127.0.0.1:5176'
const uuid = '11111111-1111-4111-8111-111111111111'
const success = data => ({success:true,data,message:'Success',errors:null})
const product = {id:uuid,name:'شيش طاووق',short_description:'قطع الدجاج المشوية من قائمة المطعم.',description:'تفاصيل الطبق من المصدر.',primary_image:{url:'/storage/product.webp'},images:[],option_groups:[]}
const paths=[]
let homeStatus=200
browser.onEvent('Fetch.requestPaused', async ({requestId,request}) => {
  const path=new URL(request.url).pathname;paths.push(path)
  let status=200,data=[]
  if(path.endsWith('/settings/public'))data={restaurant:{name:'اسم المطعم من الإعدادات',phone:'123',currency:'SAR',timezone:'Asia/Riyadh'},orders:{enabled:true}}
  else if(path.endsWith('/branches'))data=[] // No selected branch or saved preference.
  else if(path.endsWith('/products/'+uuid))data=product
  else if(path.includes('/products/'))status=404
  else if(path.endsWith('/pages/about'))data={title:'عن المطعم',seo:{title:'قصتنا من إدارة المحتوى',description:'وصف عربي محفوظ في إدارة المحتوى.',og_image_url:'/storage/about.webp',canonical_url:'https://wrong.example/about'},sections:[]}
  else if(path.endsWith('/pages/contact'))status=404
  else if(path.endsWith('/home')){status=homeStatus;data={page:{title:'الرئيسية',seo:{title:'الرئيسية | مطعم شيشلي للدجاج التركي',description:'وصف قديم من CMS',og_image_url:'/storage/home.webp'},sections:[{id:'hero',key:'hero',title:'محتوى الرئيسية من CMS',data:{}}]},banners:[],featured_products:[],active_offers:[],gallery_preview:[]}}
  else if(path.endsWith('/cart'))data={id:uuid,token:'seo-fixture',status:'active',items:[],summary:{quantity_total:0,items_count:0,total:'0.00'}}
  else if(path.endsWith('/promotions'))data=[]
  const body=status===404?{success:false,message:'Not found'}:success(data)
  await browser.send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'*'},{name:'Access-Control-Allow-Headers',value:'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID'},{name:'Access-Control-Allow-Methods',value:'GET,POST,OPTIONS'}],body:Buffer.from(JSON.stringify(body)).toString('base64')})
})
const head=()=>browser.evaluate(`({title:document.title,description:document.querySelector('meta[name="description"]')?.content,canonical:document.querySelector('link[rel="canonical"]')?.href,og:document.querySelector('meta[property="og:url"]')?.content,image:document.querySelector('meta[property="og:image"]')?.content,robots:document.querySelector('meta[name="robots"]')?.content,schema:document.querySelector('#restaurant-jsonld')?.textContent,canonicalCount:document.querySelectorAll('link[rel="canonical"]').length})`)
const navigate=path=>browser.evaluate(`document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(${JSON.stringify(path)})`)
async function assertHomeSeo(){
  const result=await head()
  assert.equal(result.title,homeSeo.title);assert.equal(result.description,homeSeo.description)
  for(const [attribute,key,value] of [['property','og:title',homeSeo.title],['property','og:description',homeSeo.description],['name','twitter:title',homeSeo.title],['name','twitter:description',homeSeo.description]]){
    assert.equal(await browser.evaluate(`document.querySelector('meta[${attribute}="${key}"]')?.content`),value)
    assert.equal(await browser.evaluate(`document.querySelectorAll('meta[${attribute}="${key}"]').length`),1)
  }
}
try {
  await browser.send('Fetch.enable',{patterns:[{urlPattern:'*://*/api/v1/*'},{urlPattern:'*://*/api/user'}]})
  await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:'localStorage.clear();sessionStorage.clear()'})
  await browser.send('Emulation.setScriptExecutionDisabled',{value:true})
  await browser.send('Page.navigate',{url:origin+'/'})
  await browser.wait(`document.querySelector('meta[name="twitter:description"]')`)
  await assertHomeSeo()
  assert.equal(await browser.evaluate('document.querySelector("#app").childElementCount'),0)
  await browser.send('Emulation.setScriptExecutionDisabled',{value:false})
  await browser.go(origin,'/')
  await browser.wait('document.querySelector("#hero-title")?.textContent==="محتوى الرئيسية من CMS"')
  await assertHomeSeo()
  assert.equal((await head()).canonical,'https://shishlifresh.com/')
  await browser.go(origin,'/product/'+uuid+'?cartItem=private')
  await browser.wait('document.title.includes("شيش طاووق")&&document.querySelector("#restaurant-jsonld")')
  let result=await head()
  assert.equal(result.description,product.short_description)
  assert.equal(result.canonical,'https://shishlifresh.com/product/'+uuid)
  assert.equal(result.og,result.canonical)
  assert.equal(result.image,'https://shishlifresh.com/storage/product.webp')
  assert.equal(JSON.parse(result.schema)['@graph'][0].name,'اسم المطعم من الإعدادات')
  assert.ok(paths.includes('/api/v1/products/'+uuid))
  assert.equal(paths.some(path=>path.includes('/menu/products/')),false)
  await navigate('/product/'+uuid+'?cartItem=another')
  assert.equal((await head()).title,'شيش طاووق | مطعم شيشلي')
  await navigate('/about')
  await browser.wait('document.title==="قصتنا من إدارة المحتوى"')
  result=await head()
  assert.equal(result.canonical,'https://shishlifresh.com/about')
  assert.equal(result.description,'وصف عربي محفوظ في إدارة المحتوى.')
  assert.equal(await browser.evaluate(`document.querySelector('meta[name="twitter:title"]').content`),'قصتنا من إدارة المحتوى')
  await navigate('/branches')
  await browser.wait('document.title==="فروعنا | مطعم شيشلي"')
  result=await head()
  assert.equal(result.image,undefined)
  assert.equal(result.canonicalCount,1)
  await navigate('/login')
  await browser.wait('document.querySelector("meta[name=robots]")?.content==="noindex, follow"')
  assert.equal((await head()).schema,undefined)
  await navigate('/contact')
  await browser.wait('document.querySelector("main").textContent.includes("الصفحة غير منشورة")')
  assert.equal((await head()).robots,'noindex, follow')
  await navigate('/product/22222222-2222-4222-8222-222222222222')
  await browser.wait('document.title==="المنتج غير موجود | مطعم شيشلي"')
  assert.equal((await head()).robots,'noindex, follow')
  homeStatus=404
  await browser.go(origin,'/')
  await browser.wait('document.querySelector("link[rel=canonical]")?.href==="https://shishlifresh.com/"')
  assert.equal((await head()).robots,'index, follow')
  await assertHomeSeo()
  assert.deepEqual(browser.diagnostics,[])
  console.log('PASS: product metadata without branch, CMS SEO, canonical normalization, query navigation, stale metadata removal, private/unpublished noindex, actual JSON-LD')
} finally { browser.close() }
