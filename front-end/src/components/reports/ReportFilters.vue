<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import { reactive, watch, ref } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { productsApi } from '../../api/products.api'
import { categoriesApi, flattenCategories } from '../../api/categories.api'
import { useApiState } from '../../composables/useApiState'
import { useOrderBranches } from '../../composables/useOrderBranches'
import { orderStatusLabels } from '../../constants/orderStatus'
import { paymentMethodLabels } from '../../utils/paymentStatus'
import AdminFilterExtras from '../admin/AdminFilterExtras.vue'
import AdminIcon from '../admin/AdminIcon.vue'
const props = defineProps({ modelValue: { type: Object, required: true }, errors: Object, busy: Boolean, report: String })
const emit = defineEmits(['apply'])
const { branches, error: branchError, loading: branchesLoading } = useOrderBranches()
const draft = reactive({ ...props.modelValue })
const auth = useAuthStore(), catalog = useApiState(), productSearch = ref('')
async function loadCatalog() {
  await catalog.run(async signal => {
    const products = auth.can('products.view') ? await productsApi.list({ search: productSearch.value, per_page: 100 }, signal) : null
    const categories = auth.can('categories.view') ? await categoriesApi.tree() : await categoriesApi.publicList()
    return { products: products?.data || [], categories: flattenCategories(categories), more: products && products.meta.last_page > 1 }
  })
}
watch(() => props.report, value => { if (value === 'inventory') loadCatalog(); else catalog.clear() }, { immediate: true })
watch(() => props.modelValue, value => { for (const key of Object.keys(draft)) delete draft[key]; Object.assign(draft, value) })
const fieldError = key => props.errors?.[key]?.join(' ')
</script>
<template>
  <form class="panel report-filter-panel" @submit.prevent="emit('apply', { ...draft, page: 1 })">
    <div class="report-filters admin-filter-primary">
      <template v-if="report !== 'inventory'">
        <label>من تاريخ<input v-model="draft.date_from" name="date_from" type="date" :aria-invalid="!!fieldError('date_from')"><small class="report-field-error">{{ fieldError('date_from') }}</small></label>
        <label>إلى تاريخ<input v-model="draft.date_to" name="date_to" type="date" :aria-invalid="!!fieldError('date_to')"><small class="report-field-error">{{ fieldError('date_to') }}</small></label>
      </template>
      <label>الفرع<select v-model="draft.branch_uuid" name="branch_uuid"><option value="">كل الفروع</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option><option v-if="draft.branch_uuid && !branches.some(branch => branch.id === draft.branch_uuid)" :value="draft.branch_uuid">الفرع المحدد</option></select><small class="report-field-error">{{ fieldError('branch_uuid') }}</small></label>
      <button class="button" type="submit"><AdminIcon name="search"/> تطبيق الفلاتر</button>
    </div>
    <AdminFilterExtras :errors="errors">
      <template v-if="report !== 'inventory'">
        <label>نوع الطلب<select v-model="draft.order_type" name="order_type"><option value="">كل الأنواع</option><option value="pickup">استلام</option><option value="delivery">توصيل</option></select><small class="report-field-error">{{ fieldError('order_type') }}</small></label>
        <label>حالة الطلب<select v-model="draft.order_status" name="order_status"><option value="">كل الحالات</option><option v-for="(label, value) in orderStatusLabels" :key="value" :value="value">{{ label }}</option></select><small class="report-field-error">{{ fieldError('order_status') }}</small></label>
        <label>طريقة الدفع<select v-model="draft.payment_method" name="payment_method"><option value="">كل الطرق</option><option v-for="(label, value) in paymentMethodLabels" :key="value" :value="value">{{ label }}</option></select><small class="report-field-error">{{ fieldError('payment_method') }}</small></label>
        <label v-if="report === 'sales/trend' || report === 'dashboard'">تجميع المبيعات<select v-model="draft.group_by" name="group_by"><option value="day">يومي</option><option value="week">أسبوعي</option><option value="month">شهري</option></select><small class="report-field-error">{{ fieldError('group_by') }}</small></label>
        <label v-if="report === 'products'">ترتيب المنتجات<select v-model="draft.sort_by" name="sort_by"><option value="quantity">الكمية المباعة</option><option value="net_sales">المبيعات بعد الخصم</option><option value="orders_count">عدد الطلبات</option></select><small class="report-field-error">{{ fieldError('sort_by') }}</small></label>
        <label v-if="!['dashboard','sales/trend'].includes(report)">عدد الصفوف<select v-model.number="draft.per_page" name="per_page"><option :value="20">20</option><option :value="50">50</option><option :value="100">100</option></select><small class="report-field-error">{{ fieldError('per_page') }}</small></label>
      </template>
      <template v-else>
        <label>التصنيف<select v-model="draft.category_uuid" name="category_uuid"><option value="">كل التصنيفات</option><option v-for="category in catalog.data.value?.categories || []" :key="category.id" :value="category.id">{{ category.label }}</option></select><small class="report-field-error">{{ fieldError('category_uuid') }}</small></label>
        <template v-if="auth.can('products.view')"><label>بحث قائمة المنتجات<input v-model="productSearch" type="search" placeholder="ابحث لتحميل المنتج"><button type="button" class="row-button" :disabled="catalog.loading.value" @click="loadCatalog">تحميل المنتجات</button></label><label>المنتج<select v-model="draft.product_uuid" name="product_uuid"><option value="">كل المنتجات</option><option v-for="product in catalog.data.value?.products || []" :key="product.id" :value="product.id">{{ product.name }}</option></select><small class="report-field-error">{{ fieldError('product_uuid') }}</small></label></template>
        <label>بحث المنتجات<input v-model="draft.search" name="search" type="search" maxlength="180" placeholder="اسم المنتج أو رمزه"><small class="report-field-error">{{ fieldError('search') }}</small></label>
        <label class="report-check"><input v-model="draft.low_stock" type="checkbox">المخزون المنخفض</label>
        <label class="report-check"><input v-model="draft.out_of_stock" type="checkbox">المخزون النافد</label>
      </template>
    </AdminFilterExtras>
    <AppSkeleton v-if="branchesLoading" variant="inline" label="جارٍ تحميل الفروع…"/><p v-if="branchError" class="muted" role="status">تعذر تحميل قائمة الفروع: {{ branchError.message }}</p>
    <AppSkeleton v-if="catalog.loading.value" variant="inline" label="جارٍ تحميل قوائم المنتجات والتصنيفات…"/><p v-if="catalog.error.value" class="muted" role="status">تعذر تحميل قوائم المنتجات والتصنيفات: {{ catalog.error.value.message }}</p>
    <p v-if="catalog.data.value?.more" class="muted">توجد منتجات أخرى؛ استخدم بحث قائمة المنتجات للوصول إليها.</p>
    <p v-if="report !== 'inventory'" class="muted">ترك التاريخين فارغين يستخدم الفترة الافتراضية للمطعم. يمكن تغيير الفلاتر أثناء التحميل.</p>
  </form>
</template>
