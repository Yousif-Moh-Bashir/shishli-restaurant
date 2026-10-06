import { settingsApi } from './settings.api'
import { http, unwrap } from './http'
export const checkoutApi = {
  submit: (body, { token, uuid } = {}) => http.post('/checkout', body, {
    headers: token ? { 'X-Cart-Token': token } : { 'X-Cart-UUID': uuid },
  }).then(unwrap),
  settings: signal => settingsApi.public(signal),
}
