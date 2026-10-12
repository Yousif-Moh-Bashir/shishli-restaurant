import { http, unwrap } from './http'
import { page, cleanParams } from './catalogTransport'
const orderPath = id => '/driver/orders/' + encodeURIComponent(id)
const driverPath = id => '/admin/drivers/' + encodeURIComponent(id)
export const driversApi = {
  me: signal => http.get('/driver/me', { signal }).then(unwrap),
  availability: available => http.patch('/driver/availability', { available }).then(unwrap),
  orders: (params, signal) => http.get('/driver/orders', { params: cleanParams(params), signal }).then(page),
  order: (id, signal) => http.get(orderPath(id), { signal }).then(unwrap),
  accept: id => http.post(orderPath(id) + '/accept', {}).then(unwrap),
  transition: (id, status, reason) => http.post(orderPath(id) + '/transition', { status, reason: reason || null }).then(unwrap),
  collect: (id, amount) => http.post(orderPath(id) + '/cash/collect', { amount }).then(unwrap),
  collections: (params, signal) => http.get('/driver/collections', { params: cleanParams(params), signal }).then(page),
}
export const adminDriversApi = {
  list: (params, signal) => http.get('/admin/drivers', { params: cleanParams(params), signal }).then(page),
  show: (id, signal) => http.get(driverPath(id), { signal }).then(unwrap),
  create: body => http.post('/admin/drivers', body).then(unwrap),
  update: (id, body) => http.put(driverPath(id), body).then(unwrap),
  collections: (id, params, signal) => http.get(driverPath(id) + '/collections', { params: cleanParams(params), signal }).then(page),
  settle: (id, body) => http.post(driverPath(id) + '/settlements', body).then(unwrap),
  reassign: (id, body) => http.post('/admin/orders/' + encodeURIComponent(id) + '/reassign-driver', body).then(unwrap),
}
