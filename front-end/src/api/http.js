import axios from 'axios'
import { phoneRequest } from '../utils/phone'
export const apiBaseURL = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/+$/, '')
export const http = axios.create({ baseURL: apiBaseURL, timeout: 15000, headers: { Accept: 'application/json' } })
let credentials = () => null, unauthorized = () => {}
// Inject after Pinia creation. Capture the request token: stale 401 responses
// must never expire a newer session. No store/router dependency cycle.
export function configureHttpAuth(getToken, onUnauthorized) { credentials = getToken; unauthorized = onUnauthorized }
http.interceptors.request.use(config => {
  config.data = phoneRequest(config.data)
  config.params = phoneRequest(config.params)
  const token = config.skipAuth ? null : credentials()
  config.sessionToken = token
  if (token) config.headers.set('Authorization', `Bearer ${token}`)
  return config
})
http.interceptors.response.use(response => response, error => {
  if (error.response?.status === 401 && error.config?.sessionToken && !error.config.skipSessionExpiry) unauthorized(error.config.sessionToken)
  return Promise.reject(error)
})
export function unwrap(response) {
  if (response.data?.success !== true || !Object.hasOwn(response.data, 'data')) throw new Error('INVALID_API_RESPONSE')
  return response.data.data
}
