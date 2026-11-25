import { http, unwrap } from './http'
import { publicRequest } from './catalogTransport'

export const catalogPage = (path, signal) =>
  http.get('/catalog-page' + path, { ...publicRequest, signal }).then(unwrap)
