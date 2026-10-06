import { http, unwrap } from './http'
import { page, publicRequest } from './catalogTransport'
const branchPath = branch => '/admin/branches/'+encodeURIComponent(branch)
export const businessHoursApi = {
  list: (branch,signal) => http.get(branchPath(branch)+'/business-hours',{signal}).then(unwrap),
  replace: (branch,hours) => http.put(branchPath(branch)+'/business-hours',{hours}).then(unwrap),
  public: (branch,signal) => http.get('/branches/'+encodeURIComponent(branch)+'/business-hours',{...publicRequest,signal}).then(unwrap),
}
export const closuresApi = {
  list: (branch,currentPage,signal) => http.get(branchPath(branch)+'/closures',{params:{page:currentPage},signal}).then(page),
  create: (branch,body) => http.post(branchPath(branch)+'/closures',body).then(unwrap),
  update: (branch,id,body) => http.patch(branchPath(branch)+'/closures/'+encodeURIComponent(id),body).then(unwrap),
  remove: (branch,id) => http.delete(branchPath(branch)+'/closures/'+encodeURIComponent(id)).then(unwrap),
}
