import { http,unwrap } from './http'
import { cleanParams,page } from './catalogTransport'
const paymentPath=id=>'/admin/payments/'+encodeURIComponent(id)
export const paymentsApi={
 list:(params,signal)=>http.get('/admin/payments',{params:cleanParams(params),signal}).then(page),
 show:(id,signal)=>http.get(paymentPath(id),{signal}).then(unwrap),
 transactions:(id,params,signal)=>http.get(paymentPath(id)+'/transactions',{params:cleanParams(params),signal}).then(page),
 collect:(orderId)=>http.post('/admin/orders/'+encodeURIComponent(orderId)+'/payments/cash/collect',{}).then(unwrap),
 owned:(orderId,params,signal)=>http.get('/orders/'+encodeURIComponent(orderId)+'/payments',{params:cleanParams(params),signal}).then(page),
}
