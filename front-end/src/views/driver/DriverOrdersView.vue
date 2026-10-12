<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { driversApi } from '../../api/drivers.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { preparationLabels, deliveryLabels, driverTime, driverError, isDriverOrderClosed } from '../../constants/driverDelivery'
import { formatCurrency } from '../../utils/formatters'
const router = useRouter(), auth = useAuthStore(), scope = ref('available'), page = ref(1), busy = ref(false), error = ref(null)
const list = useOrderPolling(signal => driversApi.orders({ scope: scope.value, page: page.value, period: 'current' }, signal), { paused: () => busy.value, normalizeError: driverError })
watch([scope, page, () => auth.driverProfile?.available], () => { list.reset(); void list.load() })
async function accept(order) {
  if (busy.value) return
  busy.value = true; error.value = null
  try { await driversApi.accept(order.id); await router.push('/driver/orders/' + order.id) }
  catch (cause) { error.value = driverError(cause); await list.load(false, true) }
  finally { busy.value = false }
}
void list.load()
</script>
<template>
  <main id="main" class="driver-main">
    <h1>طلبات التوصيل</h1>
    <p class="driver-muted">طلبات التوصيل الجاهزة أولًا، ثم المتوقع تجهيزها خلال 15 دقيقة. يمكنك قبول الطلب بعد إعلان المطعم أنه جاهز فقط. تظهر طلبات الفرع المرتبط بحسابك فقط.</p>
    <p v-if="list.data.value?.driver_context?.work_day" class="driver-muted">يوم العمل: <bdi>{{ list.data.value.driver_context.work_day.date }}</bdi></p>
    <section v-if="list.data.value?.driver_context?.active_order" class="driver-current-order">
      <div><small>طلبك الحالي</small><strong>#{{ list.data.value.driver_context.active_order.order_number }}</strong><p>{{ preparationLabels[list.data.value.driver_context.active_order.preparation_status] }} · {{ deliveryLabels[list.data.value.driver_context.active_order.delivery_status] }}</p><p v-if="list.data.value.driver_context.active_order.is_previous_day">بدأ في يوم عمل سابق ويحتاج إلى إكماله.</p></div>
      <RouterLink class="driver-button" :to="'/driver/orders/'+list.data.value.driver_context.active_order.id">متابعة الطلب</RouterLink>
    </section>
    <p v-if="scope==='available' && list.data.value?.driver_context?.blocking_reason" class="driver-notice" role="status">{{ list.data.value.driver_context.blocking_reason }}</p>
    <div class="driver-toolbar">
      <div class="driver-tabs"><button :class="{ 'is-selected': scope==='available' }" :aria-pressed="scope==='available'" :disabled="busy" @click="scope='available';page=1">طلبات الفرع المتاحة</button><button :class="{ 'is-selected': scope==='mine' }" :aria-pressed="scope==='mine'" :disabled="busy" @click="scope='mine';page=1">طلباتي</button></div>
      <button class="driver-link" :disabled="busy || list.refreshing.value" @click="list.load()">تحديث</button>
    </div>
    <p v-if="list.loading.value" role="status">جارٍ تحميل الطلبات…</p>
    <div v-if="error || list.error.value" class="driver-error" role="alert">{{ error?.message || list.error.value?.message }}<button class="driver-link" @click="list.load()">إعادة المحاولة</button></div>
    <div class="driver-order-grid">
      <article v-for="order in list.data.value?.data || []" :key="order.id" class="driver-card">
        <div class="driver-card-head"><h2>طلب <bdi>#{{ order.order_number }}</bdi></h2><span class="driver-badge" :class="{ready:order.preparation_status==='ready'}">{{ preparationLabels[order.preparation_status] }}</span></div>
        <dl><div><dt>حالة التوصيل</dt><dd>{{ deliveryLabels[order.delivery_status || 'unassigned'] }}</dd></div><div><dt>منطقة التوصيل</dt><dd>{{ order.delivery_zone || order.district || 'غير محددة' }}</dd></div><div><dt>رسوم التوصيل</dt><dd>{{ formatCurrency(order.delivery_fee) }}</dd></div><div v-if="order.estimated_ready_at && order.preparation_status==='preparing'"><dt>التجهيز المتوقع</dt><dd>{{ driverTime(order.estimated_ready_at) }}</dd></div><div><dt>الفرع</dt><dd>{{ order.branch.name }}</dd></div></dl>
        <button v-if="!isDriverOrderClosed(order) && order.available_actions.includes('accept')" class="driver-button" :disabled="busy" @click="accept(order)">{{ busy?'جارٍ القبول…':'قبول الطلب' }}</button>
        <RouterLink v-else-if="scope==='mine'" class="driver-button secondary" :to="'/driver/orders/'+order.id">{{ isDriverOrderClosed(order)?'عرض التفاصيل':'متابعة الطلب' }}</RouterLink>
        <button v-else class="driver-button secondary" disabled>قبول الطلب غير متاح حاليًا</button>
      </article>
    </div>
    <p v-if="list.data.value && !list.data.value.data.length" class="driver-empty">{{ scope==='mine'?'لم تُسند إليك طلبات في يوم العمل الحالي.':'لا توجد طلبات توصيل جاهزة أو قريبة من الجاهزية وغير مسندة في يوم العمل الحالي.' }}</p>
    <div v-if="list.data.value?.meta?.last_page>1" class="driver-pager"><button class="driver-button secondary" :disabled="page<=1 || busy" @click="page--">السابق</button><span>{{ page }} / {{ list.data.value.meta.last_page }}</span><button class="driver-button secondary" :disabled="page>=list.data.value.meta.last_page || busy" @click="page++">التالي</button></div>
  </main>
</template>
