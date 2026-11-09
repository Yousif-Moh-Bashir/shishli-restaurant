<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { staffNotificationsApi } from '../../api/staffNotifications.api'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
const state = ref(null), error = ref(''), received = ref(false), loading = ref(false)
async function refresh() {
  if (loading.value) return
  loading.value = true; error.value = ''
  try { state.value = await staffNotificationsApi.diagnostic() }
  catch { state.value = null; error.value = 'وضع اختبار الإشعارات غير متاح هنا، أو انتهت جلسة الدخول.' }
  finally { loading.value = false }
}
function message(event) { if (event.data?.type === 'staff-push-diagnostic') received.value = true }
onMounted(() => { void refresh(); navigator.serviceWorker?.addEventListener('message', message); window.addEventListener('shishli-push-subscription-changed', refresh) })
onBeforeUnmount(() => { navigator.serviceWorker?.removeEventListener('message', message); window.removeEventListener('shishli-push-subscription-changed', refresh) })
</script>
<template>
  <main id="main">
    <AdminPageHeader><div><h1>اختبار إشعارات الجهاز</h1><p>صفحة اختبار محلية محمية؛ لا تنشئ طلبات ولا ترسل إشعارات تلقائية.</p></div></AdminPageHeader>
    <section class="diagnostic-panel">
      <p v-if="error" role="status">{{ error }}</p>
      <template v-if="state?.enabled">
        <p v-if="!state.ready">إعداد مفاتيح إشعارات الجهاز غير مكتمل.</p>
        <p>فعّل إشعارات الاختبار من قائمة الإدارة على هذا الجهاز، ثم حدّث حالة الاشتراك.</p>
        <dl v-if="state.subscription"><dt>معرّف اشتراك هذا الجهاز</dt><dd><code dir="ltr">{{ state.subscription.id }}</code></dd><dt>معرّف الحساب للاختبار</dt><dd><code dir="ltr">{{ state.subscription.user_id }}</code></dd></dl>
        <p v-else>لا يوجد اشتراك تشخيص مرتبط بجلسة الدخول الحالية.</p>
      </template>
      <p v-if="received" role="status">وصل إشعار الاختبار إلى هذه النافذة.</p>
      <button type="button" class="button button-primary" :disabled="loading" @click="refresh">تحديث حالة الاشتراك</button>
    </section>
  </main>
</template>
<style scoped>
.diagnostic-panel{margin:20px;padding:20px;border:1px solid var(--line);border-radius:12px;background:#fff;line-height:1.9}.diagnostic-panel dd{margin:0 0 12px;overflow-wrap:anywhere}.diagnostic-panel code{font-size:13px}
</style>
