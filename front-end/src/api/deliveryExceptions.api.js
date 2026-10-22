import { http, unwrap } from './http'
import { page, cleanParams } from './catalogTransport'
export const deliveryExceptionsApi = {
  list: (params, signal) => http.get('/admin/delivery-exceptions', { params: cleanParams(params), signal }).then(page),
  manage: (id, body) => http.post('/admin/orders/' + encodeURIComponent(id) + '/delivery-exception', body).then(unwrap),
  intervention: (id, body) => http.post('/driver/orders/' + encodeURIComponent(id) + '/intervention', body).then(unwrap),
}
