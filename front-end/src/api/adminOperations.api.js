import {http,unwrap} from './http'
import {crud,cleanParams,page} from './catalogTransport'
export const staffApi={...crud('/admin/staff',['list','show','create','update']),activate:id=>http.post(`/admin/staff/${id}/activate`).then(unwrap),deactivate:id=>http.post(`/admin/staff/${id}/deactivate`).then(unwrap)}
export const rolesApi={list:signal=>http.get('/admin/roles',{signal}).then(unwrap),permissions:signal=>http.get('/admin/permissions',{signal}).then(unwrap),update:(role,permissions)=>http.put(`/admin/roles/${encodeURIComponent(role)}/permissions`,{permissions}).then(unwrap)}
export const activityApi={list:(params,signal)=>http.get('/admin/activity-logs',{params:cleanParams(params),signal}).then(page)}
