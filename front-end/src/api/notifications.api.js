import {http,unwrap} from './http'
import {cleanParams,page} from './catalogTransport'
const path=id=>'/admin/notifications/'+encodeURIComponent(id)
export const notificationsApi={
 list:(params,signal)=>http.get('/admin/notifications',{params:cleanParams(params),signal}).then(page),
 show:(id,signal)=>http.get(path(id),{signal}).then(unwrap),
 attempts:(id,params,signal)=>http.get(path(id)+'/attempts',{params:cleanParams(params),signal}).then(page),
 retry:id=>http.post(path(id)+'/retry',{}).then(unwrap),
}
