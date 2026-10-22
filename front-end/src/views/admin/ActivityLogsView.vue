<script setup>
import AdminFilterSummary from '../../components/admin/AdminFilterSummary.vue'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import {computed} from 'vue'
import {exceptionActionLabels} from '../../constants/deliveryExceptions'
import {deliveryLabels} from '../../constants/driverDelivery'
import DomainFilters from '../../components/admin/DomainFilters.vue'
import {paginationFields} from '../../constants/domainFilters'
import {staffApi} from '../../api/adminOperations.api'
import {useAuthStore} from '../../stores/auth'
import {activityApi} from '../../api/adminOperations.api'
import {useServerList} from '../../composables/useServerList'
import {formatDateTime} from '../../utils/formatters'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
const activityFilters=[{key:'action',label:'نوع العملية',max:150},{key:'subject_type',label:'نوع المورد',max:80},{key:'date_from',label:'من تاريخ',type:'date'},{key:'date_to',label:'إلى تاريخ',type:'date'},...paginationFields]
const list=useServerList(activityApi,{action:'',subject_type:'',date_from:'',date_to:'',sort:'created_at',direction:'desc'})
const filterDefaults={...list.filters}
// Only allow-listed audit metadata is presented. No raw payload rendering.
const auditFields={delivery_status:'حالة التوصيل',driver_uuid:'معرف المندوب',payment_status:'حالة الدفع',amount:'المبلغ'}
const safeValues=value=>Object.entries(value||{}).filter(([key])=>['name','is_active','roles','permissions','password_changed','status','sort_order','title','key',...Object.keys(auditFields)].includes(key)).map(([key,v])=>(auditFields[key]||key)+': '+(key==='delivery_status'?(deliveryLabels[v]||'غير مسند'):Array.isArray(v)?v.join('، '):String(v))).join(' · ')
const activityLabel=value=>value?.startsWith('delivery_exceptions.')?(exceptionActionLabels[value.split('.')[1]]||'طلب تدخل الإدارة'):value==='payments.confirm_network'?'تأكيد دفع الشبكة':value
const auth=useAuthStore(),pickers=computed(()=>auth.can('staff.view')?[{key:'user_uuid',label:'الموظف',api:staffApi}]:[])
list.load()
</script>
<template><main id="main"><AdminPageHeader><div><span class="eyebrow">إدارة شيشلي</span><h1>سجل النشاط</h1><p>راجع العمليات الإدارية والتغييرات المسجلة.</p></div></AdminPageHeader><section class="panel"><form class="toolbar" @submit.prevent="list.search"><input v-model="list.filters.search" type="search" aria-label="بحث النشاط" placeholder="بحث في السجل" maxlength="150"><button class="button" :disabled="list.state.loading.value">بحث</button><DomainFilters @clear="list.search()" :form="list.filters" :fields="activityFilters" :pickers="pickers" :busy="list.state.loading.value"/></form><AdminFilterSummary :filters="list.filters" :defaults="filterDefaults" :busy="list.state.loading.value" @clear="Object.assign(list.filters,filterDefaults);list.search()"/><ApiFeedback :loading="list.state.loading.value" :error="list.state.error.value" @retry="list.load"/><div v-if="list.state.data.value" class="table-wrap"><table v-admin-table><thead><tr><th>الوقت</th><th>الموظف</th><th>العملية</th><th>المورد</th><th>التفاصيل الآمنة</th></tr></thead><tbody><tr v-for="row in list.state.data.value.data" :key="row.id"><td><div class="admin-cell-value">{{ formatDateTime(row.created_at) }}</div></td><td><div class="admin-cell-value">{{ row.actor?.name||'النظام' }}</div></td><td><div class="admin-cell-value"><bdi>{{ activityLabel(row.action) }}</bdi></div></td><td><div class="admin-cell-value">{{ row.subject?.type }}</div></td><td><div class="admin-cell-value">{{ row.description }}<details v-if="safeValues(row.old_values)||safeValues(row.new_values)"><summary>عرض التغيير</summary><p>قبل: {{ safeValues(row.old_values) }}</p><p>بعد: {{ safeValues(row.new_values) }}</p></details></div></td></tr></tbody></table><p v-if="!list.state.data.value.data.length" class="empty">لا توجد أنشطة مطابقة.</p></div><ApiPagination :meta="list.state.data.value?.meta" :links="list.state.data.value?.links" :busy="list.state.loading.value" @page="list.changePage"/></section></main></template>
