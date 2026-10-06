<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import AdminFilterSummary from '../../components/admin/AdminFilterSummary.vue'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import {computed} from 'vue'
import {branchesApi} from '../../api/branches.api'
import DomainFilters from '../../components/admin/DomainFilters.vue'
import {promotionFilters} from '../../constants/domainFilters'
import {ref,inject} from 'vue'
import {promotionsApi} from '../../api/promotions.api'
import {useServerList} from '../../composables/useServerList'
import {useApiState,useApiMutation} from '../../composables/useApiState'
import {useAuthStore} from '../../stores/auth'
import {formatCurrency} from '../../utils/formatters'
import PromotionEditor from '../../components/admin/PromotionEditor.vue'
import CouponsPanel from '../../components/admin/CouponsPanel.vue'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
const auth=useAuthStore(),{notify}=inject('admin'),list=useServerList(promotionsApi,{status:'',type:'',sort:'priority',direction:'desc'}),detail=useApiState(),mutation=useApiMutation(),open=ref(false),editing=ref(null),selected=ref(null)
const filterDefaults={...list.filters}
const filterPickers=computed(()=>auth.can('branches.view')?[{key:'branch_uuid',label:'الفرع',api:branchesApi}]:[])
async function edit(row=null){editing.value=row?await detail.run(()=>promotionsApi.show(row.id)):null;if(row&&!editing.value)return;open.value=true}
async function remove(row){if(!(await confirmAction('حذف العرض '+row.name+'؟')))return;const result=await mutation.run(()=>promotionsApi.remove(row.id));if(result.ok){if(selected.value?.id===row.id)selected.value=null;notify('تم حذف العرض.');list.load()}}
function saved(value){if(selected.value?.id===value.id)selected.value=value;notify('تم حفظ العرض على الخادم.');list.load()}
list.load()
</script>
<template><main id="main"><AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>العروض والكوبونات</h1><p class="muted">إدارة العروض وقواعد تطبيقها على الخادم</p></div><button v-if="auth.can('promotions.create')" class="button" :disabled="mutation.busy.value||detail.loading.value" @click="edit()">إضافة عرض</button></AdminPageHeader><section class="panel"><form class="toolbar" @submit.prevent="list.search"><input v-model="list.filters.search" type="search" maxlength="150" aria-label="بحث العروض" placeholder="ابحث عن عرض"><select v-model="list.filters.status" aria-label="صلاحية العرض"><option value="">كل الحالات</option><option value="active">ساري</option><option value="future">قادم</option><option value="expired">منتهي</option><option value="inactive">متوقف</option></select><select v-model="list.filters.type" aria-label="نوع العرض"><option value="">كل الأنواع</option><option value="automatic">تلقائي</option><option value="coupon">كوبون</option></select><button class="button" :disabled="list.state.loading.value">بحث</button><DomainFilters @clear="list.search()" :form="list.filters" :fields="promotionFilters" :pickers="filterPickers" :busy="list.state.loading.value"/></form><AdminFilterSummary :filters="list.filters" :defaults="filterDefaults" :busy="list.state.loading.value" @clear="Object.assign(list.filters,filterDefaults);list.search()"/><ApiFeedback :loading="list.state.loading.value" :error="list.state.error.value" @retry="list.load"/><ApiFeedback variant="inline" :loading="detail.loading.value" :error="detail.error.value" @retry="detail.error.value=null"/><div v-if="list.state.data.value" class="table-wrap"><table v-admin-table><thead><tr><th>العرض</th><th>النوع / النطاق</th><th>الخصم</th><th>الحالة</th><th>الاستخدام</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="row in list.state.data.value.data" :key="row.id"><td><div class="admin-cell-value">{{ row.name }}</div></td><td><div class="admin-cell-value">{{ row.type==='coupon'?'كوبون':'تلقائي' }} · {{ row.application_type==='cart'?'السلة':row.application_type==='product'?'منتجات':'أقسام' }}</div></td><td><div class="admin-cell-value">{{ row.discount.type==='percentage'?row.discount.value+'٪':formatCurrency(row.discount.value) }}</div></td><td><div class="admin-cell-value"><span class="badge" :class="row.is_active?'good':'off'">{{ row.is_active?'مفعّل':'متوقف' }}</span></div></td><td><div class="admin-cell-value">{{ row.limits.used_count }}</div></td><td><div class="admin-cell-value"><div class="admin-row-actions"><button v-if="auth.can('promotions.update')" class="row-button" :disabled="mutation.busy.value||detail.loading.value" @click="edit(row)">تعديل</button><button v-if="auth.can('coupons.view')" class="row-button" :disabled="mutation.busy.value" @click="selected=row">الكوبونات</button><button v-if="auth.can('promotions.delete')" class="row-button danger" :disabled="mutation.busy.value" @click="remove(row)">حذف</button></div></div></td></tr></tbody></table><p v-if="!list.state.data.value.data.length" class="empty">لا توجد عروض مطابقة.</p></div><ApiPagination :meta="list.state.data.value?.meta" :links="list.state.data.value?.links" :busy="list.state.loading.value" @page="list.changePage"/><ApiFeedback :error="mutation.error.value" @retry="mutation.error.value=null"/></section><CouponsPanel @close="selected=null" v-if="selected&&auth.can('coupons.view')" :promotion="selected"/><PromotionEditor v-model:open="open" :record="editing" @saved="saved"/></main></template>
