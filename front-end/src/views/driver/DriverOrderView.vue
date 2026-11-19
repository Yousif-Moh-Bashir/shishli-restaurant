<script setup>
import { ref, computed, watch, defineAsyncComponent, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useRoute } from 'vue-router'
import { driversApi } from '../../api/drivers.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { preparationLabels, deliveryLabels, deliveryActionLabels, driverTime, driverError, isDriverOrderClosed } from '../../constants/driverDelivery'
import { formatCurrency } from '../../utils/formatters'
import { validLocation } from '../../utils/location'
import SiteIcon from '../../components/site/SiteIcon.vue'
import OrderLifecycleSummary from '../../components/orders/OrderLifecycleSummary.vue'
import DriverIntervention from '../../components/orders/DriverIntervention.vue'
const DeliveryDestinationMap = defineAsyncComponent(() => import('../../components/orders/DeliveryDestinationMap.vue'))
const route = useRoute(), busy = ref(false), error = ref(null), notice = ref(''), reason = ref(''), failureOpen = ref(false), cashOpen = ref(false), amount = ref('')
const auth = useAuthStore(), online = ref(navigator.onLine), destinationOpen = ref(false), pendingStart = ref(null)
const state = useOrderPolling(signal => driversApi.order(route.params.id, signal), {
  enabled: () => !isDriverOrderClosed(state.data.value),
  paused: () => busy.value || cashOpen.value || failureOpen.value,
  normalizeError: driverError,
})
const order = state.data, readOnly = computed(() => isDriverOrderClosed(order.value)), actions = computed(() => readOnly.value ? [] : order.value?.available_actions || [])
const canOperate = computed(() => online.value && !busy.value && !state.refreshing.value && !state.error.value && !readOnly.value)
const deliveryStarted = computed(() => ['on_the_way','delivered'].includes(order.value?.delivery_status))
const tripToolsAvailable = computed(() => order.value?.trip_tools_available ?? deliveryStarted.value)
watch(order, value => {
  if (!value || !auth.driverProfile) return
  if (!isDriverOrderClosed(value)) auth.driverProfile.current_order = { id:value.id, order_number:value.order_number, delivery_status:value.delivery_status, progress_reminder:value.progress_reminder }
  else if (auth.driverProfile.current_order?.id === value.id) auth.driverProfile.current_order = null
})
const paymentAction = computed(() => order.value?.delivery_status === 'on_the_way'
  ? ['collect_cash', 'confirm_network'].find(action => actions.value.includes(action)) || null
  : null)
const primaryAction = computed(() => paymentAction.value || ['start_delivery', 'picked_up', 'on_the_way', 'delivered'].find(action => actions.value.includes(action)) || null)
const showPrimaryAction = computed(() => !readOnly.value && Boolean(primaryAction.value || deliveryStarted.value))
const primaryActionLabel = computed(() => paymentAction.value === 'collect_cash' ? 'متابعة تأكيد التسليم — تسجيل التحصيل النقدي'
  : paymentAction.value === 'confirm_network' ? 'متابعة تأكيد التسليم — تأكيد دفع الشبكة'
    : deliveryActionLabels[primaryAction.value] || (deliveryStarted.value ? 'تأكيد التسليم' : ''))
