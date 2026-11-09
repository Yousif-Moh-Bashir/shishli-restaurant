import { http, unwrap } from './http'

export const staffWebPushPilotApi = {
  status: () => http.get('/admin/web-push-pilot').then(unwrap),
  subscribe: body => http.post('/admin/web-push-pilot/subscriptions', body).then(unwrap),
  unsubscribe: id => http.delete('/admin/web-push-pilot/subscriptions/' + encodeURIComponent(id)).then(unwrap),
}
