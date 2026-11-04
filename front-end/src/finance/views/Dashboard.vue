<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { financeApi } from '../api'
import { useFinanceSession } from '../session'
import { currency, errorMessage, today } from '../utils'
import FinanceFilters from '../components/FinanceFilters.vue'
const session = useFinanceSession(), report = ref(null), loading = ref(false), exporting = ref(false), error = ref('')
const filters = ref({ from: today().slice(0, 7) + '-01', to: today(), branch_uuid: '', source_uuid: '', group_by: 'day' })
const applied = ref({})
const route = useRoute(), allTrend = ref(false)
const reportPage = computed(() => route.path === '/reports')
const quickActions = computed(() => [
  { to: '/revenues', label: 'تسجيل إيرادات', icon: 'wallet', permission: 'revenues.view' },
  { to: '/expenses', label: 'تسجيل مصروف', icon: 'arrow-up-left', permission: 'expenses.view' },
  { to: '/purchases', label: 'فاتورة مشتريات', icon: 'shopping-bag', permission: 'purchases.view' },
].filter(action => session.can(action.permission) && session.can(action.permission.replace('.view', '.create'))))
const visibleTrend = computed(() => allTrend.value ? report.value?.periods || [] : (report.value?.periods || []).slice(-7))
const scope = computed(() => ({
  branch: report.value?.scope?.branch || session.branches.find(b => b.uuid === applied.value.branch_uuid)?.name || 'كل الفروع المسموحة',
  source: report.value?.scope?.source || session.sources.find(s => s.uuid === applied.value.source_uuid)?.name || 'كل مصادر الإيراد',
}))
const rankedSources = computed(() => [...(report.value?.sources || [])].sort((a, b) => Number(b.amount) - Number(a.amount)))
const sourceShare = amount => Number(report.value?.totals.revenues) > 0 ? Math.min(100, Number(amount) / Number(report.value.totals.revenues) * 100) : 0
const max = computed(() => Math.max(1, ...(report.value?.periods || []).map(p => Number(p.revenues))))
const change = computed(() => { const previous = Number(report.value?.previous.revenues || 0), current = Number(report.value?.totals.revenues || 0); return previous === 0 ? null : ((current - previous) / previous * 100).toFixed(1) })
let sequence = 0
async function load() {
  const id = ++sequence; loading.value = true; error.value = ''
  const params = { ...filters.value }
  try { const result = await financeApi.report(params); if (id === sequence) { report.value = result; applied.value = params; allTrend.value = false } }
  catch (e) { if (id === sequence) error.value = errorMessage(e) }
  finally { if (id === sequence) loading.value = false }
}
async function download(format) {
  if (exporting.value || !['pdf', 'xlsx'].includes(format)) return
  exporting.value = true; error.value = ''
  try { await financeApi.download(`reports/export/${format}`, `shishli-finance.${format}`, applied.value) }
  catch (e) { error.value = e.code === 'EXPORT_FORMAT_MISMATCH' ? 'صيغة التقرير المستلم لا تطابق الصيغة المطلوبة. لم يتم تنزيل الملف؛ حاول مجددًا.' : errorMessage(e) }
  finally { exporting.value = false }
}
onMounted(load)
</script>
<template>
<div class="finance-dashboard">
  <div class="page-intro"><div><span class="eyebrow">الإدارة المالية الداخلية</span><h2>{{ reportPage ? 'تقاريرك المالية بوضوح' : `أهلًا، ${session.user?.name || ''}` }}</h2><p class="muted">نظرة على الإيرادات والمدفوعات المسجلة، لتتابع يومك وتراجع الفترة بثقة.</p></div><div class="actions" v-if="session.can('reports.export')"><button type="button" :disabled="!report || loading || exporting" @click.stop.prevent="download('xlsx')"><FinanceIcon name="upload" class="download-icon" />Excel</button><button type="button" :disabled="!report || loading || exporting" @click.stop.prevent="download('pdf')"><FinanceIcon name="upload" class="download-icon" />PDF</button></div></div>
  <div v-if="!reportPage && quickActions.length" class="dashboard-quick-actions" aria-label="إجراءات مالية سريعة"><RouterLink v-for="action in quickActions" :key="action.to" :to="action.to"><FinanceIcon :name="action.icon" /><span>{{ action.label }}</span><FinanceIcon name="plus" /></RouterLink></div>
  <FinanceFilters v-model="filters" :title="reportPage ? 'تصفية التقارير' : 'تصفية النظرة العامة'" sources periods @apply="load"/>
  <p class="notice danger" v-if="error" role="alert">{{ error }} <button @click="load">إعادة المحاولة</button></p>
  <div v-if="loading" class="loading card" role="status">جارٍ إعداد الملخص المالي…</div>
  <template v-else-if="report">
    <div class="dashboard-scope"><span><FinanceIcon name="store" />{{ scope.branch }}</span><span>{{ scope.source }}</span><span class="badge approved">الإيرادات المعتمدة فقط</span></div>
    <section class="stats-grid dashboard-current-stats" aria-label="الإيرادات الحالية">
      <article class="stat card"><div><span>إيرادات اليوم</span><span class="stat-symbol"><FinanceIcon name="wallet" /></span></div><strong>{{ currency(report.today.revenues) }}</strong><small>{{ today() }}</small></article>
      <article class="stat card"><div><span>إيرادات الشهر الحالي</span><span class="stat-symbol"><FinanceIcon name="history" /></span></div><strong>{{ currency(report.month.revenues) }}</strong><small>من أول الشهر إلى اليوم</small></article>
    </section>
    <section class="card dashboard-period-overview" aria-labelledby="period-overview-title">
      <div class="dashboard-period-heading"><div><span class="eyebrow">الفترة المختارة</span><h3 id="period-overview-title">ملخص الحركة المالية</h3><p class="muted">{{ report.filters.from }} — {{ report.filters.to }}</p></div><FinanceIcon name="chart-column" /></div>
      <div class="dashboard-period-metrics"><div><span>الإيرادات المعتمدة</span><strong>{{ currency(report.totals.revenues) }}</strong></div><div><span>المصروفات المدفوعة</span><strong>{{ currency(report.totals.expenses) }}</strong></div><div><span>المشتريات المدفوعة</span><strong>{{ currency(report.totals.purchases) }}</strong></div></div>
      <div class="dashboard-net-flow"><div><span>صافي التدفق المسجل</span><small>الإيرادات − المصروفات − المشتريات</small></div><strong :class="{ negative: Number(report.totals.net_flow) < 0 }">{{ currency(report.totals.net_flow) }}</strong></div>
      <details class="dashboard-basis"><summary>كيف يُحسب هذا الملخص؟</summary><p>{{ report.basis }}</p></details>
    </section>
    <div class="dashboard-grid dashboard-insights">
      <section class="card trend-panel"><div class="panel-heading"><div><h3>اتجاه الإيرادات</h3><p class="muted">{{ allTrend ? 'كل الفترات المسجلة' : 'آخر 7 فترات مسجلة ضمن الاختيار' }}</p></div><span class="badge approved">معتمد</span></div>
        <div v-if="!report.periods.length" class="empty">لا توجد بيانات ضمن الفترة. ابدأ بتسجيل إيراداتك اليومية.</div>
        <div v-else class="dashboard-trend-list" role="list" aria-label="اتجاه الإيرادات حسب الفترة"><div v-for="row in visibleTrend" :key="row.period" class="dashboard-trend-row" role="listitem" :aria-label="`${row.period}: ${currency(row.revenues)}`"><div><span>{{ row.period }}</span><strong>{{ currency(row.revenues) }}</strong></div><div class="dashboard-trend-track" aria-hidden="true"><span :style="{ width: `${Number(row.revenues) > 0 ? Math.max(2, Number(row.revenues) / max * 100) : 0}%` }"/></div></div></div>
        <button v-if="report.periods.length > 7" class="dashboard-expand" @click="allTrend = !allTrend">{{ allTrend ? 'عرض آخر 7 فترات' : 'عرض جميع الفترات' }}<FinanceIcon name="chevron-down" /></button>
      </section>
      <section class="card comparison dashboard-comparison"><span class="eyebrow">مقارنة الفترات</span><h3>مقابل الفترة السابقة</h3><p class="muted">{{ report.previous.from }} — {{ report.previous.to }}</p><strong class="comparison-value" :class="Number(change) < 0 ? 'negative' : ''">{{ change === null ? '—' : `${Number(change) > 0 ? '+' : ''}${change}%` }}</strong><p>{{ change === null ? 'لا توجد إيرادات سابقة لحساب نسبة التغير.' : 'التغير في الإيرادات المعتمدة' }}</p><div class="comparison-row"><span>الفترة الحالية</span><b>{{ currency(report.totals.revenues) }}</b></div><div class="comparison-row"><span>الفترة السابقة</span><b>{{ currency(report.previous.revenues) }}</b></div></section>
    </div>
    <section class="card dashboard-sources"><div class="panel-heading"><div><h3>من أين تأتي الإيرادات؟</h3><p class="muted">المصادر مرتبة حسب الإيراد في الفترة المختارة.</p></div><FinanceIcon name="wallet" /></div><div v-if="!rankedSources.length" class="empty">لا توجد إيرادات معتمدة لهذه الفترة.</div><div v-else class="dashboard-source-list"><div v-for="source in rankedSources" :key="source.uuid" class="dashboard-source-row"><div><strong>{{ source.name }}</strong><b>{{ currency(source.amount) }}</b></div><div class="dashboard-trend-track" aria-hidden="true"><span :style="{ width: `${sourceShare(source.amount)}%` }"/></div><small>{{ sourceShare(source.amount).toFixed(1) }}% من الإيرادات المعتمدة</small></div></div></section>
    <section class="card"><div class="panel-heading"><h3>ملخص الفترات</h3><span class="muted">العملة: ريال سعودي</span></div><div class="table-wrap finance-responsive-wrap"><table class="finance-responsive-table" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader">الفترة</th><th role="columnheader">الإيرادات المعتمدة</th><th role="columnheader">المصروفات</th><th role="columnheader">المشتريات</th><th role="columnheader">صافي التدفق المسجل</th></tr></thead><tbody role="rowgroup"><tr v-for="row in report.periods" :key="row.period" role="row"><td role="cell"><span class="finance-cell-label" aria-hidden="true">الفترة</span><div class="finance-cell-value">{{ row.period }}</div></td><td class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">الإيرادات المعتمدة</span><div class="finance-cell-value">{{ currency(row.revenues) }}</div></td><td class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">المصروفات</span><div class="finance-cell-value">{{ currency(row.expenses) }}</div></td><td class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">المشتريات</span><div class="finance-cell-value">{{ currency(row.purchases) }}</div></td><td class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">صافي التدفق المسجل</span><div class="finance-cell-value">{{ currency(row.net_flow) }}</div></td></tr><tr class="total-table" role="row"><th role="cell"><span class="finance-cell-label" aria-hidden="true">الفترة</span><div class="finance-cell-value">الإجمالي</div></th><th class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">الإيرادات المعتمدة</span><div class="finance-cell-value">{{ currency(report.totals.revenues) }}</div></th><th class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">المصروفات</span><div class="finance-cell-value">{{ currency(report.totals.expenses) }}</div></th><th class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">المشتريات</span><div class="finance-cell-value">{{ currency(report.totals.purchases) }}</div></th><th class="finance-cell-amount" role="cell"><span class="finance-cell-label" aria-hidden="true">صافي التدفق المسجل</span><div class="finance-cell-value">{{ currency(report.totals.net_flow) }}</div></th></tr></tbody></table></div></section>
  </template>
</div>
</template>
