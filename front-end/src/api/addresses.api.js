import { http, unwrap } from './http'
import { page } from './catalogTransport'
const path = id => '/addresses/' + encodeURIComponent(id)
export const addressesApi = {
  list: (params, signal) => http.get('/addresses', { params: { page: params?.page || 1 }, signal }).then(page),
  show: id => http.get(path(id)).then(unwrap),
  create: body => http.post('/addresses', body).then(unwrap),
  update: (id, body) => http.patch(path(id), body).then(unwrap),
  remove: id => http.delete(path(id)).then(unwrap),
  setDefault: id => http.patch(path(id) + '/default', {}).then(unwrap),
}
