<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { deliveryExceptionsApi } from '../../api/deliveryExceptions.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { normalizeApiError } from '../../utils/apiError'
import { deliveryLabels } from '../../constants/driverDelivery'
import { formatDateTime, formatCurrency } from '../../utils/formatters'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import DeliveryExceptionActions from '../../components/orders/DeliveryExceptionActions.vue'
import CashCollection from '../../components/payments/CashCollection.vue'

const page = ref(1), busy = ref(false)
const state = useOrderPolling(signal => deliveryExceptionsApi.list({ page: page.value, per_page: 20 }, signal), { paused: () => busy.value, normalizeError: normalizeApiError })
const orders = computed(() => state.data.value?.data || [])
const interventionCount = computed(() => orders.value.filter(order => order.delivery_exception?.requested_at).length)
const reload = () => state.load(true, true)
function changePage(value) { page.value = value; void reload() }
onBeforeUnmount(() => { busy.value = false })
</script>

<template>
  <main id="main" class="delivery-exceptions-page">
    <header class="exceptions-hero">
      <div class="exceptions-hero-copy">
        <span class="eyebrow">مركز متابعة التوصيل</span>
        <h1>استثناءات التوصيل</h1>
        <p>تابع الحالات التي تحتاج تدخلًا، وراجع عهدة الطلب قبل تنفيذ أي إجراء.</p>
      </div>
      <button class="button refresh-button" :disabled="busy || state.refreshing.value" @click="reload">
        <span aria-hidden="true">↻</span>{{ state.refreshing.value ? 'جارٍ التحديث…' : 'تحديث القائمة' }}
      </button>
    </header>

    <section class="exceptions-overview" aria-label="ملخص القائمة">
      <div class="overview-card overview-card--total">
        <span class="overview-mark" aria-hidden="true">▤</span>
        <div><small>النتائج المعروضة</small><strong>{{ orders.length }}</strong><span>من أصل {{ state.data.value?.meta?.total ?? 0 }} طلب</span></div>
      </div>
      <div class="overview-card overview-card--attention">
        <span class="overview-mark" aria-hidden="true">!</span>
        <div><small>طلبات طلبت تدخل الإدارة</small><strong>{{ interventionCount }}</strong><span>في الصفحة الحالية</span></div>
      </div>
      <div class="overview-note">
        <span class="overview-note-mark" aria-hidden="true">i</span>
        <p>تظهر الحالات من جميع الأيام، بما فيها التعذر والإرجاع والمهام المسندة منذ أكثر من ساعتين. لا تُغلق أي مهمة تلقائيًا بسبب عمرها.</p>
      </div>
    </section>

    <p v-if="state.loading.value" class="list-feedback" role="status">جارٍ تحميل حالات التوصيل…</p>
    <p v-if="state.error.value" class="list-feedback list-feedback--error" role="alert">{{ state.error.value.message }}</p>

    <section v-if="state.data.value" class="exception-list" aria-label="طلبات استثناءات التوصيل">
      <article v-for="order in orders" :key="order.id" class="exception-card">
        <header class="exception-card-head">
          <div class="exception-order-title">
            <span class="order-number-label">رقم الطلب</span>
            <h2><RouterLink :to="'/admin/orders/' + order.id">{{ order.order_number }}</RouterLink></h2>
          </div>
          <span class="delivery-status">{{ deliveryLabels[order.delivery?.status] || 'بانتظار الإسناد' }}</span>
        </header>

        <div class="exception-facts">
          <div class="fact-item"><span class="fact-icon" aria-hidden="true">⌖</span><div><small>الفرع</small><strong>{{ order.branch?.name || '—' }}</strong></div></div>
          <div class="fact-item"><span class="fact-icon" aria-hidden="true">♙</span><div><small>المندوب</small><strong>{{ order.delivery?.driver?.name || 'دون مندوب' }}</strong></div></div>
          <div class="fact-item"><span class="fact-icon" aria-hidden="true">◷</span><div><small>وقت الطلب</small><strong>{{ formatDateTime(order.placed_at) }}</strong></div></div>
          <div class="fact-item"><span class="fact-icon" aria-hidden="true">▣</span><div><small>إجمالي الطلب</small><strong>{{ formatCurrency(order.pricing.total) }}</strong></div></div>
        </div>

        <div class="payment-strip">
          <span class="payment-label">طريقة الدفع</span>
          <strong>{{ { cash: 'نقدي', network: 'الشبكة', card: 'إلكتروني' }[order.payment?.method] || 'غير محدد' }}</strong>
          <span class="payment-divider" aria-hidden="true"></span>
          <span class="payment-label">حالة الدفع</span>
          <strong>{{ { pending: 'بانتظار التأكيد', paid: 'مدفوع', cancelled: 'ملغي', failed: 'متعثر', refunded: 'مسترد', partially_refunded: 'مسترد جزئيًا' }[order.payment?.status] || order.payment?.status || '—' }}</strong>
        </div>

        <div class="exception-card-body">
          <CashCollection :order="order" :reload="reload" @active="busy = $event" />
          <DeliveryExceptionActions :order="order" :reload="reload" @active="busy = $event" />
        </div>

        <details v-if="order.delivery?.history?.length" class="delivery-history">
          <summary><span>سجل حركة التوصيل</span><small>{{ order.delivery.history.length }} أحداث</small></summary>
          <ol>
            <li v-for="(entry, index) in order.delivery.history" :key="index">
              <span class="history-dot" aria-hidden="true"></span>
              <div><strong>{{ deliveryLabels[entry.to_status] || entry.to_status }}</strong><span>{{ entry.actor }} · {{ formatDateTime(entry.at) }}</span><p v-if="entry.reason">{{ entry.reason }}</p></div>
            </li>
          </ol>
        </details>
      </article>

      <div v-if="!orders.length" class="exceptions-empty">
        <span class="empty-mark" aria-hidden="true">✓</span>
        <h2>لا توجد حالات تحتاج المعالجة</h2>
        <p>ستظهر هنا طلبات التوصيل التي تحتاج إلى متابعة أو تدخل.</p>
      </div>

      <ApiPagination :meta="state.data.value.meta" :links="state.data.value.links" :busy="busy || state.refreshing.value" @page="changePage" />
    </section>
  </main>
