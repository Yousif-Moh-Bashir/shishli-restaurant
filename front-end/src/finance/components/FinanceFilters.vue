<script setup>
import { computed, ref } from 'vue'
import { useFinanceSession } from '../session'
import FinanceFilterPanel from './FinanceFilterPanel.vue'
const props = defineProps({ modelValue: Object, sources: Boolean, periods: Boolean, statuses: Boolean, title: { type: String, default: 'تصفية النتائج' } })
const emit = defineEmits(['update:modelValue', 'apply'])
const session = useFinanceSession()
const update = (values, field, value) => emit('update:modelValue', { ...values, [field]: value })
const panel = ref(null)
const periodSummary = computed(() => {
  const date = value => {
    if (!value) return ''
    const parsed = new Date(`${value}T12:00:00`)
    return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('ar-SA-u-ca-gregory', { day: 'numeric', month: 'long', year: 'numeric', numberingSystem: 'latn' }).format(parsed) : value
  }
  const from = date(props.modelValue.from), to = date(props.modelValue.to)
  return from && to ? `${from} — ${to}` : from ? `من ${from}` : to ? `حتى ${to}` : 'جميع الفترات'
})
function apply() { emit('apply'); panel.value?.collapse() }
</script>
<template><FinanceFilterPanel ref="panel" :title="title" :summary="periodSummary"><form class="filters card" @submit.prevent="apply">
<label>من تاريخ<input type="date" :value="modelValue.from" @input="update(modelValue, 'from', $event.target.value)"></label>
<label>إلى تاريخ<input type="date" :value="modelValue.to" @input="update(modelValue, 'to', $event.target.value)"></label>
<label>الفرع<select :value="modelValue.branch_uuid" @change="update(modelValue, 'branch_uuid', $event.target.value)"><option value="">كل الفروع المسموحة</option><option v-for="b in session.branches" :value="b.uuid" :key="b.uuid">{{ b.name }}</option></select></label>
<label v-if="sources">مصدر الإيراد<select :value="modelValue.source_uuid" @change="update(modelValue, 'source_uuid', $event.target.value)"><option value="">كل المصادر</option><option v-for="s in session.sources" :key="s.uuid" :value="s.uuid">{{ s.name }}</option></select></label>
<label v-if="periods">عرض التقرير<select :value="modelValue.group_by" @change="update(modelValue, 'group_by', $event.target.value)"><option value="day">يومي</option><option value="month">شهري</option><option value="year">سنوي</option></select></label>
<label v-if="statuses">حالة الإقفال<select :value="modelValue.status" @change="update(modelValue, 'status', $event.target.value)"><option value="">الكل</option><option value="draft">مسودة</option><option value="approved">معتمد</option></select></label>
<button class="primary" type="submit"><FinanceIcon name="search" />تطبيق الفلاتر</button></form></FinanceFilterPanel></template>
