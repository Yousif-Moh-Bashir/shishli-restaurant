<script setup>
import { computed } from 'vue'
import { useFinanceSession } from '../session'
import { auditDetails } from '../auditPresentation'
const props = defineProps({ record: Object })
const session = useFinanceSession()
const details = computed(() => auditDetails(props.record, session))
</script>
<template>
  <details class="finance-audit-details">
    <summary>عرض التفاصيل</summary>
    <dl v-if="details.fields.length" class="finance-audit-fields">
      <div v-for="field in details.fields" :key="field.key" class="finance-audit-field">
        <dt>{{ field.label }}</dt>
        <dd :class="{ 'finance-audit-comparison': details.comparison }">
          <span v-if="details.comparison"><small>قبل:</small><bdi>{{ field.before }}</bdi></span>
          <span><small v-if="details.comparison">بعد:</small><bdi>{{ field.after }}</bdi></span>
        </dd>
      </div>
    </dl>
    <p v-else class="muted">لا توجد تغييرات إضافية في بيانات العرض لهذه العملية.</p>
  </details>
</template>
