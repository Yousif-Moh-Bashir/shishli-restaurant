<script setup>
import { computed, ref, watch } from 'vue'
import { reportValue } from '../../utils/reportFormatters'
import AdminSectionHeading from '../admin/AdminSectionHeading.vue'
const props=defineProps({rows:{type:Array,default:()=>[]},context:Object})
const selected=ref(null)
watch(()=>props.rows,()=>selected.value=null)
const series=[{key:'orders_count',label:'إجمالي الطلبات',color:'#5a1725'},{key:'completed_orders_count',label:'الطلبات المكتملة',color:'#36784c'}]
const maximum=computed(()=>Math.max(1,...props.rows.flatMap(row=>series.map(item=>Number(row[item.key])||0))))
const width=computed(()=>Math.max(720,props.rows.length*54+80))
const step=computed(()=>(width.value-80)/Math.max(1,props.rows.length))
const active=computed(()=>props.rows[selected.value??props.rows.length-1])
const description=row=>`${reportValue(row.period,'date',props.context)}: ${series.map(item=>item.label+' '+reportValue(row[item.key],'number',props.context)).join('، ')}`
const dateLabel=value=>/^\d{4}-\d{2}-\d{2}$/.test(value)?`${Number(value.slice(8))}/${Number(value.slice(5,7))}`:value
</script>
<template>
  <section class="panel report-chart" aria-label="الطلبات حسب الفترة">
    <AdminSectionHeading icon="shopping-bag">الطلبات حسب الفترة</AdminSectionHeading>
    <div class="report-series-legend"><span v-for="item in series" :key="item.key"><i :style="{background:item.color}"/>{{ item.label }}</span></div>
    <p v-if="!rows.length||rows.every(row=>!Number(row.orders_count))" class="empty">لا توجد طلبات في الفترة المختارة.</p>
    <template v-else>
      <p class="report-chart-selection" aria-live="polite">{{ description(active) }}</p>
      <div class="report-columns-scroll" tabindex="0" role="region" aria-label="مقارنة الطلبات، قابل للتمرير أفقيًا">
        <svg class="report-columns" :viewBox="`0 0 ${width} 270`" :style="{width:width+'px'}" role="group" aria-label="إجمالي الطلبات والطلبات المكتملة">
          <g v-for="ratio in [0,.5,1]" :key="ratio"><line x1="60" :x2="width-20" :y1="205-ratio*155" :y2="205-ratio*155" class="report-chart-grid"/><text x="50" :y="209-ratio*155" text-anchor="end">{{ Math.round(maximum*ratio) }}</text></g>
          <g v-for="(row,index) in rows" :key="row.period" tabindex="0" role="button" :aria-label="description(row)" :aria-pressed="selected===index" @mouseenter="selected=index" @focus="selected=index" @click="selected=index" @keydown.enter.prevent="selected=index" @keydown.space.prevent="selected=index">
            <rect :x="60+index*step" y="28" :width="step" height="180" fill="transparent"/>
            <rect v-for="(item,offset) in series" :key="item.key" class="report-column" :x="60+(index+.5)*step+(offset-1)*16" :y="205-(Number(row[item.key])||0)/maximum*155" width="13" :height="(Number(row[item.key])||0)/maximum*155" rx="3" :fill="item.color"/>
            <text :x="60+(index+.5)*step" y="235" text-anchor="middle">{{ dateLabel(row.period) }}</text><title>{{ description(row) }}</title>
          </g>
        </svg>
      </div>
      <p class="muted report-chart-hint">اختر فترة لعرض أعدادها. مرّر أفقيًا لرؤية بقية الفترات.</p>
    </template>
  </section>
</template>
<style scoped>
.report-series-legend{display:flex;flex-wrap:wrap;gap:16px;margin:14px 0;font-size:12px}.report-series-legend span{display:flex;align-items:center;gap:7px}.report-series-legend i{width:12px;height:12px;border-radius:3px}
</style>
