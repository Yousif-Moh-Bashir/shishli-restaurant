<script setup>
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useApiState } from '../../composables/useApiState'
import { useReportContext } from '../../composables/useReportContext'
import { dashboardApi } from '../../api/dashboard.api'
import { reportsApi } from '../../api/reports.api'
import { adminOrdersApi } from '../../api/orders.api'
import { RouterLink } from 'vue-router'
import { reportValue } from '../../utils/reportFormatters'
import { reportDate } from '../../utils/reportFormatters'
import ReportFilters from '../../components/reports/ReportFilters.vue'
import AdminIcon from '../../components/admin/AdminIcon.vue'
import OrderStatusBadge from '../../components/orders/OrderStatusBadge.vue'
import ReportTable from '../../components/reports/ReportTable.vue'
import { reportDefinitions, metricDefinitions } from '../../constants/reports'
import '../../assets/css/reports.css'
const auth = useAuthStore(), contextState = useReportContext()
const dashboard = useApiState(), trend = useApiState(), recent = useApiState()
const filters = ref({ date_from: '', date_to: '', branch_uuid: '', order_type: '', order_status: '', payment_method: '', group_by: 'day' })
const context = computed(() => ({ ...contextState.data.value?.restaurant, timezone: dashboard.data.value?.period.timezone || contextState.data.value?.restaurant?.timezone }))
const statusRows = computed(() => Object.entries(dashboard.data.value?.order_statuses || {}).map(([status,orders_count]) => ({ status, orders_count })))
const typeRows = computed(() => Object.entries(dashboard.data.value?.order_types || {}).map(([type,orders_count]) => ({ type, orders_count })))
const salesDefinition = reportDefinitions.find(report => report.key === 'sales/trend')
const primaryCards = computed(() => {
  const summary = dashboard.data.value?.summary || {}
  return [
    ['orders_count', 'shopping-bag', 'جميع الطلبات في الفترة'],
    ['pending_orders_count', 'clock', 'طلبات بانتظار التأكيد'],
    [summary.completed_sales != null ? 'completed_sales' : 'completed_orders_count', 'badge-check', 'الطلبات المكتملة فقط'],
    ['new_customers_count', 'user-round', 'عملاء جدد خلال الفترة'],
  ].filter(([key]) => summary[key] != null)
})
const extraMetrics = computed(() => Object.entries(dashboard.data.value?.summary || {}).filter(([key,value]) => metricDefinitions[key] && value != null && !primaryCards.value.some(([primary]) => primary === key)))
const financialCards = computed(() => extraMetrics.value.filter(([key]) => ['average_order_value','collected_revenue'].includes(key)))
const change = key => dashboard.data.value?.comparison?.changes?.[key]
const barWidth = count => dashboard.data.value?.summary.orders_count > 0 ? Math.min(100, count / dashboard.data.value.summary.orders_count * 100) + '%' : '0%'
const salesMaximum = computed(() => Math.max(1, ...(trend.data.value?.data || []).map(row => Number(row.completed_sales))))
let loadRevision = 0
async function load() {
  const revision = ++loadRevision
  const query = { ...filters.value }
  trend.clear(); recent.clear()
  const data = await dashboard.run(signal => dashboardApi.get(query, signal))
  if (data && revision === loadRevision) {
    const period = { date_from: data.period.from, date_to: data.period.to }
    await Promise.allSettled([
      auth.can('reports.sales.view') ? trend.run(signal => reportsApi.get('sales/trend', { ...query, ...period }, signal)) : Promise.resolve(),
      auth.can('orders.view') ? recent.run(signal => adminOrdersApi.list({ ...period, branch: query.branch_uuid, type: query.order_type, status: query.order_status, payment_method: query.payment_method, sort: 'placed_at', direction: 'desc', per_page: 5 }, signal)) : Promise.resolve(),
    ])
  }
}
function apply(value) { filters.value = value; load() }
onMounted(load)
onBeforeUnmount(() => { loadRevision++ })
</script>
<template>
  <main id="main" class="dashboard-overview" :aria-busy="dashboard.loading.value">
    <AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>نظرة عامة</h1><p>كل ما تحتاجه لمتابعة المطعم، في مكان واحد.</p></div><button class="button secondary" :disabled="dashboard.loading.value" @click="load"><AdminIcon name="clock" />تحديث</button></AdminPageHeader>
    <details class="overview-filters" :open="!!dashboard.error.value?.errors && Object.keys(dashboard.error.value.errors).length > 0"><summary><span><AdminIcon name="settings" />تصفية النظرة العامة</span><span class="muted" v-if="dashboard.data.value">{{ reportDate(dashboard.data.value.period.from) }} — {{ reportDate(dashboard.data.value.period.to) }}</span></summary><ReportFilters :model-value="filters" :errors="dashboard.error.value?.errors" report="dashboard" @apply="apply" /></details>
    <section v-if="contextState.error.value" class="panel report-error" role="alert">تعذر تحميل عملة المطعم: {{ contextState.error.value.message }} <button class="button secondary" @click="contextState.load">إعادة المحاولة</button></section>
    <AppSkeleton v-if="dashboard.loading.value" class="panel report-loading" variant="stats" label="جارٍ تحميل المؤشرات…"/>
    <section v-else-if="dashboard.error.value" class="panel report-error" role="alert">{{ dashboard.error.value.message }}<button class="button" @click="load">إعادة المحاولة</button></section>
    <template v-else-if="dashboard.data.value">
      <div class="stats overview-stats">
        <article v-for="[key, icon, note] in primaryCards" :key="key" class="stat" :data-metric="key"><div class="stat-heading"><span>{{ metricDefinitions[key][0] }}</span><span class="stat-icon"><AdminIcon :name="icon" /></span></div><strong>{{ reportValue(dashboard.data.value.summary[key],metricDefinitions[key][1],context) }}</strong><small>{{ note }}</small><small v-if="change(key)" class="overview-change">{{ change(key).trend === 'up' ? '↑' : change(key).trend === 'down' ? '↓' : '—' }} {{ change(key).percentage_change == null ? 'لا توجد قيمة سابقة للمقارنة' : reportValue(change(key).percentage_change,'percent') }} · السابق {{ reportValue(change(key).previous,metricDefinitions[key][1],context) }}</small></article>
      </div>
      <div class="two-columns overview-columns">
        <section v-if="auth.can('orders.view')" class="panel overview-orders">
          <div class="panel-head"><h2>آخر الطلبات</h2><RouterLink class="row-button" to="/admin/orders">كل الطلبات <AdminIcon name="arrow-left" /></RouterLink></div>
          <AppSkeleton v-if="recent.loading.value" variant="list" label="جارٍ تحميل الطلبات…"/><p v-else-if="recent.error.value" class="empty" role="alert">{{ recent.error.value.message }} <button class="row-button" @click="load">إعادة المحاولة</button></p>
          <div v-else-if="recent.data.value?.data.length" class="table-wrap"><table v-admin-table><thead><tr><th scope="col">الطلب</th><th scope="col">العميل</th><th scope="col">الاستلام</th><th scope="col">الإجمالي</th><th scope="col">الحالة</th><th scope="col"></th></tr></thead><tbody><tr v-for="order in recent.data.value.data" :key="order.id"><td><div class="admin-cell-value"><strong>{{ order.order_number }}</strong><small>{{ reportValue(order.placed_at,'datetime',context) }}</small></div></td><td><div class="admin-cell-value">{{ order.customer?.name || 'غير محدد' }}<small>{{ order.branch?.name }}</small></div></td><td><div class="admin-cell-value">{{ reportValue(order.type,'orderType') }}</div></td><td><div class="admin-cell-value">{{ reportValue(order.pricing?.total,'money',{ ...context, currency: order.pricing?.currency || context.currency }) }}</div></td><td><div class="admin-cell-value"><OrderStatusBadge :status="order.status" /></div></td><td><div class="admin-cell-value"><RouterLink class="row-button" :to="'/admin/orders/' + order.id">التفاصيل <AdminIcon name="arrow-left" /></RouterLink></div></td></tr></tbody></table></div><p v-else class="empty">لا توجد طلبات لهذه الفترة.</p>
        </section>
        <section class="panel overview-statuses"><div class="panel-head"><h2>حالة الطلبات</h2><AdminIcon name="shopping-bag" /></div><div v-for="row in statusRows" :key="row.status" class="bar-row"><div><span>{{ reportValue(row.status,'orderStatus') }}</span><strong>{{ reportValue(row.orders_count,'number') }}</strong></div><div class="bar"><span :style="{ width: barWidth(row.orders_count) }"></span></div></div><RouterLink v-if="auth.can('reports.orders.view')" class="button secondary" to="/admin/reports?report=order-statuses">تفاصيل الحالات <AdminIcon name="arrow-left" /></RouterLink></section>
      </div>
      <div class="two-columns overview-columns">
        <section v-if="auth.can('reports.sales.view')" class="panel overview-sales"><div class="panel-head"><div><h2>مبيعات الطلبات المكتملة</h2><small>حسب تاريخ إنشاء الطلب · {{ context.timezone }}</small></div><RouterLink class="row-button" to="/admin/reports?report=sales%2Ftrend">التقرير <AdminIcon name="arrow-left" /></RouterLink></div><AppSkeleton v-if="trend.loading.value" variant="list" label="جارٍ تحميل اتجاه المبيعات…"/><div v-else-if="trend.error.value" class="report-error" role="alert">{{ trend.error.value.message }} <button class="row-button" @click="load">إعادة المحاولة</button></div><template v-else-if="trend.data.value"><p v-if="trend.data.value.data.every(row => Number(row.completed_sales) === 0)" class="empty">لا توجد مبيعات مكتملة في هذه الفترة.</p><div v-else class="overview-trend"><div v-for="row in trend.data.value.data" :key="row.period" class="bar-row" :title="`${reportDate(row.period)}: ${reportValue(row.completed_sales,'money',context)}`"><div><span>{{ reportDate(row.period) }}</span><strong>{{ reportValue(row.completed_sales,'money',context) }}</strong></div><div class="bar"><span :style="{ width: Number(row.completed_sales) / salesMaximum * 100 + '%' }"></span></div></div></div><details class="overview-details"><summary>عرض جدول المبيعات</summary><ReportTable :rows="trend.data.value.data" :columns="salesDefinition.columns" :context="context" /></details></template></section>
        <section class="panel overview-types"><div class="panel-head"><h2>التوصيل والاستلام</h2><AdminIcon name="truck" /></div><div v-for="row in typeRows" :key="row.type" class="bar-row"><div><span>{{ reportValue(row.type,'orderType') }}</span><strong>{{ reportValue(row.orders_count,'number') }}</strong></div><div class="bar"><span :style="{ width: barWidth(row.orders_count) }"></span></div></div><div v-for="[key,value] in financialCards" :key="key" class="overview-financial" :data-metric="key"><span>{{ metricDefinitions[key][0] }}</span><strong>{{ reportValue(value,metricDefinitions[key][1],context) }}</strong><small v-if="key==='collected_revenue'">حسب وقت معالجة عمليات التحصيل الناجحة.</small><small v-if="change(key)">{{ change(key).percentage_change == null ? 'لا توجد قيمة سابقة للمقارنة' : reportValue(change(key).percentage_change,'percent') }} · السابق {{ reportValue(change(key).previous,metricDefinitions[key][1],context) }}</small></div></section>
      </div>
      <details class="panel overview-details"><summary>مؤشرات إضافية <AdminIcon name="layout-grid" /></summary><p v-if="dashboard.data.value.comparison?.period" class="report-note">المقارنة مع {{ reportDate(dashboard.data.value.comparison.period.from) }} — {{ reportDate(dashboard.data.value.comparison.period.to) }}</p><div class="table-wrap"><table v-admin-table><thead><tr><th scope="col">المؤشر</th><th scope="col">القيمة</th></tr></thead><tbody><tr v-for="[key,value] in extraMetrics" :key="key"><td><div class="admin-cell-value">{{ metricDefinitions[key][0] }}</div></td><td><div class="admin-cell-value">{{ reportValue(value,metricDefinitions[key][1],context) }}</div></td></tr></tbody></table></div><template v-if="dashboard.data.value.summary.inventory"><h2>المخزون الحالي</h2><p class="report-note">لقطة حالية مستقلة عن فترة الطلبات.</p><div class="table-wrap"><table v-admin-table><tbody><tr v-for="(value,key) in dashboard.data.value.summary.inventory" :key="key"><template v-if="metricDefinitions[key]"><th scope="row">{{ metricDefinitions[key][0] }}</th><td><div class="admin-cell-value">{{ reportValue(value,metricDefinitions[key][1],context) }}</div></td></template></tr></tbody></table></div></template></details>
    </template>
  </main>
