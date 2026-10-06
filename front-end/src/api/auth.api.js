import { apiBaseURL, http, unwrap } from './http'
const publicRequest = { skipAuth: true, skipSessionExpiry: true }
export const authApi = {
  login: payload => http.post('/login', payload, publicRequest).then(unwrap),
  register: payload => http.post('/register', payload, publicRequest).then(unwrap),
  // Actual current-user route is /api/user, outside v1.
  me: () => http.get('/user', { baseURL: apiBaseURL.replace(/\/v1$/, '') }).then(unwrap),
  adminMe: () => http.get('/admin/me').then(unwrap),
  logout: () => http.post('/logout').then(unwrap),
}