watch(paymentAction, value => { if (value !== 'collect_cash') cashOpen.value = false })
const location = computed(() => order.value?.address?.location)
const hasLocation = computed(() => validLocation(location.value?.latitude, location.value?.longitude))
const navigation = computed(() => hasLocation.value ? 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(location.value.latitude + ',' + location.value.longitude) : '')
const mapUrl = computed(() => hasLocation.value ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(location.value.latitude + ',' + location.value.longitude) : '')
let operationGeneration = 0
async function mutate(request, onSuccess, returnsOrder = true) {
  if (!canOperate.value) return
  const generation = operationGeneration, id = route.params.id
  busy.value = true; error.value = null; notice.value = ''
  try {
    const value = await request()
    if (generation !== operationGeneration) return
    if (returnsOrder && value?.id !== id) throw Error('INVALID_DRIVER_ORDER_RESPONSE')
    await onSuccess(value)
  } catch (cause) {
    if (generation !== operationGeneration) return
    error.value = driverError(cause)
    if ([401,403,404].includes(error.value.status)) order.value = null
    await state.load(false,true)
  } finally {
    if (generation === operationGeneration) busy.value = false
  }
}
async function operate(status) {
  if (!canOperate.value || !actions.value.includes(status)) return
  const id = route.params.id
  if (status === 'start_delivery') {
    if (!window.confirm('هل استلمت الطلب فعليًا من الفرع وتنطلق إلى العميل الآن؟ سيُسجّل بدء التوصيل ويُحدّث تتبع العميل.')) return
    if (!pendingStart.value || pendingStart.value.expected_revision !== order.value.delivery_revision) {
      pendingStart.value = { operation_key: crypto.randomUUID(), expected_revision: order.value.delivery_revision, picked_up_and_departing: true }
    }
    const payload = {...pendingStart.value}
    await mutate(()=>driversApi.start(id,payload), value=>{
      order.value=value;pendingStart.value=null;notice.value='سُجّل استلام الطلب وبدء التوصيل. أكّد التسليم عند تسليمه للعميل.'
    })
    return
  }
  if (status === 'picked_up' && !window.confirm('هل استلمت هذا الطلب فعليًا من الفرع؟')) return
  if (status === 'on_the_way' && !window.confirm('هل بدأت التوصيل إلى العميل الآن؟ لا تستخدم هذا الإجراء لتسجيل خروج سابق بعد التسليم.')) return
  if (status === 'confirm_network') {
    if (!window.confirm('تحققت من نجاح عملية جهاز الشبكة المحمول لدى العميل؟')) return
    await mutate(()=>driversApi.confirmNetwork(id),value=>{order.value=value;notice.value='تم تسجيل نجاح دفع الشبكة باسمك.'})
    return
  }
  if (status === 'delivered' && !window.confirm('هل سلّمت الطلب للعميل؟')) return
  const revision=order.value.delivery_revision, note=status==='failed'?reason.value:null
  await mutate(()=>driversApi.transition(id,status,note,revision),value=>{
    order.value=value;failureOpen.value=false;reason.value='';notice.value='تم تحديث حالة التوصيل.'
  })
}
async function collect() {
  if (!canOperate.value || !actions.value.includes('collect_cash') || !window.confirm('تأكيد استلام ' + formatCurrency(amount.value) + ' نقدًا من العميل؟')) return
  const id=route.params.id, collected=amount.value
  await mutate(()=>driversApi.collect(id,collected),async()=>{
    cashOpen.value=false;notice.value='تم تسجيل المبلغ المحصل.';await state.load(false,true)
  },false)
}
async function runPrimaryAction() {
  if (!canOperate.value) return
  if (primaryAction.value === 'collect_cash') {
    if (cashOpen.value) { await collect(); return }
    cashOpen.value = true
    amount.value = order.value.cash_due
    await nextTick()
    const input = document.querySelector('.driver-field input[type=number]')
    input?.focus({ preventScroll: true })
    input?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    return
  }
  if (primaryAction.value) void operate(primaryAction.value)
}
watch([() => route.params.id, () => auth.token], () => { operationGeneration++;busy.value=false;state.reset();pendingStart.value=null;error.value=null;notice.value='';failureOpen.value=cashOpen.value=false;if(auth.token)void state.load() })
function updateConnection() {
  online.value = navigator.onLine
  if (online.value) void state.load(true, true)
}
onMounted(() => { window.addEventListener('online', updateConnection); window.addEventListener('offline', updateConnection) })
onBeforeUnmount(() => { window.removeEventListener('online', updateConnection); window.removeEventListener('offline', updateConnection) })
void state.load()
</script>
<template><main id="main" class="driver-main" :class="{'has-mobile-action':showPrimaryAction}"><div class="driver-toolbar"><RouterLink class="driver-link" to="/driver/orders">العودة للطلبات</RouterLink><button class="driver-link" :disabled="busy || state.refreshing.value" @click="state.load()">تحديث</button></div><p v-if="state.loading.value" role="status">جارٍ تحميل الطلب…</p><div v-if="error || state.error.value" class="driver-error" role="alert"><p>{{ error?.message || state.error.value?.message }}</p><p v-for="(messages,key) in error?.errors" :key="key">{{ Array.isArray(messages)?messages.join(' '):messages }}</p><button class="driver-link" @click="state.load()">إعادة المحاولة</button></div><p v-if="notice" class="driver-notice" role="status">{{ notice }}</p><p v-if="!online" class="driver-error" role="status">أنت غير متصل. لا تُسجّل الحالات دون اتصال؛ ستُراجع حالة المهمة من الخادم عند عودة الإنترنت.</p><p v-if="state.error.value && order" class="driver-error" role="status">هذه آخر بيانات مؤكدة؛ الإجراءات متوقفة حتى تحديثها من الخادم.</p><template v-if="order"><p v-if="order.progress_reminder?.stalled" class="driver-notice" role="status">{{ order.progress_reminder.message }} آخر تقدم: {{ driverTime(order.progress_reminder.last_progress_at) }}<span v-if="order.progress_reminder.elapsed_minutes != null"> · {{ order.progress_reminder.elapsed_minutes }} دقيقة دون تقدم</span><span v-if="order.progress_reminder.escalated"> · تجاوزت المهمة مدة متابعة المشرف.</span></p><section class="driver-card"><div class="driver-card-head"><h1>طلب <bdi>#{{ order.order_number }}</bdi></h1><span class="driver-badge">{{ deliveryLabels[order.delivery_status] }}</span></div><dl><div><dt>حالة تجهيز الطلب</dt><dd>{{ preparationLabels[order.preparation_status] }}</dd></div><div><dt>وقت التجهيز المتوقع</dt><dd>{{ driverTime(order.estimated_ready_at) || 'غير محدد' }}</dd></div></dl><OrderLifecycleSummary :status="order.preparation_status" :delivery-status="order.delivery_status" delivery-only/><p v-if="!readOnly && order.delivery_block" class="driver-muted" role="status">{{ order.delivery_block.message }}</p><div v-if="showPrimaryAction" class="driver-primary-action"><h2>{{ deliveryStarted?'تأكيد التسليم':'الخطوة التالية المطلوبة' }}</h2><p v-if="paymentAction">{{ paymentAction==='collect_cash'?'وصلت للعميل؟ استلم كامل المبلغ النقدي ثم سجّله هنا.':'وصلت للعميل؟ تحقّق من نجاح العملية على جهاز الشبكة ثم أكّد الدفع.' }}</p><p v-if="!primaryAction" class="driver-action-help" role="status">{{ order.delivery_block?.message || 'راجع الإدارة لاستيفاء شروط التسليم.' }}</p><small v-if="!online || state.error.value" class="driver-action-status" role="status">{{ !online?'غير متصل — لا يمكن تسجيل التحديث':'حدّث حالة المهمة قبل المتابعة' }}</small><button class="driver-button" type="button" :disabled="!canOperate || !primaryAction" @click="runPrimaryAction">{{ busy?'جارٍ التحديث…':cashOpen && paymentAction==='collect_cash'?'تأكيد استلام المبلغ':primaryActionLabel }}</button></div><p v-if="['assigned','picked_up'].includes(order.delivery_status) && !readOnly" class="driver-muted">سجّل الاستلام والانطلاق عند حدوثهما فعلًا. بعد الوصول سيقودك زر تأكيد التسليم إلى تسجيل الدفع المطلوب ثم التسليم.</p><details v-if="actions.includes('failed')" class="driver-alternate-action"><summary>تعذّر إكمال التوصيل؟</summary><button class="driver-button secondary" type="button" :disabled="!canOperate" @click="failureOpen=!failureOpen">{{ failureOpen?'إغلاق النموذج':'تسجيل تعذر التسليم' }}</button></details><p v-if="order.delivery_status==='assigned' && order.preparation_status!=='ready'" class="driver-muted">انتظر جاهزية الطلب قبل الاستلام.</p><p v-if="order.delivery_status==='failed'" class="driver-muted">تواصل مع إدارة الفرع لمعالجة الطلب. سيبقى الطلب في عهدتك حتى المعالجة.</p><form v-if="failureOpen && !readOnly" @submit.prevent="operate('failed')"><label class="driver-field">سبب تعذر التسليم<textarea v-model="reason" required minlength="3" maxlength="1000" rows="3" :disabled="!canOperate"/></label><button class="driver-button secondary" :disabled="!canOperate">تسجيل تعذر التسليم</button></form></section><p v-if="order.delivery_status==='delivered'" class="driver-notice" role="status">تم تسليم الطلب وإكماله بنجاح.</p><p v-if="!readOnly && ['assigned','picked_up'].includes(order.delivery_status)" class="driver-muted">إذا سلّمت الطلب فعليًا ونسيت تحديث الحالة، اطلب تدخل الإدارة أدناه واذكر ما حدث. لا تسجّل بدء التوصيل الآن على أنه حدث في وقته.</p><DriverIntervention :order="order" :reload="()=>state.load(false,true)" @active="busy=$event"/><details class="driver-card driver-destination" :open="tripToolsAvailable" @toggle="destinationOpen=$event.target.open"><summary>{{ tripToolsAvailable?'العميل وعنوان التوصيل':'تنسيق قبل الانطلاق: عرض العنوان والاتصال عند الحاجة' }}</summary><p v-if="!tripToolsAvailable" class="driver-muted">يمكنك التأكد من العنوان أو الاتصال بالعميل قبل الانطلاق. عرض هذه البيانات لا يسجّل استلامًا أو بدء توصيل.</p><div class="driver-address"><p>{{ order.customer?.name }}</p><p dir="ltr">{{ order.customer?.phone }}</p><p v-if="order.address?.recipient_name && order.address.recipient_name!==order.customer?.name">المستلم: {{ order.address.recipient_name }}</p><div v-if="order.address" class="driver-address-location"><p>{{ order.address.city }}، {{ order.address.district }}، {{ order.address.street }}</p><a v-if="tripToolsAvailable && mapUrl && !readOnly" class="driver-button secondary driver-open-map" :href="mapUrl" target="_blank" rel="noopener noreferrer"><SiteIcon name="map-pin"/>فتح على الخريطة</a></div><p v-if="order.address?.building_number">المبنى {{ order.address.building_number }}<span v-if="order.address.floor"> · الطابق {{ order.address.floor }}</span><span v-if="order.address.apartment"> · الشقة {{ order.address.apartment }}</span></p><p v-if="order.address?.landmark">{{ order.address.landmark }}</p><p v-if="order.address?.notes">تعليمات التوصيل: {{ order.address.notes }}</p></div><DeliveryDestinationMap v-if="tripToolsAvailable && hasLocation && (deliveryStarted || destinationOpen)" :key="order.id" :location="location" :branch="order.branch_location"/><p v-if="tripToolsAvailable && hasLocation" class="driver-muted">موقع التوصيل: <bdi>{{ location.latitude }}, {{ location.longitude }}</bdi></p><p v-else-if="tripToolsAvailable" class="driver-muted">لم يُحدد موقع على الخريطة لهذا الطلب. استخدم العنوان واتصل بالعميل.</p><p v-else class="driver-muted">الخرائط والملاحة تظهر بعد تسجيل الاستلام وبدء التوصيل الفعلي. يظل العنوان والاتصال متاحين للتنسيق الضروري.</p><div v-if="!readOnly" class="driver-actions"><a v-if="order.customer?.phone" class="driver-button secondary" :href="'tel:'+order.customer.phone">اتصال بالعميل</a><a v-if="order.address?.phone && order.address.phone!==order.customer?.phone" class="driver-button secondary" :href="'tel:'+order.address.phone">اتصال بالمستلم</a><a v-if="tripToolsAvailable && navigation" class="driver-button" :href="navigation" target="_blank" rel="noopener noreferrer">فتح الملاحة</a></div></details><section class="driver-card"><h2>الدفع والتحصيل</h2><dl><div><dt>إجمالي الطلب</dt><dd>{{ formatCurrency(order.total) }}</dd></div><div><dt>رسوم التوصيل</dt><dd>{{ formatCurrency(order.delivery_fee) }}</dd></div><div><dt>طريقة الدفع</dt><dd>{{ {cash:'نقدًا',network:'الشبكة',card:'البطاقة'}[order.payment_method] }}</dd></div><div><dt>حالة الدفع</dt><dd>{{ {paid:'مدفوع',pending:'بانتظار الدفع',failed:'تعذر الدفع',cancelled:'ملغي',refunded:'مسترد',partially_refunded:'مسترد جزئيًا'}[order.payment_status] || order.payment_status }}</dd></div><div v-if="order.payment_method==='cash'"><dt>المطلوب تحصيله نقدًا</dt><dd>{{ formatCurrency(order.cash_due) }}</dd></div></dl><p v-if="order.payment_status==='pending' && order.delivery_status==='picked_up' && ['cash','network'].includes(order.payment_method)" class="driver-muted">سيظهر إجراء الدفع بعد بدء التوصيل والوصول للعميل.</p><form v-if="cashOpen && paymentAction==='collect_cash'" @submit.prevent="collect"><label class="driver-field">المبلغ المحصل فعليًا<input v-model="amount" type="number" min="0" step="0.01" required :disabled="!canOperate" inputmode="decimal"/></label><p class="driver-muted">سجّل المبلغ الذي استلمته من العميل فعلًا.</p><button class="driver-button" :disabled="!canOperate">تأكيد استلام المبلغ</button><button class="driver-button secondary" type="button" :disabled="busy" @click="cashOpen=false">إلغاء إدخال التحصيل</button></form></section><section v-if="order.history?.length" class="driver-card"><h2>سجل التوصيل</h2><ol class="driver-timeline"><li v-for="(item,index) in order.history" :key="index"><strong>{{ deliveryLabels[item.to_status] }}</strong><p>{{ item.actor }}<span v-if="item.reason"> · {{ item.reason }}</span></p><small>{{ driverTime(item.at) }}</small></li></ol></section></template></main></template>
<style scoped>
.driver-primary-action{margin-top:16px;padding:15px;border:1px solid #ead7c5;border-radius:12px;background:linear-gradient(135deg,#fff9f1,#fff)}
.driver-destination summary{cursor:pointer;min-height:48px;font-weight:700;line-height:1.8}.driver-primary-action>p{margin:0 0 10px;color:#756357;font-size:13px;line-height:1.8}
.driver-primary-action .driver-button{width:100%;min-height:52px;font-size:15px}
.driver-alternate-action{margin-top:12px;border-top:1px solid #eee5dc;padding-top:10px}
.driver-alternate-action summary{min-height:40px;display:flex;align-items:center;cursor:pointer;color:#806c60;font-size:12px}
.driver-alternate-action .driver-button{width:100%;margin-top:8px}
.driver-action-status{display:block;font-size:12px;line-height:1.8;margin-bottom:8px;color:#9a3333}
@media(max-width:700px){
 .driver-main.has-mobile-action{padding-bottom:calc(240px + env(safe-area-inset-bottom,0px))}
 .driver-primary-action{position:fixed;inset-inline:12px;bottom:calc(78px + env(safe-area-inset-bottom,0px));z-index:60;margin:0;padding:12px;background:#fffdfa;border:1px solid #dfcdbd;box-shadow:0 -3px 18px #35261f20;border-radius:12px}
 .driver-primary-action h2{font-size:14px;margin:0 0 7px;line-height:1.6}
 .driver-primary-action>p{display:none}
 .driver-primary-action>.driver-action-help{display:block;font-size:12px;line-height:1.8;margin:0 0 8px}
 .driver-primary-action .driver-button{font-size:14px;line-height:1.7;padding:10px;white-space:normal}
}
</style>
