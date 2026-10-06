import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { normalizeApiError } from '../utils/apiError'
export function useSessionLogout(notify) {
  const auth = useAuthStore(), router = useRouter(), logoutError = ref('')
  async function logout() {
    logoutError.value = ''
    try { if (await auth.logout()) await router.replace('/') }
    catch (cause) { logoutError.value = normalizeApiError(cause).message; notify?.(logoutError.value) }
  }
  return { logout, logoutError }
}
