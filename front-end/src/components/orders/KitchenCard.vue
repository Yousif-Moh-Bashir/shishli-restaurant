<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import AdminIcon from '../admin/AdminIcon.vue'
import OrderNumber from './OrderNumber.vue'
import OrderStatusBadge from './OrderStatusBadge.vue'
import KitchenSnapshot from './KitchenSnapshot.vue'
import { getOrderTypeLabel } from '../../constants/orderStatus'
const props=defineProps({order:{type:Object,required:true},elapsed:String,disabled:Boolean})
defineEmits(['focus'])
const quantity=computed(()=>(props.order.items||[]).reduce((total,item)=>total+Number(item.quantity||0),0))
</script>
<template>
  <article class="panel kitchen-order-card" :data-status="order.status">
    <header class="kitchen-card-heading"><OrderNumber :number="order.order_number"/><OrderStatusBadge :status="order.status"/></header>
    <div class="kitchen-card-context"><span><AdminIcon name="map-pin"/>{{ order.branch?.name || 'الفرع غير محدد' }}</span><span class="kitchen-order-type"><AdminIcon :name="order.type==='delivery'?'truck':'shopping-bag'"/>{{ getOrderTypeLabel(order.type) }}</span></div>
    <div class="kitchen-card-timing"><span><AdminIcon name="clock"/>{{ order.confirmed_at?'منذ التأكيد':'منذ استلام الطلب' }}</span><strong>{{ elapsed }}</strong></div>
    <div class="kitchen-card-items-heading"><h3>الأطباق المطلوبة</h3><span>{{ quantity }} قطعة</span></div>
    <KitchenSnapshot :order="order"/>
    <footer class="kitchen-card-actions"><button class="button" type="button" :disabled="disabled" @click="$emit('focus',order.id)"><AdminIcon name="utensils"/> إجراءات الطلب</button><RouterLink class="row-button" :to="'/admin/kitchen/'+order.id">تفاصيل التجهيز <AdminIcon name="arrow-left"/></RouterLink></footer>
  </article>
</template>
<style scoped>
.kitchen-order-card{display:flex;flex-direction:column;min-width:0;padding:20px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 3px 14px #35101704}
.kitchen-card-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}
.kitchen-card-heading :deep(.order-number-display){min-width:0}.kitchen-card-heading :deep(.order-number-short){font-size:22px;letter-spacing:1px;line-height:1.3}.kitchen-card-heading :deep(.order-number-label){font-size:11px}
.kitchen-card-heading :deep(.order-status-badge){flex-shrink:0;font-size:11px;margin-top:3px}
.kitchen-card-heading :deep(.order-status-badge){background:#f5f5f5;color:var(--primary);border:1px solid var(--line)}
.kitchen-card-heading :deep(.order-number-full){font-size:10px}.kitchen-card-heading :deep(.order-number-full>div){flex-wrap:wrap;overflow-wrap:anywhere}
.kitchen-card-context{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-bottom:14px}
.kitchen-card-context>span{display:flex;align-items:center;gap:5px;min-width:0;overflow-wrap:anywhere}.kitchen-card-context .icon{width:15px;height:15px;flex-shrink:0}
.kitchen-order-type{background:#f5f5f5;border-radius:6px;padding:4px 8px;color:var(--primary)}
.kitchen-card-timing{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f7f7f7;border:1px solid #ececec;border-radius:8px;padding:10px 12px;margin-bottom:18px}
.kitchen-card-timing>span{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted)}.kitchen-card-timing .icon{width:16px;height:16px}.kitchen-card-timing strong{font-size:17px;color:var(--primary);white-space:nowrap}
.kitchen-card-items-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:10px;border-bottom:1px solid var(--line)}.kitchen-card-items-heading h3{font-size:13px;color:var(--primary)}.kitchen-card-items-heading span{font-size:11px;color:var(--muted)}
:deep(.kitchen-snapshot){margin:0;padding:0;border:0;box-shadow:none;background:transparent;border-radius:0;flex:1}
:deep(.kitchen-snapshot>h2){display:none}
:deep(.kitchen-snapshot article){padding-block:12px;border-bottom:1px solid var(--line)}
:deep(.kitchen-snapshot article h3){display:flex;align-items:baseline;gap:8px;font-size:15px;line-height:1.6;font-weight:700;overflow-wrap:anywhere}
:deep(.kitchen-snapshot article h3 strong){display:inline-block;flex-shrink:0;min-width:34px;font-size:16px;color:var(--primary);background:#f5f5f5;border-radius:6px;text-align:center;padding:2px 5px}
:deep(.kitchen-snapshot article p){font-size:12px;color:var(--muted);margin:5px 42px 0 0;overflow-wrap:anywhere}
:deep(.kitchen-snapshot>section){padding:12px;background:#f7f7f7;border:1px solid #ececec;border-inline-start:3px solid var(--primary);border-radius:8px;margin-top:14px}
:deep(.kitchen-snapshot>section h3){font-size:12px;color:var(--primary);margin-bottom:5px}
:deep(.kitchen-notes){font-size:13px;line-height:1.8;font-weight:500;color:var(--text)}
.kitchen-card-actions{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap;padding-top:18px;margin-top:auto}
.kitchen-card-actions>.button,.kitchen-card-actions>.row-button{white-space:nowrap;line-height:1.5}
.kitchen-card-actions>.button{flex:1;justify-content:center;padding:10px 12px;min-height:42px;font-size:12px}
.kitchen-card-actions>.button{background:#d71920;border-color:#d71920;color:white}
.kitchen-card-actions>.row-button{margin:0;display:flex;align-items:center;justify-content:center;gap:5px;min-height:42px;font-size:11px;padding:8px 10px}
.kitchen-card-actions .icon{width:16px;height:16px}
@media(max-width:480px){.kitchen-order-card{padding:16px}.kitchen-card-actions{gap:8px}.kitchen-card-heading :deep(.order-number-short){font-size:21px}}
</style>
