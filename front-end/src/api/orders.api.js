import { http, unwrap } from './http'
import { page, cleanParams } from './catalogTransport'
const path = id => '/orders/' + encodeURIComponent(id)
export const ordersApi = {
  list: (params, signal) => http.get('/orders', { params: cleanParams(params), signal }).then(page),
  show: (id, signal) => http.get(path(id), { signal }).then(unwrap),
  guest: (id, token, signal) => http.get(path(id) + '/guest', {
    signal, headers: { 'X-Order-Token': token }, skipAuth: true, skipSessionExpiry: true,
  }).then(unwrap),
  tracking: (id, signal) => http.get(path(id) + '/tracking', { signal }).then(unwrap),
  trackGuest: (body, signal) => http.post('/orders/track', body, { signal, skipAuth: true, skipSessionExpiry: true }).then(unwrap),
  cancel: (id, body, token) => http.post(path(id) + (token ? '/guest/cancel' : '/cancel'), body, token ? { headers: { 'X-Order-Token': token }, skipAuth: true, skipSessionExpiry: true } : {}).then(unwrap),
}
const operations = { confirm: 'confirm', start_preparing: 'start-preparing', mark_ready: 'mark-ready', dispatch: 'dispatch', complete: 'complete', cancel: 'cancel' }
export const adminOrdersApi = {
  list: (params, signal) => http.get('/admin/orders', { params: cleanParams(params), signal }).then(page),
  counts: (params, signal) => http.get('/admin/orders/counts', { params: cleanParams(params), signal }).then(unwrap),
  show: (id, signal) => http.get('/admin' + path(id), { signal }).then(unwrap),
  history: (id, params, signal) => http.get('/admin' + path(id) + '/history', { params, signal }).then(page),
  kitchen: (params, signal) => http.get('/admin/kitchen/orders', { params: cleanParams(params), signal }).then(page),
  operate: (id, action, body) => {
    if (!Object.hasOwn(operations, action)) throw new Error('INVALID_ORDER_ACTION')
    return http.post('/admin' + path(id) + '/' + operations[action], body).then(unwrap)
  },
}
