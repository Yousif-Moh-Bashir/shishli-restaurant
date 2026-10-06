const tokenKey = 'shishli_auth_token'
export function readAuthToken() { try { return localStorage.getItem(tokenKey) || null } catch { return null } }
export function writeAuthToken(token) {
  try { if (token) localStorage.setItem(tokenKey, token); else localStorage.removeItem(tokenKey); return true } catch { return false }
}
