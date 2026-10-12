<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import { RouterLink } from 'vue-router'
import { useCartStore } from '../../stores/cart'
import { formatCurrency } from '../../utils/formatters'
import SiteIcon from './SiteIcon.vue'
import CartCoupon from './CartCoupon.vue'
import RegistrationOffer from './RegistrationOffer.vue'
defineProps({ totalId: { type: String, default: 'cart-total' } })
defineEmits(['continue'])
const cart = useCartStore()
</script>
<template>
  <aside class="cart-summary" aria-label="ملخص الطلب" :aria-busy="cart.isLoading||cart.isMutating">
    <div class="cart-summary-heading"><span class="cart-summary-icon"><SiteIcon name="shopping-bag"/></span><div><h3 class="cart-summary-title">ملخص الطلب</h3><p v-if="cart.itemsCount">{{ cart.itemsCount }} قطعة في سلتك</p></div></div>
    <AppSkeleton v-if="cart.isLoading&&!cart.cart" variant="list" :rows="3" label="جارٍ تحميل إجماليات السلة…"/>
    <template v-if="cart.cart && cart.itemsCount">
      <RegistrationOffer placement="cart"/>
      <div class="cart-summary-line"><span>إجمالي المنتجات</span><strong>{{ formatCurrency(cart.subtotal) }}</strong></div>
      <div v-if="Number(cart.discount)>0" class="cart-summary-line cart-discount"><span>الخصم</span><strong>− {{ formatCurrency(cart.discount) }}</strong></div>
      <details class="cart-summary-coupon" :open="!!cart.cart.coupon"><summary><span>{{ cart.cart.coupon?'كوبون الخصم المطبق':'لديك كود خصم؟' }}</span><span aria-hidden="true">+</span></summary><CartCoupon/></details>
      <div class="cart-summary-line cart-grand-total"><div><span>إجمالي الطلب</span><small>قبل رسوم التوصيل</small></div><strong :id="totalId">{{ formatCurrency(cart.total) }}</strong></div>
      <p class="cart-delivery-note"><SiteIcon name="truck"/><span>تُحدد رسوم التوصيل في الخطوة التالية حسب عنوانك.</span></p>
      <RouterLink class="button button-primary checkout-link" to="/checkout">مراجعة وإكمال الطلب <SiteIcon name="arrow-left"/></RouterLink>
      <p v-if="!cart.cart.can_checkout" class="catalog-note" role="status">الطلب الفوري غير متاح حاليًا. راجع مشكلات المنتجات وحالة الفرع، أو اختر موعدًا إذا كانت الجدولة متاحة.</p>
    </template>
    <p v-else-if="!cart.isLoading" class="cart-price-note">{{ cart.error?'تعذر تحميل ملخص الطلب. أعد تحميل السلة لعرض الإجمالي.':'أضف أطباقك المفضلة ليظهر ملخص طلبك هنا.' }}</p>
    <button class="button cart-continue" type="button" @click="$emit('continue')">{{ cart.itemsCount?'إضافة أطباق أخرى':'استعرض المنيو' }} <SiteIcon name="arrow-left"/></button>
  </aside>
</template>
