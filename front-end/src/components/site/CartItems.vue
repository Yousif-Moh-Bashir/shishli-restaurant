<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import { inject, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useCartStore } from '../../stores/cart'
import { formatCurrency } from '../../utils/formatters'
import SiteIcon from './SiteIcon.vue'
defineProps({ id: { type: String, default: 'cart-items' } })
const cart = useCartStore(), confirmation = ref(null)
const { toast } = inject('site')
async function feedback(request) { const result = await request; if (!result.ok) toast(result.message) }
async function clear() { const result = await cart.clearCart(); if (result.ok) confirmation.value.close(); else toast(result.message) }
</script>
<template>
<div :id="id" class="cart-items" :aria-busy="cart.isLoading || cart.isMutating">
  <AppSkeleton v-if="cart.isLoading" :variant="cart.cart?'inline':'list'" label="جارٍ تحميل السلة…"/>
  <div v-if="cart.error" role="alert" class="catalog-note"><p>{{ cart.error.message }}</p><p v-for="(messages,field) in cart.error.errors" :key="field">{{ messages.join(' ') }}</p><button class="text-button" :disabled="cart.isLoading || cart.isMutating" @click="cart.loadCart()">إعادة تحميل السلة</button></div>
  <p v-if="cart.notice" role="status" class="catalog-note">{{ cart.notice }}</p>
  <p v-if="cart.persistenceWarning" role="alert">{{ cart.persistenceWarning }}</p>
  <template v-if="cart.cart">
    <p class="cart-branch"><SiteIcon name="map-pin"/> {{ cart.cart.branch.name }} <span>{{ cart.itemsCount }} قطعة</span></p>
    <p v-for="(issue,index) in cart.issues" :key="index" role="alert" class="catalog-note">{{ issue.message }}</p>
    <p v-if="cart.cart.availability?.reason" role="status">{{ cart.cart.availability.reason.message }}</p>
    <div v-if="cart.items.length" class="cart-tools"><button class="text-button" :disabled="cart.isLoading || cart.isMutating" @click="feedback(cart.refreshCart())">تحديث السلة والأسعار</button><button class="text-button cart-clear" :disabled="cart.isLoading || cart.isMutating" @click="confirmation.showModal()">إفراغ السلة</button></div>
    <p v-if="cart.items.length" class="cart-price-note">الأسعار والتوفر يُراجعان عند تأكيد الطلب.</p>
  </template>
  <article v-for="item in cart.items" :key="item.id" class="cart-row">
    <div class="product-photo api-product-photo" role="img" :aria-label="item.product?.name || 'منتج غير متاح'"><img v-if="item.product?.primary_image?.url" :src="item.product.primary_image.url" alt="" loading="lazy"><SiteIcon v-else name="shopping-bag"/></div>
    <div class="cart-row-info"><h3>{{ item.product?.name || 'منتج غير متاح' }}</h3><p v-for="(option,index) in item.options" :key="index">{{ option.group.name }}: {{ option.value.name }} (+ {{ formatCurrency(option.price_modifier) }})</p><p>سعر الوحدة: {{ formatCurrency(item.pricing.unit_price) }}</p><p>الإجمالي: {{ formatCurrency(item.pricing.line_total_after_discount ?? item.pricing.line_total) }}</p><p v-if="item.pricing.discount_total">الخصم: {{ formatCurrency(item.pricing.discount_total) }}</p><p v-for="(issue,index) in item.issues" :key="index" role="alert">{{ issue.message }} <small>{{ issue.code }}</small></p><div class="quantity-controls"><button type="button" @click="feedback(cart.updateQuantity(item.id,item.quantity+1))" :aria-label="'زيادة كمية '+item.product?.name" :disabled="item.quantity>=50 || !item.is_available || cart.isMutating || cart.isLoading">+</button><span>{{ item.quantity }}</span><button type="button" @click="feedback(cart.updateQuantity(item.id,item.quantity-1))" :aria-label="'تقليل كمية '+item.product?.name" :disabled="item.quantity<=1 || !item.is_available || cart.isMutating || cart.isLoading">−</button><button class="remove-item" type="button" @click="feedback(cart.removeItem(item.id))" :disabled="cart.isMutating || cart.isLoading">حذف</button></div><RouterLink v-if="item.product?.id" class="text-button" :to="{name:'product',params:{slug:item.product.id},query:{cartItem:item.id}}">مراجعة وتعديل الخيارات</RouterLink></div>
  </article>
  <div v-if="cart.isEmpty && !cart.isLoading && !cart.error" class="cart-empty"><SiteIcon name="shopping-bag"/><p>سلتك بانتظار شيء لذيذ!</p><p>اختر الفرع وأطباقك المفضلة من المنيو.</p></div>
  <dialog ref="confirmation" class="info-dialog" aria-labelledby="clear-cart-title"><h2 id="clear-cart-title">إفراغ السلة؟</h2><p>سيتم حذف جميع المنتجات من السلة.</p><button class="button button-primary" :disabled="cart.isMutating" @click="clear">إفراغ السلة</button><button class="button" :disabled="cart.isMutating" @click="confirmation.close()">إلغاء</button></dialog>
</div>
</template>
