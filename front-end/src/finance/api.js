import { http, unwrap } from '../api/http'

const base = '/finance'
export const financeApi = {
  login: data => http.post(`${base}/login`, data, { skipAuth: true, skipSessionExpiry: true }).then(unwrap),
  logout: () => http.post(`${base}/logout`).then(unwrap),
  me: () => http.get(`${base}/me`).then(unwrap),
  list: (kind, params) => http.get(`${base}/${kind}`, { params }).then(r => r.data),
  saveRevenue: (data, uuid, correction = false) => correction
    ? http.post(`${base}/revenues/${uuid}/correct`, data).then(unwrap)
    : uuid ? http.put(`${base}/revenues/${uuid}`, data).then(unwrap) : http.post(`${base}/revenues`, data).then(unwrap),
  approve: (uuid, version) => http.post(`${base}/revenues/${uuid}/approve`, { version }).then(unwrap),
  saveOutflow: (kind, data, uuid) => http.post(`${base}/${kind}${uuid ? `/${uuid}` : ''}`, data).then(unwrap),
  settings: kind => http.get(`${base}/settings/${kind}`).then(unwrap),
  saveSetting: (kind, data, uuid) => (uuid ? http.put(`${base}/settings/${kind}/${uuid}`, data) : http.post(`${base}/settings/${kind}`, data)).then(unwrap),
  report: params => http.get(`${base}/reports`, { params }).then(unwrap),
  users: () => http.get(`${base}/users`).then(unwrap),
  saveUser: (data, uuid) => (uuid ? http.put(`${base}/users/${uuid}`, data) : http.post(`${base}/users`, data)).then(unwrap),
  download: async (path, filename, params) => {
    const response = await http.get(`${base}/${path}`, { params, responseType: 'blob', timeout: 120000 })
    const format = path === 'reports/export/pdf' ? 'pdf' : path === 'reports/export/xlsx' ? 'xlsx' : null
    if (format) {
      const expectedType = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      const signature = await response.data.slice(0, 5).text()
      if (response.data.type.split(';')[0] !== expectedType || !(format === 'pdf' ? signature === '%PDF-' : signature.startsWith('PK'))) {
        throw Object.assign(new Error('EXPORT_FORMAT_MISMATCH'), { code: 'EXPORT_FORMAT_MISMATCH' })
      }
    }
    const url = URL.createObjectURL(response.data)
    const a = document.createElement('a')
    try {
      a.href = url; a.download = filename; a.hidden = true
      document.body.appendChild(a); a.click()
    } finally {
      a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    }
  },
}
