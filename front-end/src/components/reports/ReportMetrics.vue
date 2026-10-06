<script setup>
import { computed } from 'vue'
import { metricDefinitions } from '../../constants/reports'
import { reportValue, reportDate } from '../../utils/reportFormatters'
import AdminIcon from '../admin/AdminIcon.vue'
const props = defineProps({ summary: Object, context: Object, comparison: Object })
const metrics = computed(() => Object.entries(props.summary || {}).filter(([key,value]) => metricDefinitions[key] && value != null))
const change = key => props.comparison?.changes?.[key]
</script>
<template>
  <section v-if="metrics.length" class="stats report-metrics" aria-label="مؤشرات التقرير">
    <article v-for="[key, value] in metrics" :key="key" class="stat" :data-metric="key">
      <div class="stat-heading"><span>{{ metricDefinitions[key][0] }}</span><span class="stat-icon"><AdminIcon :name="metricDefinitions[key][1] === 'money' ? 'badge-check' : key.includes('customer') ? 'user-round' : key.includes('stock') || key.includes('product') ? 'utensils' : key.includes('delivery') ? 'truck' : 'shopping-bag'"/></span></div><strong>{{ reportValue(value, metricDefinitions[key][1], context) }}</strong>
      <small v-if="change(key)" :class="['report-change', change(key).trend]">
        {{ change(key).trend === 'up' ? '↑' : change(key).trend === 'down' ? '↓' : '—' }}
        {{ change(key).percentage_change == null ? 'فترة سابقة بلا قيمة للمقارنة' : reportValue(change(key).percentage_change, 'percent') }}
        · السابق {{ reportValue(change(key).previous, metricDefinitions[key][1], context) }}
      </small>
    </article>
  </section>
  <p v-if="comparison?.period" class="report-period muted">المقارنة مع {{ reportDate(comparison.period.from) }} — {{ reportDate(comparison.period.to) }}</p>
</template>
