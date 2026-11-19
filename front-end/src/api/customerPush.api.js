import { http, unwrap } from './http'
export const customerPushApi = {
  settings: signal => http.get('/customer/push', { signal }).then(unwrap),
  subscribe: data => http.post('/customer/push/subscriptions', data).then(unwrap),
  remove: id => http.delete('/customer/push/subscriptions/' + encodeURIComponent(id)).then(unwrap),
}
