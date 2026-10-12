import { http, unwrap } from './http'
const path = id => '/addresses/' + encodeURIComponent(id)
export const addressesApi = {
  list: signal => http.get('/addresses', { signal }).then(unwrap),
  show: id => http.get(path(id)).then(unwrap),
  create: body => http.post('/addresses', body).then(unwrap),
  update: (id, body) => http.patch(path(id), body).then(unwrap),
  remove: id => http.delete(path(id)).then(unwrap),
  setDefault: id => http.patch(path(id) + '/default', {}).then(unwrap),
}
