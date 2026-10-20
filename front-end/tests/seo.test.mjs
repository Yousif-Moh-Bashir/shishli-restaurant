import assert from 'node:assert/strict'
import test from 'node:test'
import {canonicalUrl, routeSeo, restaurantSchema} from '../src/utils/seo.js'

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
