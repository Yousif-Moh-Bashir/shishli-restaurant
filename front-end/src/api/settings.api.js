import { http, unwrap } from './http'
import { page, cleanParams, publicRequest } from './catalogTransport'
export const settingsApi = {
  list: signal => http.get('/admin/settings', {signal}).then(unwrap),
  groups: signal => http.get('/admin/settings/groups', {signal}).then(unwrap),
  update: settings => http.patch('/admin/settings', {settings}).then(unwrap),
  audit: (params,signal) => http.get('/admin/settings/audit',{params:cleanParams(params),signal}).then(page),
  public: signal => http.get('/settings/public',{...publicRequest,signal}).then(unwrap),
}