</template>
<style scoped>
.overview-filters{margin-bottom:22px;border-bottom:1px solid var(--line);padding-bottom:12px}.overview-filters>summary{display:flex;justify-content:space-between;gap:12px;cursor:pointer;font-size:12px;color:var(--primary)}.overview-filters>summary>span:first-child{display:flex;align-items:center;gap:8px}.overview-filters .report-filter-panel{margin-top:16px;margin-bottom:0}.overview-stats{grid-template-columns:repeat(4,minmax(0,1fr))}.overview-stats .stat{min-width:0}.overview-stats .stat strong{font-size:27px;overflow-wrap:anywhere}.overview-stats .stat small{display:block}.overview-change{margin-top:7px;color:var(--primary)}.overview-columns{align-items:start}.overview-columns>.panel{min-width:0}.overview-orders td small{display:block;font-size:10px;margin-top:4px}.overview-orders table{min-width:600px}.overview-orders td:first-child strong{font-size:12px}.overview-statuses .button{width:100%;margin-top:14px}.overview-trend{max-height:300px;overflow-y:auto;scrollbar-width:thin}.overview-details>summary{cursor:pointer;color:var(--primary);font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:space-between;min-height:28px}.overview-details[open]>summary{margin-bottom:18px}.overview-details .report-table{padding:0;border:0;box-shadow:none;margin:0}.overview-details h2{margin-top:22px}.overview-financial{display:grid;grid-template-columns:1fr auto;gap:5px;margin-top:20px;padding-top:16px;border-top:1px solid var(--line);font-size:12px}.overview-financial strong{color:var(--primary)}.overview-financial small{grid-column:1/-1}.bar-row strong{font-size:12px}.overview-stats .stat-heading{min-height:40px}.overview-filters .muted{font-size:11px}@media(max-width:1100px){.overview-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-columns{grid-template-columns:1fr}}@media(max-width:600px){.overview-stats .stat strong{font-size:23px}.overview-filters>summary{flex-wrap:wrap}.overview-stats .stat-heading{min-height:32px}.overview-orders .panel-head{gap:8px}.overview-financial{grid-template-columns:1fr}.overview-filters .icon{width:17px;height:17px}}
</style>
