<script setup>
import { computed } from 'vue'
import { getOrderStatusLabel } from '../../constants/orderStatus'
import { formatDateTime } from '../../utils/formatters'
import OrderStatusBadge from './OrderStatusBadge.vue'
const props=defineProps({timeline:{type:Array,default:()=>[]},statusHistory:{type:Array,default:()=>[]},timeZone:{type:String,default:'Asia/Riyadh'}})
const steps=computed(()=>props.timeline.length?props.timeline:props.statusHistory.map(h=>({status:h.to_status,occurred_at:h.created_at,source:h.source,note:h.note,actor:h.changed_by?.name,from:h.from_status,state:h.to_status==='cancelled'?'cancelled':'completed'})))
const sources={system:'النظام',admin:'الإدارة',cashier:'الكاشير',kitchen:'المطبخ',customer:'العميل'}
</script>
<template><ol v-if="steps.length" class="order-timeline" aria-label="مراحل الطلب"><li v-for="(step,index) in steps" :key="index" :class="'order-step-'+step.state" :aria-current="step.state==='current'?'step':undefined"><span class="order-step-icon">{{ step.state==='upcoming'?'○':'●' }}</span><div><OrderStatusBadge :status="step.status"/><p v-if="step.from">من {{ getOrderStatusLabel(step.from) }}</p><time v-if="step.occurred_at" :datetime="step.occurred_at">{{ formatDateTime(step.occurred_at,timeZone) }}</time><small v-else>{{ step.state==='upcoming'?'لم تبدأ بعد':'التوقيت غير متاح' }}</small><p v-if="step.source">{{ sources[step.source]||'مصدر غير معروف' }}<span v-if="step.actor"> · {{ step.actor }}</span></p><p v-if="step.note" class="order-text">{{ step.note }}</p></div></li></ol><p v-else>لا يوجد سجل مراحل متاح لهذا الطلب.</p></template>
<style scoped>.order-text{white-space:pre-wrap;overflow-wrap:anywhere}</style>
