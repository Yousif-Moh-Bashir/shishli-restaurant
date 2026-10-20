import assert from 'node:assert/strict'
import test from 'node:test'
import {readFile} from 'node:fs/promises'
import {canonicalUrl, routeSeo, restaurantSchema, homeSeo, cmsPageSeo} from '../src/utils/seo.js'

test('initial HTML matches the exact homepage SEO, Open Graph and Twitter values', async () => {
  assert.equal(homeSeo.title, 'مطعم شيشلي فرش | مشويات ودجاج تركي في تبوك')
  assert.equal(homeSeo.description, 'اكتشف منيو مطعم شيشلي فرش في تبوك، واستمتع بالمشويات والدجاج التركي والكباب والشيش طاووق. تصفح الأصناف والعروض واطلب عبر موقعنا الرسمي.')
  const html = await readFile(new URL('../index.html', import.meta.url), 'utf8')
  assert.equal(html.match(/<title>(.*?)<\/title>/)[1], homeSeo.title)
  for (const [attribute, key, expected] of [
    ['name','description',homeSeo.description], ['property','og:title',homeSeo.title],
    ['property','og:description',homeSeo.description], ['name','twitter:title',homeSeo.title],
    ['name','twitter:description',homeSeo.description], ['property','og:site_name','شيشلي فرش'],
  ]) assert.equal(html.match(new RegExp(`<meta ${attribute}="${key}" content="([^"]*)"`))[1], expected)
})
test('homepage CMS and router titles cannot replace the required SEO; other CMS pages remain unique', () => {
  const page={title:'الرئيسية',seo:{title:'الرئيسية | مطعم شيشلي للدجاج التركي',description:'وصف قديم',og_image_url:'/storage/home.webp'}}
  const home=cmsPageSeo({name:'home',path:'/',meta:{title:'عنوان قديم'}},page)
  assert.equal(home.title,homeSeo.title); assert.equal(home.description,homeSeo.description)
  assert.equal(home.image,page.seo.og_image_url)
  const about=cmsPageSeo({name:'about',path:'/about'}, {...page,seo:{title:'قصة المطعم',description:'وصف قصتنا'}})
  assert.equal(about.title,'قصة المطعم'); assert.equal(about.description,'وصف قصتنا')
})
test('structured data retains settings identity and each actual branch city instead of SEO targeting', () => {
  const schema=restaurantSchema({restaurant:{name:'الاسم المسجل في الإعدادات',city:'تبوك',country:'SA'}},[
    {id:'a',name:'الفرع الأول',is_active:true,location:{city:'تبوك',address:'عنوان الفرع الأول'}},
    {id:'b',name:'الفرع الثاني',is_active:true,location:{city:'مدينة أخرى',address:'عنوان الفرع الثاني'}},
  ])
  assert.equal(schema['@graph'][0].name,'الاسم المسجل في الإعدادات')
  assert.equal(schema['@graph'][0].address.addressLocality,'تبوك')
  assert.equal(schema['@graph'][2].address.addressLocality,'مدينة أخرى')
  assert.equal(schema['@graph'][2].address.streetAddress,'عنوان الفرع الثاني')
})

test('canonical URLs omit queries and fragments', () => {
  assert.equal(canonicalUrl('/product/uuid?cartItem=private#options'), 'https://shishlifresh.com/product/uuid')
  assert.equal(canonicalUrl('/'), 'https://shishlifresh.com/')
  assert.equal(canonicalUrl('//menu/'), 'https://shishlifresh.com/menu')
})
test('public routes have distinct Arabic SEO and private routes are noindex', () => {
  const titles = new Set()
  for (const name of ['home', 'menu', 'branches', 'offers', 'about', 'contact', 'gallery', 'product']) {
    const seo = routeSeo({name, path: '/' + name})
    assert.equal(seo.index, true); assert.match(seo.description, /[\u0600-\u06ff]/); titles.add(seo.title)
  }
  assert.equal(titles.size, 8)
  assert.equal(routeSeo({name:'login',path:'/login'}).title, 'تسجيل الدخول | مطعم شيشلي')
  for (const name of ['login', 'register', 'cart', 'checkout', 'driver.home', 'admin.home', 'not-found', 'account.profile', 'track-order']) assert.equal(routeSeo({name, path:'/private'}).index, false)
})
test('schema uses actual settings and active branches without invented information', () => {
  assert.equal(restaurantSchema(null), null)
  const schema = restaurantSchema({restaurant: {name:'اسم المطعم الفعلي', phone:'123', email:'info@example.com', country:'SA'}}, [
    {id:'a', name:'الفرع الفعلي', is_active:true, contact:{phone:'456'}, location:{city:'الرياض',address:'عنوان فعلي',latitude:'24.7',longitude:'46.6'}},
    {id:'hidden', name:'مغلق', is_active:false},
  ])
  assert.equal(schema['@graph'].length, 2)
  assert.equal(schema['@graph'][0].name, 'اسم المطعم الفعلي')
  assert.equal(schema['@graph'][1].telephone, '456')
  assert.equal(schema['@graph'][1].geo.latitude, 24.7)
  assert.equal(schema['@graph'][1].address.addressCountry, 'SA')
  for (const property of ['aggregateRating','review','openingHours','priceRange']) assert.equal(JSON.stringify(schema).includes(property), false)
})
test('missing contact, coordinates and addresses are omitted', () => {
  const graph = restaurantSchema({restaurant:{name:'شيشلي'}}, [{id:'b',name:'فرع',is_active:true,location:{latitude:'invalid',longitude:0}}])['@graph']
  for (const node of graph) { assert.equal(node.telephone, undefined); assert.equal(node.address, undefined); assert.equal(node.geo, undefined) }
})
