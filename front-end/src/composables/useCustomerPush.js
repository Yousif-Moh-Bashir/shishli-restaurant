import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../stores/auth'
import { customerPushApi } from '../api/customerPush.api'
import { activateCustomerWorker, bindCustomerWorker, customerWorkerStatus, customerPushRegistration, customerPushScope, clearCustomerPushSession, readCustomerPushBinding, saveCustomerPushBinding, withCustomerPushLock } from '../utils/customerPushSession'

export function useCustomerPush() {
  const auth = useAuthStore(), settings = ref(null), enabled = ref(false), busy = ref(false), message = ref(''), loading = ref(false)
  const supported = computed(() => window.isSecureContext && 'Notification' in window && 'PushManager' in window && 'serviceWorker' in navigator)
  const permission = ref(supported.value ? Notification.permission : 'unsupported')
  let generation = 0, disposed = false, controller
  const current = value => !disposed && generation === value && auth.isAuthenticated
  async function refresh() {
    const version = generation
    if (!auth.isAuthenticated || busy.value || loading.value) return
    controller?.abort(); controller = new AbortController(); loading.value = true
    try {
      const value = await customerPushApi.settings(controller.signal)
      if (!current(version)) return
      settings.value = value
      permission.value = supported.value ? Notification.permission : 'unsupported'
      const binding = readCustomerPushBinding(), reg = supported.value ? await customerPushRegistration() : null
      const subscription = await reg?.pushManager.getSubscription()
      const worker = reg?.active ? await customerWorkerStatus(reg) : null
      if (!current(version)) return
      enabled.value = Boolean(value.enabled && subscription && permission.value === 'granted' && binding?.user === auth.user.uuid && worker?.id === binding.id && value.devices.some(row => row.id === binding.id))
      if (subscription && binding?.user !== auth.user.uuid) message.value = 'فعّل إشعارات الجهاز لربطها بحسابك الحالي.'
    } catch (error) { if (current(version) && error.code !== 'ERR_CANCELED') message.value = 'تعذر تحديث إعدادات الجهاز. تحقق من الاتصال وأعد المحاولة.' }
    finally { if (current(version)) loading.value = false }
  }
  async function enable() {
    if (busy.value || !supported.value || !settings.value?.enabled) return
    const version = generation, user = auth.user.uuid, previous = readCustomerPushBinding()
    busy.value = true; message.value = ''
    let subscription, record
    try {
      // Keep permission inside the customer's click gesture, before acquiring device locks.
      permission.value = await Notification.requestPermission()
      if (!current(version)) return
      if (permission.value !== 'granted') { message.value = 'لم تسمح بالإشعارات. يمكنك تغيير الإذن من إعدادات المتصفح؛ مركز الإشعارات متاح دائمًا.'; return }
      await withCustomerPushLock(async () => {
        if (!current(version)) return
        try {
          const reg = await activateCustomerWorker(await navigator.serviceWorker.register('/customer-push/sw.js', { scope: customerPushScope }))
          if (!current(version)) return
          subscription = await reg.pushManager.getSubscription()
          if (subscription) await subscription.unsubscribe()
          const bytes = atob(settings.value.public_key.replace(/-/g, '+').replace(/_/g, '/'))
          subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from(bytes, c => c.charCodeAt(0)) })
          if (!current(version)) { await subscription.unsubscribe(); return }
          record = await customerPushApi.subscribe(subscription.toJSON())
          if (!current(version)) { await subscription.unsubscribe(); return }
          await bindCustomerWorker(reg, record.id)
          if (!current(version)) { reg.active.postMessage({ type: 'customer-push-clear', id: record.id }); await subscription.unsubscribe(); return }
          saveCustomerPushBinding({ user, id: record.id })
          if (previous?.user === user && previous.id !== record.id) {
            try { await customerPushApi.remove(previous.id) } catch { /* A revoked or expired device may already be gone. */ }
          }
          enabled.value = true; message.value = 'تم تفعيل إشعارات هذا الجهاز للتحديثات الجديدة فقط.'
        } catch (error) {
          await subscription?.unsubscribe().catch(() => {})
          throw error
        }
      })
    } catch {
      if (current(version)) { enabled.value = false; message.value = 'تعذر ربط الجهاز. تحقق من الاتصال وأعد التفعيل؛ على iPhone أضف الموقع للشاشة الرئيسية.' }
    } finally { if (current(version)) { busy.value = false; void refresh() } }
  }
  async function remove(id) {
    if (busy.value) return
    const version = generation
    busy.value = true; message.value = ''
    try {
      await customerPushApi.remove(id)
      if (!current(version)) return
      if (readCustomerPushBinding()?.id === id) { await clearCustomerPushSession(id); enabled.value = false }
      message.value = 'أُوقف اشتراك الجهاز؛ سجل إشعارات طلباتك محفوظ.'
    } catch { if (current(version)) message.value = 'تعذر إيقاف الاشتراك. أعد المحاولة أو امنع الإشعارات من إعدادات المتصفح.' }
    finally { if (current(version)) { busy.value = false; void refresh() } }
  }
  function renew(event) { if (event.data?.type === 'customer-push-renewal-required') { enabled.value = false; message.value = 'انتهى اشتراك المتصفح. اضغط تفعيل أو تجديد لربطه مجددًا.'; void refresh() } }
  watch([() => auth.token, () => auth.user?.uuid], () => {
    generation++; controller?.abort(); settings.value = null; enabled.value = false; busy.value = loading.value = false; message.value = ''
    if (auth.isAuthenticated) void refresh()
  }, { immediate: true })
  onMounted(() => { window.addEventListener('online', refresh); navigator.serviceWorker?.addEventListener('message', renew) })
  onBeforeUnmount(() => { disposed = true; generation++; controller?.abort(); window.removeEventListener('online', refresh); navigator.serviceWorker?.removeEventListener('message', renew) })
  return { settings, supported, permission, enabled, busy, loading, message, refresh, enable, remove, binding: readCustomerPushBinding }
}
