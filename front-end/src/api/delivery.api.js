import { http, unwrap } from './http'
import { page } from './catalogTransport'
const path = branch => '/admin/branches/' + encodeURIComponent(branch) + '/delivery-zones'
export const deliveryZonesApi = {
  list: (branch, currentPage, signal) => http.get(path(branch), { params: { page: currentPage }, signal }).then(page),
  show: (branch, id) => http.get(path(branch) + '/' + encodeURIComponent(id)).then(unwrap),
  create: (branch, body) => http.post(path(branch), body).then(unwrap),
  update: (branch, id, body) => http.patch(path(branch) + '/' + encodeURIComponent(id), body).then(unwrap),
  remove: (branch, id) => http.delete(path(branch) + '/' + encodeURIComponent(id)).then(unwrap),
}
export const deliveryApi = {
  quote: (body, { token, uuid } = {}, signal) => http.post('/cart/delivery/quote', body, { signal, headers: token ? { 'X-Cart-Token': token } : uuid ? { 'X-Cart-UUID': uuid } : {} }).then(unwrap),
}
