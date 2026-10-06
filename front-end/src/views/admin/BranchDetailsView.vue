<script setup>
import AdminTabs from '../../components/admin/AdminTabs.vue'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import BranchOperations from '../../components/admin/operations/BranchOperations.vue'
import AdminSectionHeading from '../../components/admin/AdminSectionHeading.vue'
import {ref,watch} from 'vue'
import {useRoute,RouterLink} from 'vue-router'
import {branchesApi} from '../../api/branches.api'
import {useAuthStore} from '../../stores/auth'
import {useApiState} from '../../composables/useApiState'
import {normalizeApiError} from '../../utils/apiError'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import CatalogEditor from '../../components/admin/catalog/CatalogEditor.vue'
import BranchProductsPanel from '../../components/admin/catalog/BranchProductsPanel.vue'
const route=useRoute(),state=useApiState(),auth=useAuthStore(),editorOpen=ref(false),detailTab=ref('info')
function load(){state.run(()=>branchesApi.show(route.params.uuid))}
async function refresh(){const id=route.params.uuid;try{const value=await branchesApi.show(id);if(route.params.uuid===id)state.data.value=value}catch(cause){state.error.value=normalizeApiError(cause)}}
watch(()=>route.params.uuid,load,{immediate:true})
</script>
<template><main id="main" class="admin-detail-page"><AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>{{ state.data.value?.name||'تفاصيل الفرع' }}</h1><RouterLink class="row-button" to="/admin/branches">العودة للفروع</RouterLink></div></AdminPageHeader><ApiFeedback variant="detail" :loading="state.loading.value" :error="state.error.value" @retry="load"/><template v-if="state.data.value"><AdminTabs v-model="detailTab" :items="[{key:'info',label:'بيانات الفرع'},{key:'operations',label:'التشغيل وساعات العمل'},{key:'products',label:'المنتجات والأسعار'}]" label="إدارة الفرع"><div v-show="detailTab==='info'" class="detail-summary-grid"><section class="panel detail-record-hero"><AdminSectionHeading icon="map-pin">بيانات الفرع</AdminSectionHeading><div class="catalog-tags"><span class="badge" :class="state.data.value.is_active?'good':'off'">{{ state.data.value.is_active?'نشط':'متوقف' }}</span><span class="badge" :class="state.data.value.accepts_orders?'good':'off'">{{ state.data.value.accepts_orders?'يستقبل الطلبات':'لا يستقبل الطلبات' }}</span><span v-if="state.data.value.supports_pickup" class="badge">استلام</span><span v-if="state.data.value.supports_delivery" class="badge">توصيل</span></div><dl class="order-facts"><dt>العنوان</dt><dd>{{ state.data.value.location?.address || '—' }}</dd><dt>المدينة والحي</dt><dd>{{ [state.data.value.location?.city,state.data.value.location?.district].filter(Boolean).join('، ') || '—' }}</dd><dt>الهاتف</dt><dd><bdi>{{ state.data.value.contact?.phone || '—' }}</bdi></dd><dt>واتساب</dt><dd><bdi>{{ state.data.value.contact?.whatsapp || '—' }}</bdi></dd></dl><button v-if="auth.can('branches.update')" class="button" @click="editorOpen=true">تعديل الفرع</button></section><section v-if="auth.can('delivery_zones.view')" class="panel"><AdminSectionHeading icon="truck">مناطق التوصيل</AdminSectionHeading><RouterLink class="button" :to="{name:'admin.delivery-zones',query:{branch:state.data.value.id}}">إدارة مناطق توصيل الفرع</RouterLink></section></div><BranchOperations v-show="detailTab==='operations'" v-if="auth.can('branches.hours.view')||auth.can('branches.closures.view')||auth.can('branches.view')" :branch-id="state.data.value.id" @updated="refresh"/><BranchProductsPanel v-show="detailTab==='products'" :branch="state.data.value.id"/></AdminTabs><CatalogEditor v-model:open="editorOpen" :record="state.data.value" kind="branches" :api="branchesApi" @saved="load"/></template></main></template>

