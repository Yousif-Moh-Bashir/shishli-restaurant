<script setup>
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AdminOrderPeriodFilter from '../../components/orders/AdminOrderPeriodFilter.vue'
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import AdminIcon from '../../components/admin/AdminIcon.vue'
import { reactive,ref,computed,watch,nextTick,onMounted,onBeforeUnmount } from 'vue'
import { useRoute,useRouter,onBeforeRouteLeave } from 'vue-router'
import { adminOrdersApi } from '../../api/orders.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { useOrderBranches } from '../../composables/useOrderBranches'
import { useAuthStore } from '../../stores/auth'
import OrderStatusBadge from '../../components/orders/OrderStatusBadge.vue'
import KitchenCard from '../../components/orders/KitchenCard.vue'
import AdminOrderOperations from '../../components/orders/AdminOrderOperations.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import { normalizeApiError } from '../../utils/apiError'
import OrderNumber from '../../components/orders/OrderNumber.vue'
import { formatDateTime } from '../../utils/formatters'
const auth=useAuthStore(),{branches,error:branchError,loading:branchesLoading}=useOrderBranches(),filters=reactive({branch:'',status:'',type:'',period:'current',date_from:'',date_to:''}),page=ref(1),active=ref(false),focused=ref(null),focusError=ref(null),focusLoading=ref(false),clock=ref(Date.now())
const route=useRoute(),router=useRouter()
const actionsDialog=ref(null),actionsOpen=ref(false)
let applied={},tick,revision=0
const state=useOrderPolling(signal=>adminOrdersApi.kitchen({...applied,page:page.value,per_page:20},signal),{interval:15000,enabled:()=>auth.can('orders.view'),paused:()=>actionsOpen.value||active.value||focusLoading.value})
const {data,loading,refreshing,error,syncedAt}=state
const stages=[{value:'',label:'قيد التجهيز'},{value:'confirmed',label:'طلبات مؤكدة'},{value:'preparing',label:'جاري التحضير'},{value:'ready',label:'جاهزة'}]
const currentStage=computed(()=>stages.find(stage=>stage.value===(route.query.status||''))?.label||'قيد التجهيز')
function setStage(value){filters.status=value;load()}
function clearFilters(){filters.branch='';filters.type='';filters.status='';filters.period='current';filters.date_from=filters.date_to='';load()}
function selectPeriod(value){filters.period=value;filters.date_from=filters.date_to='';if(value!=='range')load()}
function load(next=1){const values=next===1?{...filters}:applied;void router.replace({query:{...Object.fromEntries(Object.entries(values).filter(([,value])=>value)),page:String(next)}})}
watch(()=>route.query,query=>{filters.branch=typeof query.branch==='string'?query.branch:'';filters.status=['confirmed','preparing','ready'].includes(query.status)?query.status:'';filters.type=['pickup','delivery'].includes(query.type)?query.type:'';filters.period=['current','history','range'].includes(query.period)?query.period:'current';filters.date_from=typeof query.date_from==='string'?query.date_from:'';filters.date_to=typeof query.date_to==='string'?query.date_to:'';applied={...filters};page.value=Math.max(1,Math.min(100000,Number.parseInt(query.page,10)||1));state.reset();if(auth.can('orders.view'))void state.load()},{immediate:true})
async function focus(id){if(active.value||focusLoading.value)return;const current=++revision;focusLoading.value=true;focusError.value=null;focused.value=null;actionsOpen.value=true;await nextTick();if(current!==revision)return;actionsDialog.value?.showModal();try{const order=await adminOrdersApi.show(id);if(current===revision)focused.value=order}catch(cause){if(current===revision)focusError.value=normalizeApiError(cause)}finally{if(current===revision)focusLoading.value=false}}
function closeFocus(){if(active.value||!actionsOpen.value)return;revision++;actionsOpen.value=false;actionsDialog.value?.close();focused.value=null;focusError.value=null;focusLoading.value=false}
async function reload(){if(focused.value){const id=focused.value.id;const current=revision;try{const value=await adminOrdersApi.show(id);if(current===revision)focused.value=value}catch(cause){if(current===revision){focusError.value=normalizeApiError(cause);if([401,403,404].includes(focusError.value.status))focused.value=null}}}await state.load(true,true)}
function elapsed(order){const date=Date.parse(order.confirmed_at||order.placed_at);return Number.isFinite(date)?Math.max(0,Math.floor((clock.value-date)/60000))+' دقيقة':'غير متاح'}
watch(()=>auth.token,()=>{revision++;actionsOpen.value=false;actionsDialog.value?.close();focused.value=null;focusError.value=null;focusLoading.value=false;active.value=false;state.reset();if(auth.can('orders.view'))void state.load()},{immediate:true})
onMounted(()=>{tick=setInterval(()=>{clock.value=Date.now()},60000)})
onBeforeRouteLeave(()=>!active.value)
onBeforeUnmount(()=>{revision++;actionsDialog.value?.close();clearInterval(tick)})
</script>
<template>
  <main id="main" class="kitchen-page">
    <AdminPageHeader class="page-heading"><div><span class="eyebrow">تشغيل شيشلي</span><h1>المطبخ</h1><p>الطلبات الأقدم أولًا، من التأكيد حتى الجاهزية.</p></div><button class="button kitchen-refresh" :disabled="refreshing||active||focusLoading" @click="state.load(true)"><AdminIcon name="refresh"/> تحديث القائمة</button></AdminPageHeader>
    <section class="panel kitchen-filter-panel" aria-label="تصفية طلبات المطبخ">
      <div class="kitchen-stage-tabs" role="group" aria-label="مرحلة التجهيز"><button v-for="stage in stages" :key="stage.value" type="button" :aria-pressed="(route.query.status||'')===stage.value" :disabled="refreshing||active||focusLoading" @click="setStage(stage.value)">{{ stage.label }}</button></div>
      <form class="order-filter-grid" @submit.prevent="load()"><AdminOrderPeriodFilter :period="filters.period" v-model:date-from="filters.date_from" v-model:date-to="filters.date_to" :busy="refreshing||active||focusLoading" @select="selectPeriod"/><label class="field">الفرع<select v-model="filters.branch" name="kitchen_branch" :disabled="active||focusLoading"><option value="">كل الفروع</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label><label class="field">نوع الطلب<select v-model="filters.type" name="kitchen_type" :disabled="active||focusLoading"><option value="">كل الأنواع</option><option value="pickup">استلام</option><option value="delivery">توصيل</option></select></label><div class="kitchen-filter-actions"><button class="button" :disabled="refreshing||active||focusLoading">تطبيق الفلاتر</button><button v-if="route.query.branch||route.query.type||route.query.status" class="row-button" type="button" :disabled="refreshing||active||focusLoading" @click="clearFilters">مسح الفلاتر</button></div></form>
      <AppSkeleton v-if="branchesLoading" variant="inline" label="جارٍ تحميل الفروع…"/><p v-if="branchError" role="alert">{{ branchError.message }}</p>
    </section>
    <div class="kitchen-queue-heading"><div><h2>{{ currentStage }}</h2><span v-if="data" class="kitchen-queue-count">{{ data.meta?.total ?? data.data.length }} طلب في القائمة</span></div><p class="kitchen-sync-note"><AdminIcon name="refresh"/><span>{{ actionsOpen||active||focusLoading?'التحديث مؤقتًا متوقف أثناء الإجراء':'تحديث تلقائي كل 15 ثانية' }}<small v-if="syncedAt">آخر مزامنة: {{ formatDateTime(syncedAt) }}</small></span></p></div>
    <div v-if="error" class="panel" role="alert"><p>{{ error.message }}</p><p v-if="data">القائمة من آخر مزامنة ناجحة.</p><button class="row-button" @click="state.load()">إعادة المحاولة</button></div>
    <AppSkeleton v-if="refreshing&&!loading" variant="inline" label="جارٍ تحديث البيانات…"/><AppSkeleton v-if="loading" variant="cards" label="جارٍ تحميل قائمة المطبخ…"/>
    <div v-if="data" class="kitchen-queue"><KitchenCard v-for="order in data.data" :key="order.id" :order="order" :elapsed="elapsed(order)" :disabled="active||focusLoading" @focus="focus"/></div>
    <div v-if="data&&!data.data.length" class="panel kitchen-empty"><AdminIcon name="utensils"/><h3>لا توجد طلبات في هذه القائمة</h3><p>ستظهر الطلبات المطابقة هنا عند تحديث القائمة.</p><button v-if="route.query.branch||route.query.type||route.query.status" class="row-button" type="button" @click="clearFilters">عرض الطلبات قيد التجهيز</button></div>
    <dialog ref="actionsDialog" class="info-dialog kitchen-actions" aria-labelledby="kitchen-actions-title" @cancel.prevent="event=>{if(event.target===actionsDialog)closeFocus()}" @close="closeFocus">
      <div class="kitchen-focused-heading"><h2 id="kitchen-actions-title">إجراءات الطلب</h2><button class="row-button" type="button" :disabled="active" aria-label="إغلاق إجراءات الطلب" @click="closeFocus"><AdminIcon name="x"/> إغلاق</button></div>
      <AppSkeleton v-if="focusLoading" variant="list" label="جارٍ تحميل الإجراءات المسموحة…"/><p v-if="focusError" role="alert">{{ focusError.message }}</p>
      <template v-if="focused"><div class="kitchen-focused-heading"><OrderNumber :number="focused.order_number"/><OrderStatusBadge :status="focused.status"/></div><AdminOrderOperations :order="focused" :reload="reload" @active="active=$event"/></template>
    </dialog>
    <ApiPagination v-if="data" :meta="data.meta" :links="data.links" :busy="refreshing||active" @page="load"/>
  </main>