</template>

<style scoped>
.delivery-exceptions-page{--exception-ink:#42151f;--exception-muted:#806c70;--exception-line:#eadfd8;--exception-cream:#fbf6f0;min-width:0;color:var(--exception-ink)}
.exceptions-hero{position:relative;display:flex;align-items:center;justify-content:space-between;gap:20px;overflow:hidden;padding:27px 30px;margin-bottom:20px;border:1px solid #eadbd0;border-radius:16px;background:radial-gradient(ellipse at 5% 10%,#fff9 0 2%,transparent 45%),linear-gradient(120deg,#f8eee5,#fffaf4 65%,#f4e8de)}
.exceptions-hero:after{content:"";position:absolute;inset-inline-end:-32px;bottom:-78px;width:220px;height:220px;border:1px solid #a77b4630;border-radius:50%;box-shadow:0 0 0 18px #a77b4608,0 0 0 38px #a77b4608;pointer-events:none}
.exceptions-hero-copy{position:relative;z-index:1}.exceptions-hero .eyebrow{display:inline-block;color:#9a6c35;font-size:11px;font-weight:800;letter-spacing:.04em;margin-bottom:6px}.exceptions-hero h1{margin:0;font-size:clamp(22px,3vw,30px);line-height:1.5}.exceptions-hero p{margin:4px 0 0;color:var(--exception-muted);font-size:13px;line-height:1.8}.refresh-button{position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;white-space:nowrap}.refresh-button span{font-size:19px;line-height:1}
.exceptions-overview{display:grid;grid-template-columns:repeat(2,minmax(160px,.7fr)) minmax(260px,1.6fr);gap:12px;margin-bottom:22px}.overview-card,.overview-note{display:flex;align-items:center;gap:13px;min-width:0;padding:15px 17px;border:1px solid var(--exception-line);border-radius:12px;background:var(--admin-surface,#fff)}.overview-card--attention{background:#fffbf3;border-color:#eadfc9}.overview-mark,.overview-note-mark{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;border-radius:12px;background:#f5ebe3;color:#762536;font-size:20px;font-weight:800}.overview-card--attention .overview-mark{background:#f7edda;color:#936b24}.overview-card>div{display:grid;grid-template-columns:auto auto;align-items:baseline;gap:0 10px}.overview-card small,.overview-card span:not(.overview-mark){font-size:11px;color:var(--exception-muted)}.overview-card small{grid-column:1/-1}.overview-card strong{font-size:25px;line-height:1.4}.overview-card>div>span{white-space:nowrap}.overview-note{background:#f8f3eb}.overview-note-mark{flex-basis:30px;width:30px;height:30px;border-radius:50%;background:#efe5d8;color:#846a48;font-family:serif}.overview-note p{margin:0;color:#6f5c4d;font-size:12px;line-height:1.9}
.list-feedback{margin:12px 0;padding:14px 16px;border:1px solid var(--exception-line);border-radius:10px;background:#fff;font-size:13px}.list-feedback--error{border-color:#e8c4c5;background:#fff7f7;color:#9e2332}
.exception-list{display:grid;gap:16px}.exception-card{min-width:0;overflow:hidden;border:1px solid var(--exception-line);border-radius:14px;background:var(--admin-surface,#fff);box-shadow:0 4px 18px #42151f08}.exception-card-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;border-bottom:1px solid #f0e8e2;background:linear-gradient(90deg,#fff,#fdfaf7)}.order-number-label{display:block;color:var(--exception-muted);font-size:10px}.exception-order-title h2{margin:1px 0 0;font-size:19px}.exception-order-title a{color:var(--exception-ink);text-decoration:none}.exception-order-title a:hover{text-decoration:underline;text-underline-offset:3px}.delivery-status{display:inline-flex;align-items:center;min-height:30px;padding:4px 11px;border:1px solid #ead9c1;border-radius:999px;background:#fff8ec;color:#79521e;font-size:11px;font-weight:700;white-space:nowrap}
.exception-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:18px 22px}.fact-item{display:flex;align-items:center;gap:10px;min-width:0}.fact-icon{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:10px;background:var(--exception-cream);color:#855263;font-size:17px}.fact-item>div{display:grid;min-width:0;gap:2px}.fact-item small{color:var(--exception-muted);font-size:10px}.fact-item strong{overflow-wrap:anywhere;font-size:12px;line-height:1.6}.payment-strip{display:flex;align-items:center;flex-wrap:wrap;gap:7px 10px;padding:11px 22px;border-block:1px solid #f0e8e2;background:#fcfaf7;font-size:11px}.payment-label{color:var(--exception-muted)}.payment-strip strong{font-size:11px}.payment-divider{height:17px;border-inline-start:1px solid var(--exception-line);margin-inline:5px}.exception-card-body{display:grid;grid-template-columns:minmax(0,1fr);gap:0;padding:0 22px}.exception-card-body :deep(.delivery-exception-actions){border-top:1px solid #f0e8e2}.delivery-history{margin:0 22px 18px;border-top:1px solid #f0e8e2}.delivery-history summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0;cursor:pointer;list-style:none;font-size:12px;font-weight:700}.delivery-history summary::-webkit-details-marker{display:none}.delivery-history summary:after{content:"＋";color:#8d6a55;font-size:17px}.delivery-history[open] summary:after{content:"−"}.delivery-history summary small{margin-inline-start:auto;color:var(--exception-muted);font-size:10px;font-weight:400}.delivery-history ol{display:grid;gap:0;margin:0 0 15px;padding:0;list-style:none}.delivery-history li{display:grid;grid-template-columns:12px minmax(0,1fr);gap:10px;padding:0 0 14px;font-size:11px}.history-dot{position:relative;width:9px;height:9px;margin-top:5px;border:2px solid #a88150;border-radius:50%;background:#fff}.delivery-history li:not(:last-child) .history-dot:after{content:"";position:absolute;top:8px;inset-inline-start:2px;width:1px;height:30px;background:#e6d9ca}.delivery-history li div{display:grid;gap:2px}.delivery-history li strong{font-size:11px}.delivery-history li span:not(.history-dot){color:var(--exception-muted)}.delivery-history li p{margin:3px 0 0;color:#705d5d;overflow-wrap:anywhere}.exceptions-empty{display:grid;justify-items:center;padding:42px 20px;border:1px dashed #ddcfc4;border-radius:14px;background:#fffdf9;text-align:center}.empty-mark{display:grid;place-items:center;width:48px;height:48px;border-radius:50%;background:#edf5ed;color:#428151;font-size:25px}.exceptions-empty h2{margin:14px 0 3px;font-size:17px}.exceptions-empty p{margin:0;color:var(--exception-muted);font-size:12px;line-height:1.8}
@media(max-width:900px){.exceptions-overview{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-note{grid-column:1/-1}.exception-facts{grid-template-columns:repeat(2,minmax(0,1fr));row-gap:16px}}
@media(max-width:560px){.exceptions-hero{align-items:flex-start;flex-direction:column;padding:21px 18px}.exceptions-hero:after{inset-inline-end:-120px}.refresh-button{width:100%}.exceptions-overview{grid-template-columns:1fr}.overview-note{grid-column:auto}.exception-card-head{align-items:flex-start;padding:15px 16px}.exception-facts{padding:16px;gap:14px 8px}.fact-item{align-items:flex-start;gap:7px}.fact-icon{flex-basis:30px;width:30px;height:30px}.fact-item strong{font-size:11px}.payment-strip{padding:11px 16px}.exception-card-body{padding-inline:16px}.delivery-history{margin-inline:16px}}
</style>
