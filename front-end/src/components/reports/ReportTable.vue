<script setup>
import AdminIcon from '../admin/AdminIcon.vue'
import { reportValue } from '../../utils/reportFormatters'
defineProps({ rows: Array, columns: Array, context: Object, meta: Object, branchDrilldown: Boolean })
const emit = defineEmits(['page','branch'])
</script>
<template>
  <section class="panel report-table">
    <div v-if="rows?.length" class="table-wrap"><table><thead><tr><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th><th v-if="branchDrilldown" scope="col">تفاصيل الفرع</th></tr></thead><tbody><tr v-for="(row,index) in rows" :key="row.order_uuid || row.customer_uuid || row.product_uuid || index"><td v-for="column in columns" :key="column.key">{{ reportValue(row[column.key], column.type, context) }}</td><td v-if="branchDrilldown"><button class="row-button" @click="emit('branch', row.branch_uuid)">تقارير هذا الفرع <AdminIcon name="arrow-left"/></button></td></tr></tbody></table></div>
    <p v-else class="empty" role="status">لا توجد نتائج لهذه الفلاتر.</p>
    <nav v-if="meta" class="report-pagination" aria-label="صفحات التقرير"><button class="button secondary" :disabled="meta.current_page <= 1" @click="emit('page',meta.current_page - 1)">السابق</button><span>صفحة {{ meta.current_page }} من {{ meta.last_page }} · {{ meta.total }} نتيجة</span><button class="button secondary" :disabled="meta.current_page >= meta.last_page" @click="emit('page',meta.current_page + 1)">التالي</button></nav>
  </section>
</template>