</template>
<style scoped>
.kitchen-filter-panel{padding:20px;background:#fff;border-radius:12px}
.kitchen-stage-tabs{display:flex;gap:8px;flex-wrap:wrap;padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid var(--line)}
.kitchen-stage-tabs button{padding:9px 18px;border:1px solid var(--line);background:#fff;border-radius:8px;font-size:13px;color:var(--muted);min-height:40px}
.kitchen-stage-tabs button[aria-pressed=true]{background:#d71920;border-color:#d71920;color:#fff}
.order-filter-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:16px;align-items:end}
.order-filter-grid .field{margin:0;min-width:0;font-size:12px}.order-filter-grid select{width:100%;background:#fff}
.kitchen-filter-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.kitchen-filter-actions .row-button{margin:0;min-height:44px}.kitchen-filter-actions .button,.kitchen-refresh{background:#d71920;border-color:#d71920;color:#fff}
.kitchen-queue-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-block:26px 18px}
.kitchen-queue-heading>div{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.kitchen-queue-heading h2{margin:0;font-size:21px}.kitchen-queue-count{color:var(--muted);font-size:12px}
.kitchen-sync-note{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:11px;margin:0}.kitchen-sync-note .icon{width:15px;height:15px}.kitchen-sync-note small{display:block;font-size:10px;margin-top:3px}
.kitchen-queue{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:20px}
.kitchen-empty{text-align:center;padding:40px 20px;background:#fff;border:1px dashed var(--line);border-radius:12px}.kitchen-empty>.icon{width:36px;height:36px;color:var(--muted);margin-bottom:12px}.kitchen-empty h3{font-size:17px;margin-bottom:8px}.kitchen-empty p{font-size:13px;color:var(--muted)}
.kitchen-actions{background:#fff;border:1px solid var(--line);border-radius:12px;margin-top:24px}.kitchen-focused-heading{display:flex;align-items:start;justify-content:space-between;gap:12px;margin-bottom:20px}
@media(max-width:900px){.order-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.kitchen-filter-actions{grid-column:1/-1}}
@media(max-width:600px){.kitchen-filter-panel{padding:16px}.kitchen-stage-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.kitchen-stage-tabs button{padding-inline:10px}.order-filter-grid{grid-template-columns:minmax(0,1fr);gap:12px}.kitchen-filter-actions>.button{flex:1}.kitchen-queue-heading{align-items:start;flex-direction:column;gap:8px;margin-top:22px}.kitchen-queue{gap:16px}}
</style>
