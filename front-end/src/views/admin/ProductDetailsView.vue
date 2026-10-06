<script setup>
import AdminTabs from '../../components/admin/AdminTabs.vue'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AdminIcon from '../../components/admin/AdminIcon.vue'
import AdminSectionHeading from '../../components/admin/AdminSectionHeading.vue'
import ProductImage from '../../components/admin/catalog/ProductImage.vue'
import {ref,watch} from 'vue'
import {useRoute,RouterLink} from 'vue-router'
import {useAuthStore} from '../../stores/auth'
import {productsApi} from '../../api/products.api'
import {useApiState} from '../../composables/useApiState'
import {formatCurrency} from '../../utils/formatters'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import CatalogEditor from '../../components/admin/catalog/CatalogEditor.vue'
import ProductImagesPanel from '../../components/admin/catalog/ProductImagesPanel.vue'
import ProductOptionsPanel from '../../components/admin/catalog/ProductOptionsPanel.vue'
import ProductBranchesPanel from '../../components/admin/catalog/ProductBranchesPanel.vue'
const route=useRoute(),state=useApiState(),auth=useAuthStore(),editorOpen=ref(false),detailTab=ref('info')
function load(){state.run(()=>productsApi.show(route.params.uuid))}
watch(()=>route.params.uuid,load,{immediate:true})
</script>
<template>
  <main id="main" class="admin-detail-page">
    <AdminPageHeader class="page-heading detail-page-heading">
      <div><RouterLink class="detail-back" to="/admin/products"><AdminIcon name="arrow-left"/> المنتجات</RouterLink><h1>{{ state.data.value?.name || 'تفاصيل المنتج' }}</h1><p class="muted">بيانات المنتج وصوره وخياراته وتوفّره في الفروع</p></div>
      <button v-if="state.data.value && auth.can('products.update')" class="button" @click="editorOpen=true"><AdminIcon name="pencil"/> تعديل المنتج</button>
    </AdminPageHeader>
    <ApiFeedback variant="detail" :loading="state.loading.value" :error="state.error.value" @retry="load"/>
    <template v-if="state.data.value">
      <section class="panel product-profile">
        <div class="product-profile-photo"><ProductImage :image="state.data.value.primary_image?.url" :name="state.data.value.name"/></div>
        <div class="product-profile-copy">
          <span class="eyebrow">{{ state.data.value.category?.name || 'المنتج' }}</span>
          <h2>{{ state.data.value.name }}</h2>
          <p v-if="state.data.value.short_description" class="admin-detail-description">{{ state.data.value.short_description }}</p>
          <div class="catalog-tags"><span class="badge" :class="state.data.value.is_active?'good':'off'">{{ state.data.value.is_active?'نشط':'متوقف' }}</span><span class="badge" :class="state.data.value.is_available?'good':'off'">{{ state.data.value.is_available?'متوفر عالميًا':'غير متوفر عالميًا' }}</span><span v-if="state.data.value.is_featured" class="badge">منتج مميز</span></div>
          <div class="product-profile-price"><span>السعر الأساسي</span><strong>{{ formatCurrency(state.data.value.base_price) }}</strong></div>
        </div>
      </section>
      <AdminTabs v-model="detailTab" :items="[{key:'info',label:'المعلومات'},{key:'images',label:'الصور'},{key:'options',label:'الخيارات'},{key:'branches',label:'الفروع والأسعار'}]" label="إدارة المنتج"><div v-show="detailTab==='info'" class="detail-summary-grid">
        <section class="panel"><AdminSectionHeading icon="utensils">معلومات المنتج</AdminSectionHeading><dl class="order-facts"><dt>رمز المنتج</dt><dd><bdi>{{ state.data.value.sku || 'غير محدد' }}</bdi></dd><dt>وقت التحضير</dt><dd>{{ state.data.value.preparation_time==null?'غير محدد':state.data.value.preparation_time+' دقيقة' }}</dd><dt>متابعة المخزون</dt><dd>{{ state.data.value.tracks_inventory?'مفعّلة':'غير مفعّلة' }}</dd></dl></section>
        <section class="panel"><AdminSectionHeading icon="message-circle">وصف المنتج</AdminSectionHeading><p class="admin-detail-description" :class="{muted:!state.data.value.description}">{{ state.data.value.description || 'لم يُضف وصف تفصيلي لهذا المنتج.' }}</p></section>
      </div>
      <div class="detail-resources"><ProductImagesPanel v-show="detailTab==='images'" :product="state.data.value" @changed="load"/><ProductOptionsPanel v-show="detailTab==='options'" :product="state.data.value" @changed="load"/><ProductBranchesPanel v-show="detailTab==='branches'" :product="state.data.value" @changed="load"/></div></AdminTabs>
      <CatalogEditor v-model:open="editorOpen" :record="state.data.value" kind="products" :api="productsApi" @saved="load"/>
    </template>
  </main>
</template>
