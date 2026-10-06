<script setup>
import { computed } from 'vue'
import AdminIcon from '../admin/AdminIcon.vue'
import AdminSectionHeading from '../admin/AdminSectionHeading.vue'
import OrderNumber from './OrderNumber.vue'
import OrderStatusBadge from './OrderStatusBadge.vue'
import KitchenSnapshot from './KitchenSnapshot.vue'
import { getOrderTypeLabel } from '../../constants/orderStatus'
import { formatDateTime } from '../../utils/formatters'
const props=defineProps({order:{type:Object,required:true},syncedAt:[String,Number,Date]})
const quantity=computed(()=>(props.order.items||[]).reduce((total,item)=>total+Number(item.quantity||0),0))
const confirmedAt=computed(()=>props.order.timeline?.confirmed_at||props.order.confirmed_at)
</script>
<template>
  <div class="kitchen-detail-layout">
    <section class="kitchen-preparation" aria-label="الأطباق وتفاصيل التجهيز">
      <div class="panel kitchen-preparation-heading"><div><span class="kitchen-detail-kicker">قائمة التجهيز</span><h2>الأطباق المطلوبة</h2></div><div class="kitchen-detail-count"><strong>{{ quantity }}</strong><span>قطعة في {{ order.items?.length||0 }} أصناف</span></div></div>
      <KitchenSnapshot :order="order"/>
    </section>
    <aside class="panel kitchen-detail-summary" aria-label="ملخص الطلب وإجراءات المطبخ">
      <div class="kitchen-detail-status"><span class="kitchen-detail-kicker">طلب المطبخ</span><OrderStatusBadge :status="order.status"/></div>
      <OrderNumber :number="order.order_number"/>
      <dl class="kitchen-detail-meta"><div><dt><AdminIcon name="map-pin"/> الفرع</dt><dd>{{ order.branch?.name||'غير محدد' }}</dd></div><div><dt><AdminIcon :name="order.type==='delivery'?'truck':'shopping-bag'"/> نوع الطلب</dt><dd>{{ getOrderTypeLabel(order.type) }}</dd></div><div><dt><AdminIcon name="clock"/> وقت الطلب</dt><dd>{{ formatDateTime(order.placed_at) }}</dd></div><div v-if="confirmedAt"><dt><AdminIcon name="badge-check"/> وقت التأكيد</dt><dd>{{ formatDateTime(confirmedAt) }}</dd></div><div v-if="order.scheduled_for"><dt><AdminIcon name="clock"/> الموعد المحدد</dt><dd>{{ formatDateTime(order.scheduled_for) }}</dd></div></dl>
      <div class="kitchen-detail-operations"><AdminSectionHeading icon="utensils">إجراءات التجهيز</AdminSectionHeading><slot/></div>
      <p v-if="syncedAt" class="kitchen-detail-sync"><AdminIcon name="refresh"/> آخر مزامنة: {{ formatDateTime(syncedAt) }}</p>
    </aside>
  </div>
