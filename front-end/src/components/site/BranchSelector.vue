<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import PublicOperationStatus from './PublicOperationStatus.vue'
import { ref, inject, watch } from 'vue'
import { useBranchStore } from '../../stores/branch'
import { useCartStore } from '../../stores/cart'
import {useOperationsStore} from '../../stores/operations'
const operations=useOperationsStore()
const branch = useBranchStore(), cart = useCartStore(), confirmation = ref(null), target = ref('')
const { toast } = inject('site')
branch.load()
watch(()=>branch.branches,values=>values.forEach(item=>operations.load(item.id)),{immediate:true})
function statusLabel(id){const value=operations.status[id];return !value?'حالة غير متاحة':value.accepts_orders?'يستقبل الطلبات':value.is_open?'مفتوح — الطلب متوقف':'مغلق'}
async function change(event) {
  target.value = event.target.value
  event.target.value = branch.selectedUuid
  if (!target.value || target.value === branch.selectedUuid) return
  if (cart.items.length) confirmation.value.showModal()
  else await commit(false)
}
async function commit(clear) {
  const result = await cart.changeBranch(target.value, clear)
  if (!result.ok) { toast(result.message); if(!cart.items.length&&!cart.pendingCheckout&&!cart.checkoutBusy)branch.select(target.value) }
  confirmation.value?.close()
}
</script>
<template><div class="shop-sort branch-selector"><label for="site-branch">اختر الفرع</label><AppSkeleton v-if="branch.isLoading" variant="inline" label="جارٍ تحميل الفروع…"/><select id="site-branch" :value="branch.selectedUuid" :disabled="branch.isLoading || cart.isLoading || cart.isMutating" @change="change"><option value="">{{ branch.isLoading?'جارٍ تحميل الفروع…':'اختر فرعًا لعرض الأسعار والتوفر' }}</option><option v-if="branch.selectedUuid&&!branch.selectedBranch" :value="branch.selectedUuid">{{ cart.cart?.branch.name || 'فرع السلة' }}</option><option v-for="item in branch.branches" :key="item.id" :value="item.id">{{ item.name }}</option></select><PublicOperationStatus/><p v-if="branch.error" role="alert">{{ branch.error.message }} <button class="text-button" type="button" @click="branch.load(true)">إعادة المحاولة</button></p><p v-else-if="!branch.isLoading&&!branch.branches.length" role="status">لا توجد فروع نشطة حاليًا.</p><dialog ref="confirmation" class="info-dialog" aria-labelledby="branch-change-title"><h2 id="branch-change-title">تغيير الفرع</h2><p>السلة مرتبطة بفرع واحد. تغيير الفرع يتطلب حذف جميع منتجات السلة أولًا. إذا تعذر تغيير الفرع بعد الإفراغ ستبقى السلة فارغة في الفرع الحالي.</p><button class="button button-primary" :disabled="cart.isMutating" @click="commit(true)">إفراغ السلة وتغيير الفرع</button><button class="button" :disabled="cart.isMutating" @click="confirmation.close()">إلغاء</button></dialog></div></template>

