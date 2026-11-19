<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { deliveryExceptionsApi } from '../../api/deliveryExceptions.api'

const auth = useAuthStore(), online = ref(navigator.onLine)
const allowed = computed(() => auth.can('orders.view') && auth.can('delivery_exceptions.view'))
const state = useOrderPolling(signal => deliveryExceptionsApi.alerts(signal), { interval: 30000, enabled: () => allowed.value })
watch([() => auth.token, allowed], () => {
  state.reset()
  if (allowed.value) void state.load()
}, { immediate: true })
function connection() { online.value = navigator.onLine }
onMounted(() => { window.addEventListener('online', connection); window.addEventListener('offline', connection) })
onBeforeUnmount(() => { window.removeEventListener('online', connection); window.removeEventListener('offline', connection) })
</script>
<template>
  <aside v-if="allowed && (state.data.value?.count || state.error.value)" class="delivery-progress-alert" aria-label="تنبيهات تأخر التوصيل">
    <p v-if="state.data.value?.count" role="status">طلبات توصيل تحتاج متابعة المشرف: <strong>{{ state.data.value.count }}</strong>. تجاوزت مدة التصعيد دون تقدم مسجل.</p>
    <p v-if="!online || state.error.value" class="progress-stale" role="status">تعذر تحديث التنبيهات؛ قد تكون البيانات المعروضة قديمة. تُراجع عند عودة الاتصال.</p>
    <RouterLink to="/admin/delivery-exceptions?attention=escalated">متابعة الطلبات المتأخرة</RouterLink>
    <button v-if="online && state.error.value" type="button" :disabled="state.refreshing.value" @click="state.load()">إعادة المحاولة</button>
  </aside>
</template>
<style scoped>
.delivery-progress-alert{display:flex;align-items:center;flex-wrap:wrap;gap:10px 18px;margin:12px 20px;padding:14px 18px;background:#fff7e7;border:1px solid #dfc692;border-radius:12px;color:#694b22;font-size:13px;line-height:1.8}
.delivery-progress-alert p{margin:0;flex:1 1 300px}.delivery-progress-alert a,.delivery-progress-alert button{min-height:44px;display:inline-flex;align-items:center;color:#762536;font-weight:700}.progress-stale{flex-basis:100%!important}
@media(max-width:560px){.delivery-progress-alert{margin:10px;padding:12px}.delivery-progress-alert a{width:100%}}
</style>
