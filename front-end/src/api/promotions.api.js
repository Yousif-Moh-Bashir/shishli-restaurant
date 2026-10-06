import {http,unwrap} from './http'
import {crud,cleanParams,publicRequest,page} from './catalogTransport'
export const promotionsApi=crud('/admin/promotions')
export const publicPromotionsApi={list:(params,signal)=>http.get('/promotions',{...publicRequest,params:cleanParams(params),signal}).then(page)}
export const couponsApi=id=>crud(`/admin/promotions/${id}/coupons`,['list','create','update','remove'])
