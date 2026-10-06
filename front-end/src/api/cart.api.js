import { http, unwrap } from './http'
function config({ token, uuid } = {}) {
  return { headers: token ? { 'X-Cart-Token': token } : uuid ? { 'X-Cart-UUID': uuid } : {} }
}
const itemPath = id => '/cart/items/' + encodeURIComponent(id)
export const cartApi = {
  create: branch_uuid => http.post('/cart', { branch_uuid }).then(unwrap),
  show: ctx => http.get('/cart', config(ctx)).then(unwrap),
  branch: (branch_uuid, ctx) => http.patch('/cart', { branch_uuid }, config(ctx)).then(unwrap),
  refresh: ctx => http.post('/cart/refresh', {}, config(ctx)).then(unwrap),
  add: (payload, ctx) => http.post('/cart/items', payload, config(ctx)).then(unwrap),
  quantity: (id, quantity, ctx) => http.patch(itemPath(id), { quantity }, config(ctx)).then(unwrap),
  options: (id, options, ctx) => http.patch(itemPath(id) + '/options', { options }, config(ctx)).then(unwrap),
  remove: (id, ctx) => http.delete(itemPath(id), config(ctx)).then(unwrap),
  clear: ctx => http.delete('/cart/items', config(ctx)).then(unwrap),
  applyCoupon: (code,ctx) => http.post('/cart/coupon',{code},config(ctx)).then(unwrap),
  removeCoupon: ctx => http.delete('/cart/coupon',config(ctx)).then(unwrap),
}
