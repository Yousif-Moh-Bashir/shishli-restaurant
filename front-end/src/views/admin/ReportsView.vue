<script setup>
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import AdminIcon from '../../components/admin/AdminIcon.vue'
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useApiState } from '../../composables/useApiState'
import { useReportContext } from '../../composables/useReportContext'
import { reportsApi, reportParams } from '../../api/reports.api'
import { normalizeApiError } from '../../utils/apiError'
import { reportDate, reportValue } from '../../utils/reportFormatters'
import { reportDefinitions, metricDefinitions, orderReportChartColumns, inventoryReportChartMetrics } from '../../constants/reports'
import ReportFilters from '../../components/reports/ReportFilters.vue'
import ReportMetrics from '../../components/reports/ReportMetrics.vue'
import ReportChart from '../../components/reports/ReportChart.vue'
import ReportOrdersChart from '../../components/reports/ReportOrdersChart.vue'
import ReportTable from '../../components/reports/ReportTable.vue'
import '../../assets/css/reports.css'
const route = useRoute(), router = useRouter(), auth = useAuthStore()
const contextState = useReportContext(), state = useApiState()
const available = computed(() => reportDefinitions.filter(report => auth.can(report.permission)))
const definition = computed(() => reportDefinitions.find(report => report.key === route.query.report) || available.value[0])
const allowed = computed(() => definition.value && auth.can(definition.value.permission))
const filters = computed(() => ({ date_from: '', date_to: '', branch_uuid: '', order_type: '', order_status: '', payment_method: '', group_by: 'day', sort_by: 'quantity', per_page: 20, page: 1, ...reportParams(route.query, definition.value?.key), low_stock: route.query.low_stock === '1', out_of_stock: route.query.out_of_stock === '1' }))
const context = computed(() => ({ ...contextState.data.value?.restaurant, timezone: state.data.value?.period?.timezone || contextState.data.value?.restaurant?.timezone }))
const selectedMetric=ref('completed_sales')
const salesMetrics=computed(()=>(definition.value?.key==='orders'?orderReportChartColumns:definition.value?.columns||[]).filter(column=>column.type==='money'||column.type==='number'))
watch(()=>definition.value?.key,()=>{selectedMetric.value=definition.value?.key==='orders'?'orders_count':definition.value?.chart?.[1]}, {immediate:true})
const chartColumns = computed(() => {
 if(definition.value?.key==='inventory')return [{key:'label',type:'text'},{key:'value',type:'number',label:'العدد'}]
 const columns=definition.value?.key==='orders'?orderReportChartColumns:definition.value?.columns
 const labelKey=definition.value?.key==='orders'?'status':definition.value?.chart?.[0]
 return labelKey?[columns.find(column=>column.key===labelKey),columns.find(column=>column.key===selectedMetric.value)]:null
})
const chartRows=computed(()=>{
 const data=state.data.value
 if(!data)return []
 if(definition.value.key==='inventory')return inventoryReportChartMetrics.filter(key=>data[key]!=null).map(key=>({label:metricDefinitions[key][0],value:data[key]}))
 if(definition.value.key==='orders'){
  const groups=new Map()
  for(const row of data.data||[]){const group=groups.get(row.status)||{status:row.status,orders_count:0,total:0};group.orders_count++;group.total+=Number(row.total)||0;groups.set(row.status,group)}
  return [...groups.values()]
 }
 return data.data||[]
})
const exportBusy = ref(false), exportError = ref(null)
const reportSummary=computed(()=>{
 const result=state.data.value
 if(definition.value?.key!=='sales/trend')return definition.value?.key==='inventory'?result:result?.summary
 const rows=result?.data||[]
 const orders=rows.reduce((total,row)=>total+Number(row.orders_count||0),0)
 const completed=rows.reduce((total,row)=>total+Number(row.completed_orders_count||0),0)
 const salesMinor=rows.reduce((total,row)=>total+Math.round(Number(row.completed_sales||0)*100),0)
 return {orders_count:orders,completed_orders_count:completed,completed_sales:(salesMinor/100).toFixed(2),average_order_value:(completed?salesMinor/completed/100:0).toFixed(2)}
})
let exportController, exportRevision = 0
function cancelExport() { exportRevision++; exportController?.abort(); exportBusy.value = false; exportError.value = null }
function load() { return allowed.value ? state.run(signal => reportsApi.get(definition.value.key, filters.value, signal)) : state.clear() }
function apply(value) { const query = { report: definition.value.key, ...reportParams(value, definition.value.key) }; if (JSON.stringify(query) === JSON.stringify(route.query)) load(); else router.replace({ query }) }
function tabQuery(key) { return { report: key, ...reportParams({ ...filters.value, page: 1 }, key) } }
async function download() {
  if (exportBusy.value || !allowed.value || !definition.value.export || !auth.can('reports.export') || !state.data.value) return
  exportController = new AbortController()
  const revision = ++exportRevision, key = definition.value.key
  const query = { ...filters.value, date_from: state.data.value.period.from, date_to: state.data.value.period.to }
  exportBusy.value = true; exportError.value = null
  try {
    const result = await reportsApi.export(key, query, exportController.signal)
    if (revision !== exportRevision) return
    const url = URL.createObjectURL(result.blob), anchor = document.createElement('a')
    anchor.href = url; anchor.download = result.filename; document.body.append(anchor); anchor.click(); anchor.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (cause) { if (revision === exportRevision && cause.code !== 'ERR_CANCELED') exportError.value = normalizeApiError(cause) }
  finally { if (revision === exportRevision) exportBusy.value = false }
}
watch(() => route.fullPath, () => { cancelExport(); load() }, { immediate: true })
onBeforeUnmount(cancelExport)
</script>
<template>
  <main id="main" class="reports-page" :aria-busy="state.loading.value">
    <AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>التقارير</h1><p>تقارير المطعم حسب التاريخ والفرع والفلاتر المتاحة.</p></div><button class="button secondary" :disabled="state.loading.value" @click="load"><AdminIcon name="refresh"/> تحديث</button></AdminPageHeader>
    <nav class="report-tabs" aria-label="أنواع التقارير"><RouterLink v-for="report in available" :key="report.key" :to="{ path: '/admin/reports', query: tabQuery(report.key) }" :aria-current="definition?.key === report.key ? 'page' : undefined">{{ report.label }}</RouterLink></nav>
    <p v-if="!allowed" class="panel" role="alert">ليس لديك صلاحية لهذا التقرير.</p>
    <template v-else>
      <ReportFilters :model-value="filters" :errors="state.error.value?.errors" :report="definition.key" @apply="apply" />
      <p v-if="definition.note" class="report-note">{{ definition.note }}</p>
      <section v-if="contextState.error.value" class="panel report-error" role="alert">تعذر تحميل عملة المطعم: {{ contextState.error.value.message }} <button class="button secondary" @click="contextState.load">إعادة المحاولة</button></section>
      <AppSkeleton v-if="state.loading.value" class="panel report-loading" variant="table" label="جارٍ تحميل التقرير…"/>
      <section v-else-if="state.error.value" class="panel report-error" role="alert">{{ state.error.value.message }}<ul v-if="Object.keys(state.error.value.errors || {}).length"><li v-for="(messages, field) in state.error.value.errors" :key="field">{{ messages.join(' ') }}</li></ul><button class="button" @click="load">إعادة المحاولة</button></section>
      <template v-else-if="state.data.value">
        <div class="panel-head"><p v-if="state.data.value.period" class="muted">{{ reportDate(state.data.value.period.from) }} — {{ reportDate(state.data.value.period.to) }} · {{ context.timezone }}</p><p v-else class="muted">حتى {{ reportValue(state.data.value.as_of, 'datetime', context) }}</p><button v-if="definition.export && auth.can('reports.export')" class="button" :disabled="exportBusy" @click="download"><AdminIcon name="arrow-up-left"/> {{ exportBusy ? 'جارٍ التصدير…' : 'تصدير CSV' }}</button></div>
        <section v-if="exportError" class="panel report-error" role="alert">{{ exportError.message }}<p v-for="(messages,field) in exportError.errors" :key="field">{{ messages.join(' ') }}</p><p v-if="exportError.retryAfter">أعد المحاولة بعد {{ exportError.retryAfter }} ثانية.</p></section>
        <ReportMetrics :summary="reportSummary" :context="context" />
        <div v-if="definition.key==='sales/trend'" class="report-sales-charts">
          <div>
            <label class="field report-chart-metric">مؤشر الرسم<select v-model="selectedMetric"><option v-for="metric in salesMetrics" :key="metric.key" :value="metric.key">{{ metric.label }}</option></select></label>
            <ReportChart :rows="chartRows" :label-key="chartColumns[0].key" :label-type="chartColumns[0].type" :value-key="chartColumns[1].key" :value-type="chartColumns[1].type" :context="context" title="اتجاه المبيعات" trend :metric-label="chartColumns[1].label" />
          </div>
          <ReportOrdersChart :rows="chartRows" :context="context" />
        </div>
        <div v-else-if="chartColumns">
          <p v-if="chartColumns && state.data.value.meta" class="report-note">الرسم يعرض الصفوف في الصفحة الحالية بترتيب الخادم.</p>
          <p v-if="definition.key==='orders'" class="report-note">الطلبات مجمعة حسب الحالة من نتائج الصفحة الحالية؛ القيمة تشمل حالات الطلبات المعروضة ولا تمثل التحصيل المالي.</p>
          <p v-if="definition.key==='inventory'" class="report-note">مؤشرات مستقلة من لقطة المخزون الحالية؛ المخزون المنخفض والنافد قد يتداخلان، ولا تُجمع هذه الأعمدة.</p>
          <label v-if="salesMetrics.length" class="field report-chart-metric">مؤشر الرسم<select v-model="selectedMetric"><option v-for="metric in salesMetrics" :key="metric.key" :value="metric.key">{{ metric.label }}</option></select></label>
          <ReportChart :rows="chartRows" :label-key="chartColumns[0].key" :label-type="chartColumns[0].type" :value-key="chartColumns[1].key" :value-type="chartColumns[1].type" :context="context" :title="definition.key==='sales/trend'?'اتجاه المبيعات':definition.label" :trend="definition.key==='sales/trend'" :metric-label="chartColumns[1].label" />
        </div>
        <template v-if="definition.key !== 'inventory'">
          <details v-if="definition.key==='sales/trend'" class="report-data-disclosure"><summary>عرض البيانات</summary><ReportTable :rows="state.data.value.data" :columns="definition.columns" :context="context" /></details>
          <ReportTable v-else :rows="state.data.value.data" :columns="definition.columns" :context="context" :meta="state.data.value.meta" :branch-drilldown="definition.key === 'branches'" @page="page => apply({ ...filters, page })" @branch="branch_uuid => apply({ ...filters, branch_uuid, page: 1 })" />
        </template>
      </template>
    </template>
  </main>
</template>
