<script setup>
import { ref, computed, onMounted } from 'vue'
import { staffWebPushPilotApi as api } from '../../api/staffWebPushPilot.api'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
const state = ref(null), error = ref(''), busy = ref(false)
const supported = computed(() => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window)
async function refresh() {
  try { state.value = await api.status() }
  catch { state.value = null; error.value = 'التجربة غير متاحة لهذا الحساب أو انتهت مدتها.' }
}
async function subscribe() {
  if (busy.value || !state.value || !supported.value) return
  busy.value = true; error.value = ''
  try {
    if (await Notification.requestPermission() !== 'granted') throw Error('permission')
    const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' })
    await navigator.serviceWorker.ready
    let subscription = await registration.pushManager.getSubscription()
    if (!subscription) {
      const bytes = atob(state.value.public_key.replace(/-/g, '+').replace(/_/g, '/'))
      subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from(bytes, character => character.charCodeAt(0)) })
    }
    await api.subscribe(subscription.toJSON())
    await refresh()
  } catch { error.value = 'تعذر تسجيل الجهاز. تحقق من إذن الإشعارات؛ التجربة تسمح بجهاز واحد فقط.' }
  finally { busy.value = false }
}
async function unsubscribe() {
  if (busy.value || !state.value?.subscription) return
  busy.value = true; error.value = ''
  try {
    await api.unsubscribe(state.value.subscription.id)
    const registration = await navigator.serviceWorker.getRegistration('/')
    await (await registration?.pushManager.getSubscription())?.unsubscribe()
    registration?.active?.postMessage({ type: 'staff-clear-notifications' })
    await refresh()
  } catch { error.value = 'تعذر إلغاء الاشتراك. حدّث الحالة وأعد المحاولة.' }
  finally { busy.value = false }
}
onMounted(refresh)
</script>
<template>
  <main id="main">
    <AdminPageHeader><div><h1>تجربة إشعارات الجهاز</h1><p>تجربة مؤقتة لموظف واحد وجهاز واحد. لا تنشئ طلبًا ولا ترسل إشعارات تلقائية.</p></div></AdminPageHeader>
    <section class="pilot-panel">
      <p v-if="error" role="status">{{ error }}</p>
      <template v-if="state">
        <p v-if="!supported">استخدم Chrome على Android عبر اتصال HTTPS، واسمح بإشعارات الموقع.</p>
        <p v-if="state.subscription">اشتراك الجهاز مسجل: <code dir="ltr">{{ state.subscription.id }}</code></p>
        <button v-if="supported" type="button" :disabled="busy" @click="subscribe">{{ state.subscription ? 'تحديث ربط هذا الجهاز' : 'تسجيل هذا الجهاز للتجربة' }}</button>
        <button v-if="state.subscription" type="button" :disabled="busy" @click="unsubscribe">إلغاء اشتراك التجربة</button>
        <p>الإرسال يتم يدويًا من الخادم بعد الموافقة، ولا يوجد زر إرسال في هذه الصفحة.</p>
      </template>
      <button type="button" :disabled="busy" @click="refresh">تحديث الحالة</button>
    </section>
  </main>
</template>
<style scoped>
.pilot-panel{margin:20px;padding:20px;border:1px solid var(--line);border-radius:12px;background:#fff;line-height:1.9;overflow-wrap:anywhere}.pilot-panel button{padding:10px 16px;margin:8px;border:1px solid var(--line);border-radius:8px;font:inherit;cursor:pointer}.pilot-panel button:disabled{opacity:.6;cursor:wait}
</style>
