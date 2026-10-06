<script setup>
import {reactive,ref} from 'vue'
import {settingsApi} from '../../../api/settings.api'
import {settingFields} from '../../../constants/settings'
import {useApiState} from '../../../composables/useApiState'
import ApiFeedback from '../catalog/ApiFeedback.vue'
import ApiPagination from '../catalog/ApiPagination.vue'
import AdminSectionHeading from '../AdminSectionHeading.vue'
const state=useApiState(),filters=reactive({key:'',user_uuid:'',date_from:'',date_to:'',per_page:20}),page=ref(1)
function load(current=1){page.value=current;state.run(signal=>settingsApi.audit({...filters,page:current},signal))}
function resetFilters(){Object.assign(filters,{key:'',user_uuid:'',date_from:'',date_to:'',per_page:20});load()}
const display=value=>value===null?'—':typeof value==='boolean'?(value?'مفعّل':'غير مفعّل'):String(value)
load()
</script>
<template>
<section class="panel settings-audit-panel">
  <AdminSectionHeading icon="clock">سجل تغييرات الإعدادات<template #actions><button type="button" class="row-button" :disabled="state.loading.value" @click="load(page)">تحديث السجل</button></template></AdminSectionHeading>
  <p class="settings-section-intro">راجع التعديلات على إعدادات المطعم، وقارن القيم السابقة بالجديدة.</p>
  <form class="settings-audit-filters" @submit.prevent="load()">
    <div class="form-grid">
      <label class="field">الإعداد<select v-model="filters.key"><option value="">كل الإعدادات</option><option v-for="(field,key) in settingFields" :key="key" :value="key">{{ field.label }}</option></select></label>
      <label class="field">عدد النتائج<select v-model.number="filters.per_page"><option :value="20">20 نتيجة</option><option :value="50">50 نتيجة</option><option :value="100">100 نتيجة</option></select></label>
      <label class="field">من تاريخ<input v-model="filters.date_from" type="date" :max="filters.date_to||undefined"></label>
      <label class="field">إلى تاريخ<input v-model="filters.date_to" type="date" :min="filters.date_from||undefined"></label>
    </div>
    <p class="form-note">التصفية حسب أيام المطعم؛ أوقات التغييرات أدناه بالتوقيت العالمي UTC.</p>
    <p v-if="filters.user_uuid" class="audit-user-filter" role="status">تعرض تغييرات المستخدم المحدد.<button class="row-button" type="button" :disabled="state.loading.value" @click="filters.user_uuid='';load()">عرض كل المستخدمين</button></p>
    <div class="form-actions"><button class="button" :disabled="state.loading.value">تطبيق الفلاتر</button><button class="row-button" type="button" :disabled="state.loading.value" @click="resetFilters">مسح الفلاتر</button></div>
  </form>
  <ApiFeedback :loading="state.loading.value" :error="state.error.value" :empty="state.data.value?.data.length===0" @retry="load(page)"/>
  <div v-if="state.data.value?.data.length" class="table-wrap audit-results"><table v-admin-table><thead><tr><th>الإعداد</th><th>القيمة السابقة</th><th>القيمة الجديدة</th><th>وقت التغيير (UTC)</th><th>المنفّذ</th></tr></thead><tbody><tr v-for="row in state.data.value.data" :key="row.uuid"><td><div class="admin-cell-value"><strong>{{ settingFields[row.setting_key]?.label||row.setting_key }}</strong></div></td><td class="setting-value"><div class="admin-cell-value"><span class="audit-old-value">{{ display(row.old_value) }}</span></div></td><td class="setting-value"><div class="admin-cell-value"><span class="audit-new-value">{{ display(row.new_value) }}</span></div></td><td><div class="admin-cell-value"><bdi>{{ row.created_at }}</bdi></div></td><td><div class="admin-cell-value"><div class="audit-actor"><span>{{ row.changed_by?'مستخدم مسجّل':'حساب غير متاح' }}</span><button v-if="row.changed_by" class="row-button" :disabled="state.loading.value" @click="filters.user_uuid=row.changed_by;load()">تغييرات المستخدم</button></div></div></td></tr></tbody></table></div>
  <ApiPagination :meta="state.data.value?.meta" :links="state.data.value?.links" :busy="state.loading.value" @page="load"/>
</section>
</template>