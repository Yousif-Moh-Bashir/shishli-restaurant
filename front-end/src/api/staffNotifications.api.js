import { http, unwrap } from './http'

export const staffNotificationsApi = {
  feed: signal => http.get('/admin/staff-notifications', { signal }).then(unwrap),
  diagnostic: () => http.get('/admin/push-diagnostics').then(unwrap),
  subscribeDiagnostic: body => http.post('/admin/push-diagnostics/subscriptions', body).then(unwrap),
  unsubscribeDiagnostic: id => http.delete('/admin/push-diagnostics/subscriptions/' + encodeURIComponent(id)).then(unwrap),
  subscribe: body => http.post('/admin/staff-notifications/subscriptions', body).then(unwrap),
  unsubscribe: id => http.delete('/admin/staff-notifications/subscriptions/' + encodeURIComponent(id)).then(unwrap),
  unsubscribeEndpoint: endpoint => http.delete('/admin/staff-notifications/subscriptions', { data: { endpoint } }).then(unwrap),
}
