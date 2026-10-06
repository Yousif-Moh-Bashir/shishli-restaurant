import { http, unwrap } from './http'
import { publicRequest } from './catalogTransport'
export const operationsApi = {
  status: (branch,signal) => http.get('/branches/'+encodeURIComponent(branch)+'/status',{...publicRequest,signal}).then(unwrap),
}
