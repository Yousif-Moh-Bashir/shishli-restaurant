import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '../api/auth.api'
import { driversApi } from '../api/drivers.api'
import { normalizeApiError } from '../utils/apiError'
import { readAuthSession, writeAuthToken } from '../utils/authToken'
import { clearStaffPushSession } from '../utils/staffPushSession'

export const useAuthStore = defineStore('auth', () => {
  const stored = readAuthSession()
  const user = ref(null), token = ref(stored.token), expiresAt = ref(stored.expiresAt), adminProfile = ref(null)
  const isLoading = ref(false), isInitialized = ref(false), error = ref(null), persistenceWarning = ref('')
  const driverProfile = ref(null)
  const isDriver = computed(() => isAuthenticated.value && driverProfile.value !== null)
  const isAuthenticated = computed(() => Boolean(token.value && user.value))
  // /admin/me is authoritative. General UserResource has no permissions.
  const hasAdminAccess = computed(() => isAuthenticated.value && adminProfile.value?.is_active === true)
  const roles = computed(() => adminProfile.value?.roles ?? [])
  const permissions = computed(() => adminProfile.value?.permissions ?? [])
  const hasRole = role => roles.value.includes(role)
  const hasAnyRole = names => names.some(hasRole)
  const can = permission => hasAdminAccess.value && permissions.value.includes(permission)
  let bootstrapPromise, revision = 0
  function clearSession(expectedToken) {
    if (expectedToken && expectedToken !== token.value) return false
    clearStaffPushSession()
    revision++; user.value = adminProfile.value = driverProfile.value = token.value = null
    expiresAt.value = null
    writeAuthToken(null); persistenceWarning.value = ''; isInitialized.value = true
    return true
  }
  async function fetchMe() {
    const currentToken = token.value, currentRevision = revision
    const profile = await authApi.me()
    if (revision !== currentRevision || token.value !== currentToken) return
    if (!profile || typeof profile.uuid !== 'string') throw new Error('INVALID_USER_RESPONSE')
    user.value = profile
    try {
      const admin = await authApi.adminMe()
      if (revision === currentRevision && token.value === currentToken) {
        if (admin?.id !== profile.uuid || !Array.isArray(admin.roles) || !Array.isArray(admin.permissions)) throw new Error('INVALID_ADMIN_RESPONSE')
        adminProfile.value = admin
      }
    } catch (cause) {
      if (revision !== currentRevision || token.value !== currentToken) return
      adminProfile.value = null
      if (cause.response?.status !== 403) throw cause
      // A customer's admin denial never expires their valid customer session.
    }
    try {
      const driver = profile.is_driver ? await driversApi.me() : null
      if (revision === currentRevision && token.value === currentToken) driverProfile.value = driver
    } catch (cause) {
      if (revision !== currentRevision || token.value !== currentToken) return
      driverProfile.value = null
      if (cause.response?.status !== 403) throw cause
    }
    if (revision !== currentRevision || token.value !== currentToken) return
    const sessionOnly = adminProfile.value?.is_active === true
    if (sessionOnly || !expiresAt.value && !driverProfile.value) {
      const limit = Date.now() + (sessionOnly ? 8 : 7 * 24) * 60 * 60 * 1000
      expiresAt.value = new Date(expiresAt.value ? Math.min(Date.parse(expiresAt.value), limit) : limit).toISOString()
    }
    persistenceWarning.value = writeAuthToken(currentToken, { sessionOnly, expiresAt: expiresAt.value }) ? '' : 'التخزين المحلي غير متاح؛ ستنتهي الجلسة عند إعادة تحميل الصفحة.'
    return user.value
  }
  function initialize() {
    if (bootstrapPromise) return bootstrapPromise
    if (isInitialized.value) return Promise.resolve()
    bootstrapPromise = (async () => {
      isLoading.value = true; error.value = null
      const expectedToken = token.value
      try { if (expectedToken) await fetchMe() }
      catch (cause) {
        error.value = normalizeApiError(cause)
        if (cause.response?.status === 401) clearSession(expectedToken)
        else { user.value = null; adminProfile.value = null; driverProfile.value = null }
      } finally { isLoading.value = false; isInitialized.value = true; bootstrapPromise = null }
    })()
    return bootstrapPromise
  }
  async function login(payload) {
    if (isLoading.value) return false
    isLoading.value = true; error.value = null
    let issuedToken = null
    try {
      const result = await authApi.login(payload)
      if (result?.token_type !== 'Bearer' || typeof result.token !== 'string' || !result.token) throw new Error('INVALID_AUTH_RESPONSE')
      revision++; token.value = result.token; user.value = null; adminProfile.value = null; driverProfile.value = null
      issuedToken = result.token
      expiresAt.value = result.expires_at || null
      await fetchMe(); isInitialized.value = true
      return true
    } catch (cause) {
      error.value = normalizeApiError(cause)
      if (issuedToken) clearSession(issuedToken)
      throw cause
    } finally { isLoading.value = false }
  }
  async function register(payload) {
    if (isLoading.value) return false
    isLoading.value = true; error.value = null
    try { await authApi.register(payload); return true }
    catch (cause) { error.value = normalizeApiError(cause); throw cause }
    finally { isLoading.value = false }
  }
  async function logout() {
    if (isLoading.value) return false
    isLoading.value = true; error.value = null
    const expectedToken = token.value
    try { if (expectedToken) await authApi.logout(); clearSession(expectedToken); return true }
    catch (cause) {
      error.value = normalizeApiError(cause)
      if (cause.response?.status === 401) { clearSession(expectedToken); return true }
      throw cause // Do not claim token revocation after a network/server failure.
    } finally { isLoading.value = false }
  }
  return { user, token, expiresAt, adminProfile, driverProfile, isDriver, isLoading, isInitialized, error, persistenceWarning, isAuthenticated, hasAdminAccess, roles, permissions, hasRole, hasAnyRole, can, initialize, fetchMe, login, register, logout, clearSession }
})
