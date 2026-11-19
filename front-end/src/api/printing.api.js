import { http, unwrap } from './http'

const jobsPath = orderId => '/admin/orders/' + encodeURIComponent(orderId) + '/print-jobs'
export const printingApi = {
  list: (orderId, signal) => http.get(jobsPath(orderId), { signal }).then(unwrap),
  reprint: (orderId, jobId, body) => http.post(jobsPath(orderId) + '/' + encodeURIComponent(jobId) + '/reprint', body).then(unwrap),
}
