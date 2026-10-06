import { ref, onBeforeUnmount } from 'vue'
import { normalizeApiError } from '../utils/apiError'
// Shared state for real requests. Clearing old data prevents stale branch/filter results.
export function useApiState(initial = null) {
  const data = ref(initial), loading = ref(false), error = ref(null)
  let revision = 0, controller
  async function run(request) {
    controller?.abort(); controller = new AbortController()
    const signal = controller.signal, current = ++revision
    loading.value = true; error.value = null; data.value = initial
    try { const result = await request(signal); if (current !== revision) return null; data.value = result; return result }
    catch (cause) { if (current === revision && cause.code !== 'ERR_CANCELED') error.value = normalizeApiError(cause); return null }
    finally { if (current === revision) loading.value = false }
  }
  function clear() { ++revision; controller?.abort(); loading.value = false; error.value = null; data.value = initial }
  onBeforeUnmount(clear)
  return { data, loading, error, run, clear }
}
export function useApiMutation() {
  const busy = ref(false), error = ref(null)
  async function run(request) {
    if (busy.value) return { ok: false }
    busy.value = true; error.value = null
    try { return { ok: true, data: await request() } }
    catch (cause) { error.value = normalizeApiError(cause); return { ok: false } }
    finally { busy.value = false }
  }
  return { busy, error, run }
}
