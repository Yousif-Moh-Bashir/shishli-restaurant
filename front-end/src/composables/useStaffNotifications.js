import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { staffNotificationsApi } from '../api/staffNotifications.api'
import { useOrderPolling } from './useOrderPolling'
import { useNewOrderSound } from './useNewOrderSound'
import { PendingOrderAlerts, claimOrderReminder } from '../utils/pendingOrderAlerts'

export function useStaffNotifications(auth) {
  const allowed = () => auth.isAuthenticated && auth.can('orders.view')
  const settings = ref(null), pushEnabled = ref(false), busy = ref(false), message = ref('')
  const diagnostic = computed(() => settings.value?.push_diagnostic?.enabled === true)
  const pushAvailable = computed(() => diagnostic.value ? settings.value?.push_diagnostic?.ready === true : settings.value?.push_enabled === true)
  let diagnosticSubscription = null
  const pushKey = () => diagnostic.value ? settings.value?.push_diagnostic?.public_key : settings.value?.public_key
  const alerts = new PendingOrderAlerts()
  const sound = useNewOrderSound(() => allowed() && settings.value?.sound_enabled === true)
  const supported = computed(() => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window)
  let registration, timer, lastSync = 0, disposed = false, revision = 0, ticking = false, inspected = false
  const feed = useOrderPolling(signal => staffNotificationsApi.feed(signal), { interval: 10000, enabled: allowed })
  function post(data) { registration?.active?.postMessage(data) }
  async function tick() {
    if (ticking || !allowed() || !settings.value?.sound_enabled || !sound.enabled.value || !sound.ready.value || document.visibilityState !== 'visible' || Date.now() - lastSync > 25000) return
    ticking = true
    const current = revision
    const run = async () => {
      if (disposed || current !== revision || !allowed() || !settings.value?.sound_enabled || !sound.enabled.value) return
      const rows = alerts.due(), interval = Math.max(30, settings.value.repeat_seconds || 60) * 1000
      let ring = false
      for (const row of rows) {
        if (claimOrderReminder(localStorage, 'shishli.staffSound.' + auth.user.uuid + '.' + row.id, Date.now(), interval)) ring = true
      }
      if (ring) sound.play()
    }
    try {
      if (navigator.locks) await navigator.locks.request('shishli-staff-order-sound', run)
      // On browsers without Web Locks, only the focused window produces reminders.
      else if (document.hasFocus()) await run()
    } finally { ticking = false }
  }
  async function worker() {
    registration ||= await navigator.serviceWorker.register('/sw.js', { scope: '/' })
    await navigator.serviceWorker.ready
    return registration
  }
  function applicationKey(value) {
    const bytes = atob(value.replace(/-/g, '+').replace(/_/g, '/'))
    return Uint8Array.from(bytes, character => character.charCodeAt(0))
  }
  async function enablePush() {
    if (busy.value || !supported.value || !pushAvailable.value) return
    busy.value = true; message.value = ''
    const current = revision
    try {
      const permission = await Notification.requestPermission()
      if (permission !== 'granted') throw Error('permission')
      const reg = await worker()
      let subscription = await reg.pushManager.getSubscription()
      // Recreate an endpoint when binding a different login or rotating VAPID keys.
      if (subscription) await subscription.unsubscribe()
      subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationKey(pushKey()) })
      if (current !== revision || disposed) { await subscription.unsubscribe(); return }
      try {
        if (diagnostic.value) diagnosticSubscription = await staffNotificationsApi.subscribeDiagnostic(subscription.toJSON())
        else await staffNotificationsApi.subscribe(subscription.toJSON())
      }
      catch (error) { await subscription.unsubscribe(); throw error }
      if (current === revision && !disposed) pushEnabled.value = true
      window.dispatchEvent(new Event('shishli-push-subscription-changed'))
    } catch { message.value = 'تعذر تفعيل إشعارات الجهاز. تحقق من إذن المتصفح والاتصال؛ على iPhone أضف الموقع إلى الشاشة الرئيسية.' }
    finally { busy.value = false }
  }
  async function disablePush() {
    if (busy.value) return
    busy.value = true; message.value = ''
    try {
      const reg = await worker(), subscription = await reg.pushManager.getSubscription()
      if (subscription) {
        if (diagnostic.value) {
          const record = diagnosticSubscription || (await staffNotificationsApi.diagnostic()).subscription
          if (record) await staffNotificationsApi.unsubscribeDiagnostic(record.id)
          diagnosticSubscription = null
        } else await staffNotificationsApi.unsubscribeEndpoint(subscription.endpoint)
        await subscription.unsubscribe()
      }
      pushEnabled.value = false; post({ type: 'staff-clear-notifications' })
      window.dispatchEvent(new Event('shishli-push-subscription-changed'))
    } catch { message.value = 'تعذر إيقاف الاشتراك. أعد المحاولة أو أوقف إذن الإشعارات من إعدادات المتصفح.' }
    finally { busy.value = false }
  }
  watch(feed.data, value => {
    if (!value) return
    const wasDiagnostic = diagnostic.value
    settings.value = value; lastSync = Date.now()
    if (wasDiagnostic !== diagnostic.value) { inspected = false; pushEnabled.value = false; diagnosticSubscription = null }
    const pending = alerts.observe(value.enabled ? value.pending : [])
    if (!value.sound_enabled || !pending.length) sound.stop()
    post({ type: 'staff-pending-orders', ids: value.enabled ? value.pending.map(row => row.id) : [] })
    void tick()
    void inspectSubscription()
  })
  async function inspectSubscription() {
    if (inspected || !supported.value || !pushAvailable.value || !allowed()) return
    inspected = true
    const current = revision
    try {
      const reg = await navigator.serviceWorker.getRegistration('/')
      const subscription = await reg?.pushManager.getSubscription()
      if (!subscription || disposed || current !== revision) return
      registration = reg
      if (diagnostic.value) diagnosticSubscription = await staffNotificationsApi.subscribeDiagnostic(subscription.toJSON())
      else await staffNotificationsApi.subscribe(subscription.toJSON())
      if (current === revision && !disposed) pushEnabled.value = true
    } catch {
      pushEnabled.value = false
      message.value = 'أعد تفعيل إشعارات هذا الجهاز لربطها بحسابك الحالي.'
    }
  }
  watch([() => auth.token, allowed], () => {
    revision++; inspected = false; diagnosticSubscription = null; pushEnabled.value = false; settings.value = null; lastSync = 0; alerts.reset(); sound.reset(); sound.stop(); feed.reset()
    if (allowed()) void feed.load()
    else post({ type: 'staff-clear-notifications' })
  }, { immediate: true })
  function pushed(event) { if (event.data?.type === 'staff-order-push' && allowed()) void feed.load(true) }
  function resolved(event) {
    if (event.detail?.status === 'pending') return
    alerts.removeOrder(event.detail?.id); sound.stop()
    post({ type: 'staff-order-resolved', order_id: event.detail?.id })
    if (allowed()) void feed.load(true, true)
  }
  onMounted(async () => {
    timer = setInterval(() => { void tick() }, 1000)
    navigator.serviceWorker?.addEventListener('message', pushed)
    window.addEventListener('shishli-order-updated', resolved)
    if (supported.value) {
      try {
        const reg = await navigator.serviceWorker.getRegistration('/')
        if (disposed) return
        if (reg) registration = reg
      } catch { message.value = 'تعذر الوصول إلى إشعارات المتصفح؛ يمكنك متابعة الطلبات من اللوحة.' }
    }
  })
  onBeforeUnmount(() => {
    disposed = true; revision++; clearInterval(timer); sound.stop()
    navigator.serviceWorker?.removeEventListener('message', pushed)
    window.removeEventListener('shishli-order-updated', resolved)
  })
  return { settings, diagnostic, pushAvailable, sound, feed, supported, pushEnabled, busy, message, enablePush, disablePush }
}
