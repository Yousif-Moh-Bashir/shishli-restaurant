<script setup>
import { computed, ref, useId, watch, onMounted, onBeforeUnmount } from 'vue'
import { reportValue } from '../../utils/reportFormatters'
import AdminSectionHeading from '../admin/AdminSectionHeading.vue'
const props = defineProps({ rows: { type: Array, default: () => [] }, labelKey: String, valueKey: String, labelType: { type: String, default: 'text' }, valueType: { type: String, default: 'number' }, title: String, context: Object, trend: Boolean, metricLabel: String })
// Chart geometry only. Values and ordering are supplied by reporting endpoints.
const maximum = computed(() => Math.max(1, ...props.rows.map(row => Number(row[props.valueKey]) || 0)))
const gradientId=useId(), selected=ref(null)
watch(()=>[props.rows,props.valueKey],()=>selected.value=null)
const points=computed(()=>props.rows.map((row,index)=>({row,x:60+(props.rows.length===1?340:index/(props.rows.length-1)*680),y:245-Math.max(0,Number(row[props.valueKey])||0)/maximum.value*205})))
const line=computed(()=>points.value.map((point,index)=>`${index?'L':'M'} ${point.x} ${point.y}`).join(' '))
const area=computed(()=>points.value.length?`${line.value} L ${points.value.at(-1).x} 245 L ${points.value[0].x} 245 Z`:'')
const axisNumber=new Intl.NumberFormat('ar-SA',{numberingSystem:'latn',notation:'compact',maximumFractionDigits:1})
const ticks=computed(()=>[0,.25,.5,.75,1].map(ratio=>({y:245-ratio*205,value:axisNumber.format(maximum.value*ratio)})))
const labels=computed(()=>points.value.filter((point,index)=>index===0||index===points.value.length-1||index%Math.max(1,Math.ceil(points.value.length/3))===0))
const axisLabel=row=>props.labelType==='date'&&/^\d{4}-\d{2}-\d{2}$/.test(row[props.labelKey])?`${Number(row[props.labelKey].slice(8))}/${Number(row[props.labelKey].slice(5,7))}`:reportValue(row[props.labelKey],props.labelType,props.context)
const active=computed(()=>props.rows[selected.value??props.rows.length-1])
const pointLabel=row=>`${reportValue(row[props.labelKey],props.labelType,props.context)}: ${reportValue(row[props.valueKey],props.valueType,props.context)}`
const chartContainer=ref(null),availableWidth=ref(640)
let observer
onMounted(()=>{observer=new ResizeObserver(([entry])=>{availableWidth.value=Math.round(entry.contentRect.width)});if(chartContainer.value)observer.observe(chartContainer.value)})
onBeforeUnmount(()=>observer?.disconnect())
const columnWidth=computed(()=>Math.max(availableWidth.value,props.rows.length*90+80))
const columns=computed(()=>props.rows.map((row,index)=>({row,x:60+(index+.5)*(columnWidth.value-80)/props.rows.length,width:Math.min(32,(columnWidth.value-80)/props.rows.length*.4),height:Math.max(0,Number(row[props.valueKey])||0)/maximum.value*155})))
const columnTicks=computed(()=>[0,.5,1].map(ratio=>({y:205-ratio*155,value:axisNumber.format(maximum.value*ratio)})))
const columnLabel=row=>{const text=reportValue(row[props.labelKey],props.labelType,props.context);return [text.slice(0,14),text.length>14?text.slice(14,27)+(text.length>27?'…':''):'']}
</script>
<template>
  <section ref="chartContainer" class="panel report-chart" :aria-label="title">
    <AdminSectionHeading icon="layout-grid">{{ title }}</AdminSectionHeading>
    <p v-if="!rows.length || rows.every(row => Number(row[valueKey]) === 0)" class="empty">لا توجد قيم مسجلة في هذا الرسم للفترة المختارة.</p>
    <template v-else-if="trend">
      <p class="report-chart-selection" aria-live="polite">{{ metricLabel }} · {{ pointLabel(active) }}</p>
      <svg class="report-trend" viewBox="0 0 800 300" role="group" :aria-label="`${title} — ${metricLabel}`">
        <defs><linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--red)" stop-opacity=".24"/><stop offset="100%" stop-color="var(--red)" stop-opacity=".02"/></linearGradient></defs>
        <g v-for="tick in ticks" :key="tick.y"><line x1="60" x2="740" :y1="tick.y" :y2="tick.y" class="report-chart-grid"/><text x="50" :y="tick.y+4" text-anchor="end">{{ tick.value }}</text></g>
        <path :d="area" :fill="`url(#${gradientId})`"/><path :d="line" class="report-trend-line"/>
        <g v-for="(point,index) in points" :key="index" tabindex="0" role="button" :aria-label="pointLabel(point.row)" :aria-pressed="selected===index" @mouseenter="selected=index" @focus="selected=index" @click="selected=index" @keydown.enter.prevent="selected=index" @keydown.space.prevent="selected=index"><circle :cx="point.x" :cy="point.y" r="14" fill="transparent"/><circle :cx="point.x" :cy="point.y" :r="selected===index?6:4" class="report-trend-dot"/><title>{{ pointLabel(point.row) }}</title></g>
        <text v-for="point in labels" :key="point.x" :x="point.x" y="285" text-anchor="middle">{{ axisLabel(point.row) }}</text>
      </svg>
      <p class="muted report-chart-hint">اختر نقطة لعرض قيمتها. الفترات مرتبة حسب بيانات التقرير.</p>
    </template>
    <template v-else>
      <p class="report-chart-selection" aria-live="polite">{{ metricLabel }} · {{ pointLabel(active) }}</p>
      <div class="report-columns-scroll" tabindex="0" role="region" aria-label="مخطط الأعمدة، قابل للتمرير أفقيًا">
        <svg class="report-columns" :viewBox="`0 0 ${columnWidth} 270`" :style="{width:columnWidth+'px'}" role="group" :aria-label="`${title} — ${metricLabel}`">
          <defs><linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--red)" stop-opacity=".85"/><stop offset="100%" stop-color="var(--red)" stop-opacity=".55"/></linearGradient></defs>
          <g v-for="tick in columnTicks" :key="tick.y"><line x1="60" :x2="columnWidth-20" :y1="tick.y" :y2="tick.y" class="report-chart-grid"/><text x="50" :y="tick.y+4" text-anchor="end">{{ tick.value }}</text></g>
          <line x1="60" :x2="columnWidth-20" y1="205" y2="205" class="report-chart-axis"/>
          <g v-for="(column,index) in columns" :key="index" tabindex="0" role="button" :aria-label="pointLabel(column.row)" :aria-pressed="selected===index" :data-value="column.row[valueKey]" @mouseenter="selected=index" @focus="selected=index" @click="selected=index" @keydown.enter.prevent="selected=index" @keydown.space.prevent="selected=index">
            <rect :x="column.x-22" y="28" width="44" height="180" fill="transparent"/>
            <rect class="report-column" :x="column.x-column.width/2" :y="205-column.height" :width="column.width" :height="column.height" rx="4" :fill="`url(#${gradientId})`"/>
            <text :x="column.x" :y="195-column.height" text-anchor="middle" class="report-column-value">{{ axisNumber.format(Number(column.row[valueKey])||0) }}</text>
            <text :x="column.x" y="230" text-anchor="middle" direction="rtl"><tspan :x="column.x">{{ columnLabel(column.row)[0] }}</tspan><tspan :x="column.x" dy="17">{{ columnLabel(column.row)[1] }}</tspan></text>
            <title>{{ pointLabel(column.row) }}</title>
          </g>
        </svg>
      </div>
      <p class="muted report-chart-hint">اختر عمودًا لعرض الاسم والقيمة كاملين. مرّر أفقيًا لرؤية بقية العناصر.</p>
    </template>
  </section>
</template>