</template>
<style scoped>
.kitchen-detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:24px;align-items:start;margin-block:22px}
.kitchen-preparation{min-width:0}.kitchen-preparation-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;margin:0 0 16px;border-radius:12px;background:#f8efe6}
.kitchen-detail-kicker{font-size:11px;color:var(--muted);display:block;margin-bottom:5px}.kitchen-preparation-heading h2{font-size:21px;margin:0}
.kitchen-detail-count{display:flex;align-items:center;gap:8px;color:var(--primary);flex-shrink:0}.kitchen-detail-count strong{font-size:28px}.kitchen-detail-count span{font-size:12px}
.kitchen-detail-summary{position:sticky;top:24px;padding:22px;margin:0;border-radius:12px;min-width:0;border-top:4px solid var(--primary)}
.kitchen-detail-status{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}
:deep(.order-number-short){font-size:28px;letter-spacing:1px}:deep(.order-number-display){min-width:0}:deep(.order-number-full>div){flex-wrap:wrap;overflow-wrap:anywhere}
.kitchen-detail-meta{margin:20px 0;padding:0;border-block:1px solid var(--line)}.kitchen-detail-meta>div{display:flex;justify-content:space-between;gap:18px;padding-block:12px;border-bottom:1px solid var(--line);font-size:13px}.kitchen-detail-meta>div:last-child{border:0}
.kitchen-detail-meta dt{display:flex;align-items:center;gap:6px;color:var(--muted);flex-shrink:0}.kitchen-detail-meta dd{margin:0;text-align:end;font-weight:500;overflow-wrap:anywhere}.kitchen-detail-meta .icon{width:15px;height:15px}
.kitchen-detail-operations :deep(.admin-section-heading){margin-bottom:12px}.kitchen-detail-operations :deep(.form-actions){display:flex;flex-direction:column;align-items:stretch;gap:10px;margin:0}.kitchen-detail-operations :deep(.form-actions>.button){width:100%;justify-content:center;min-height:44px}.kitchen-detail-operations :deep(.form-actions>.button:not(.button-success)){background:#d71920;border-color:#d71920;color:#fff}.kitchen-detail-operations :deep(.form-actions>.button.button-success){background:var(--admin-success,#36784c);border-color:var(--admin-success,#36784c);color:#fff}.kitchen-detail-operations :deep(.form-actions>p){font-size:13px;color:var(--muted);line-height:1.8}
.kitchen-detail-sync{display:flex;align-items:center;gap:6px;color:var(--muted);font-size:11px;margin:18px 0 0;padding-top:14px;border-top:1px solid var(--line)}.kitchen-detail-sync .icon{width:14px;height:14px;flex-shrink:0}
:deep(.kitchen-snapshot){padding:0;border:0;background:transparent;box-shadow:none;margin:0}
:deep(.kitchen-snapshot>h2){display:none}
:deep(.kitchen-snapshot>article){background:var(--admin-surface);border:1px solid var(--line);border-radius:12px;padding:20px 22px;margin-bottom:12px}
:deep(.kitchen-snapshot article h3){display:flex;align-items:baseline;gap:14px;font-size:20px;line-height:1.6;margin:0;overflow-wrap:anywhere}
:deep(.kitchen-snapshot article h3 strong){display:inline-block;flex-shrink:0;min-width:50px;font-size:23px;text-align:center;padding:3px 9px;border-radius:8px;background:#5a17250a;color:var(--primary)}
:deep(.kitchen-snapshot article p){display:inline-block;margin:12px 64px 0 0;padding:6px 10px;background:#f8efe6;border-radius:6px;font-size:13px;overflow-wrap:anywhere}
:deep(.kitchen-snapshot>section){background:#fff5df;border:1px solid #ebd7ab;border-inline-start:4px solid #af7828;border-radius:12px;padding:20px 22px;margin-top:20px}
:deep(.kitchen-snapshot>section h3){font-size:15px;color:#79551f;margin-bottom:10px}:deep(.kitchen-notes){font-size:16px;line-height:1.9;color:var(--text);font-weight:500}
@media(max-width:1000px){.kitchen-detail-layout{grid-template-columns:minmax(0,1fr)}.kitchen-detail-summary{position:static;grid-row:1}.kitchen-detail-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:24px}.kitchen-detail-operations :deep(.form-actions){flex-direction:row;flex-wrap:wrap}.kitchen-detail-operations :deep(.form-actions>.button){width:auto;flex:1 1 180px}}
@media(max-width:600px){.kitchen-detail-layout{gap:18px;margin-top:18px}.kitchen-detail-summary{padding:18px}.kitchen-detail-meta{display:block}.kitchen-preparation-heading{padding:16px;gap:12px}.kitchen-preparation-heading h2{font-size:19px}.kitchen-detail-count{flex-direction:column;gap:0;align-items:end}.kitchen-detail-count strong{font-size:24px}:deep(.kitchen-snapshot>article){padding:16px}:deep(.kitchen-snapshot article h3){font-size:17px;gap:10px}:deep(.kitchen-snapshot article h3 strong){min-width:42px;font-size:20px}:deep(.kitchen-snapshot article p){display:block;margin:10px 52px 0 0}:deep(.kitchen-snapshot>section){padding:16px}:deep(.kitchen-notes){font-size:15px}}
</style>
