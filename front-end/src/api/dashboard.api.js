import { http, unwrap } from './http'
import { reportParams } from './reports.api'
export const dashboardApi = {
  async get(params, signal) {
    const data = unwrap(await http.get('/admin/dashboard', { params: reportParams(params), signal }))
    if (!data?.period || !data.summary || !data.order_statuses || !data.order_types) throw new Error('INVALID_DASHBOARD_RESPONSE')
    return data
  },
  async bootstrap(signal) {
    const data = unwrap(await http.get('/admin/bootstrap', { signal }))
    if (!data?.restaurant?.currency || !data.restaurant.timezone) throw new Error('INVALID_REPORT_CONTEXT')
    return data
  },
}
