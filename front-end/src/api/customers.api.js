import {http,unwrap} from './http'
import {crud,cleanParams,page} from './catalogTransport'
export const customersApi={...crud('/admin/customers',['list','show']),orders:(id,params,signal)=>http.get(`/admin/customers/${id}/orders`,{params:cleanParams(params),signal}).then(page),addresses:(id,params,signal)=>http.get(`/admin/customers/${id}/addresses`,{params,signal}).then(page),active:(id,active)=>http.post(`/admin/customers/${id}/${active?'activate':'deactivate'}`).then(unwrap)}
export const guestsApi={list:(params,signal)=>http.get('/admin/guest-customers',{params:cleanParams(params),signal}).then(page),orders:(params,signal)=>http.get('/admin/guest-customers/orders',{params:cleanParams(params),signal}).then(page)}
export const profileApi={show:signal=>http.get('/profile',{signal}).then(unwrap),update:payload=>http.patch('/profile',payload).then(unwrap),claim:()=>http.post('/profile/claim-orders',{}).then(unwrap)}
