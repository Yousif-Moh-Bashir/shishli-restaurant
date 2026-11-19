import { http, unwrap } from './http'
import { page, cleanParams } from './catalogTransport'

const path = '/customer/notifications'
export const customerNotificationsApi = {
  show: (id, signal) => http.get(path+'/'+encodeURIComponent(id), { signal }).then(unwrap),
  list: (params, signal) => http.get(path, { params:cleanParams(params), signal }).then(page),
  unreadCount: signal => http.get(path+'/unread-count', { signal }).then(unwrap).then(value => {
    if (!Number.isInteger(value?.unread_count) || value.unread_count<0) throw Error('INVALID_NOTIFICATION_COUNT')
    return value
  }),
  read: id => http.patch(path+'/'+encodeURIComponent(id)+'/read', {}).then(unwrap).then(value => {
    if (value?.id!==id || !value.order?.id || !value.read_at) throw Error('INVALID_NOTIFICATION_RESPONSE')
    return value
  }),
}
