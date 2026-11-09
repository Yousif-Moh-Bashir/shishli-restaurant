const tokenKey = 'shishli_auth_token'
const expiryKey = 'shishli_auth_expires_at'
export function readAuthSession() {
  for (const name of ['sessionStorage', 'localStorage']) {
    try {
      const storage = globalThis[name], token = storage.getItem(tokenKey)
      if (!token) continue
      const expiresAt = storage.getItem(expiryKey)
      if (expiresAt && (!Number.isFinite(Date.parse(expiresAt)) || Date.parse(expiresAt) <= Date.now())) {
        storage.removeItem(tokenKey); storage.removeItem(expiryKey)
        return { token: null, expiresAt: null }
      }
      return { token, expiresAt }
    } catch { /* Try the other storage when storage is unavailable. */ }
  }
  return { token: null, expiresAt: null }
}
export function readAuthToken() { return readAuthSession().token }
export function writeAuthToken(token, { sessionOnly = false, expiresAt = null } = {}) {
  let cleared = true
  for (const name of ['sessionStorage', 'localStorage']) {
    try { globalThis[name].removeItem(tokenKey); globalThis[name].removeItem(expiryKey) } catch { cleared = false }
  }
  if (!token) return cleared
  try {
    const storage = globalThis[sessionOnly ? 'sessionStorage' : 'localStorage']
    storage.setItem(tokenKey, token)
    if (expiresAt) storage.setItem(expiryKey, expiresAt)
    return true
  } catch { return false }
}
