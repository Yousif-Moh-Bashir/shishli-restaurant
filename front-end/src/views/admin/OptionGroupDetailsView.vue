<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AdminIcon from '../../components/admin/AdminIcon.vue'
import AdminSectionHeading from '../../components/admin/AdminSectionHeading.vue'
import {ref,watch,reactive,inject} from 'vue'
import {useRoute,RouterLink} from 'vue-router'
import {optionGroupsApi,optionValuesApi} from '../../api/productOptions.api'
import {catalogFields,initialForm,formPayload} from '../../constants/catalogFields'
import {useAuthStore} from '../../stores/auth'
import {useApiState,useApiMutation} from '../../composables/useApiState'
import {formatCurrency} from '../../utils/formatters'
import CatalogFields from '../../components/admin/catalog/CatalogFields.vue'
import CatalogEditor from '../../components/admin/catalog/CatalogEditor.vue'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
const route=useRoute(),auth=useAuthStore(),state=useApiState(),mutation=useApiMutation(),editorOpen=ref(false),valueForm=ref(false),editing=ref(null),form=reactive(initialForm(catalogFields.values))
const {notify}=inject('admin')
function load(){state.run(()=>optionGroupsApi.show(route.params.uuid))}
watch(()=>route.params.uuid,()=>{valueForm.value=false;load()},{immediate:true})
function edit(value=null){editing.value=value;Object.assign(form,initialForm(catalogFields.values,value||{}));mutation.error.value=null;valueForm.value=true}
async function submit(){
const payload=formPayload(catalogFields.values,form)
const result=await mutation.run(()=>editing.value?optionValuesApi.update(state.data.value.id,editing.value.id,payload):optionValuesApi.create(state.data.value.id,payload))
if(result.ok){valueForm.value=false;notify('تم حفظ قيمة الخيار على الخادم.');load()}
}
async function remove(value){if(!(await confirmAction('حذف قيمة «'+value.name+'»؟')))return;const result=await mutation.run(()=>optionValuesApi.remove(state.data.value.id,value.id));if(result.ok){notify('تم حذف قيمة الخيار.');load()}}
</script>
<template><main id="main" class="admin-detail-page"><AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>{{ state.data.value?.name || 'مجموعة خيارات' }}</h1><RouterLink class="row-button" to="/admin/product-options">العودة للمجموعات</RouterLink></div></AdminPageHeader><ApiFeedback variant="detail" :loading="state.loading.value" :error="state.error.value" @retry="load"/><template v-if="state.data.value"><div class="detail-management-grid"><section class="panel"><AdminSectionHeading icon="settings">إعدادات المجموعة</AdminSectionHeading><dl class="order-facts"><dt>نوع الاختيار</dt><dd>{{ state.data.value.type==='single'?'اختيار واحد':'اختيارات متعددة' }}</dd><dt>الإلزام</dt><dd>{{ state.data.value.is_required?'إلزامي':'اختياري' }}</dd><dt>حدود الاختيارات</dt><dd>{{ state.data.value.min_select }}–{{ state.data.value.max_select??'بلا حد' }}</dd><dt>الحالة</dt><dd><span class="badge" :class="state.data.value.is_active?'good':'off'">{{ state.data.value.is_active?'نشطة':'متوقفة' }}</span></dd></dl><button v-if="auth.can('options.update')" class="button" @click="editorOpen=true">تعديل المجموعة</button></section><section class="panel catalog-section"><AdminSectionHeading icon="layout-grid">قيم الخيارات</AdminSectionHeading><button v-if="auth.can('options.create')" class="row-button" :disabled="mutation.busy.value" @click="edit()"><AdminIcon name="plus"/> إضافة قيمة</button><div v-if="state.data.value.values?.length" class="table-wrap"><table v-admin-table><thead><tr><th>الاسم</th><th>زيادة السعر</th><th>الحالة / الترتيب</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="value in state.data.value.values" :key="value.id"><td><div class="admin-cell-value">{{ value.name }}</div></td><td><div class="admin-cell-value">{{ formatCurrency(value.price_modifier) }}</div></td><td><div class="admin-cell-value">{{ value.is_active?'نشط':'متوقف' }} · {{ value.is_default?'افتراضي':'غير افتراضي' }} · {{ value.sort_order }}</div></td><td><div class="admin-cell-value"><div class="admin-row-actions"><button v-if="auth.can('options.update')" class="row-button" :disabled="mutation.busy.value" @click="edit(value)"><AdminIcon name="pencil"/> تعديل</button><button v-if="auth.can('options.delete')" class="row-button danger" :disabled="mutation.busy.value" @click="remove(value)"><AdminIcon name="trash-2"/> حذف</button></div></div></td></tr></tbody></table></div><p v-else class="empty">لا توجد قيم للمجموعة.</p>
<form v-if="valueForm" class="catalog-subform" @submit.prevent="submit"><h3>{{ editing?'تعديل قيمة':'إضافة قيمة' }}</h3><CatalogFields :fields="catalogFields.values" :form="form" :errors="mutation.error.value?.errors" :disabled="mutation.busy.value"/><div class="form-actions"><button class="button" :disabled="mutation.busy.value">حفظ القيمة</button><button class="row-button" type="button" :disabled="mutation.busy.value" @click="valueForm=false">إلغاء</button></div></form><ApiFeedback v-if="mutation.error.value" :error="mutation.error.value" @retry="mutation.error.value=null"/></section></div><CatalogEditor v-model:open="editorOpen" :record="state.data.value" kind="groups" :api="optionGroupsApi" @saved="load"/></template></main></template>

