<script setup>
import {ref,useId} from 'vue'
import {useCartStore} from '../../stores/cart'
const cart=useCartStore(),code=ref(''),notice=ref(''),id=useId()
async function apply(){const result=await cart.applyCoupon(code.value);notice.value=result.ok?'تم تطبيق الكوبون.':result.message}
async function remove(){const result=await cart.removeCoupon();notice.value=result.ok?'تمت إزالة الكوبون.':result.message}
</script>
<template><div class="cart-coupon"><p v-if="cart.cart?.coupon" class="coupon-applied">الكوبون: <bdi>{{ cart.cart.coupon.code }}</bdi> <button class="text-button" type="button" :disabled="cart.isMutating||cart.checkoutBusy||!!cart.pendingCheckout" @click="remove">إزالة الكوبون</button></p><form v-else @submit.prevent="apply"><label :for="id">كود الكوبون</label><div class="coupon-controls"><input :id="id" v-model="code" required maxlength="64" autocomplete="off" dir="ltr" spellcheck="false" placeholder="أدخل كود الخصم" :disabled="cart.isMutating||!!cart.pendingCheckout"><button class="button button-primary" :disabled="cart.isMutating||cart.isLoading||cart.checkoutBusy||!!cart.pendingCheckout">تطبيق</button></div></form><p v-if="notice" class="coupon-notice" role="status">{{ notice }}</p><p v-for="promotion in cart.cart?.promotions||[]" :key="promotion.promotion_uuid||promotion.id||promotion.name" class="muted">{{ promotion.name }}</p></div></template>

<style scoped>
.cart-coupon form{min-width:0}
.cart-coupon form .coupon-controls{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:stretch;gap:8px;width:100%;min-width:0}
.cart-coupon form .coupon-controls input{box-sizing:border-box;width:100%;min-width:0;min-height:44px;border:1px solid var(--border);border-radius:8px;padding:10px 12px;background:#fffdfa;color:var(--primary-dark);font:inherit;font-size:13px;line-height:1.6;text-align:start}
.cart-coupon form .coupon-controls input::placeholder{direction:rtl;text-align:right;font-size:12px;color:var(--muted)}
.cart-coupon form .coupon-controls input:focus{outline:2px solid #d7192033;outline-offset:2px;border-color:#d71920}
.cart-coupon form .coupon-controls .button{display:flex;align-items:center;justify-content:center;min-width:72px;min-height:44px;margin:0;padding:10px 14px;box-sizing:border-box;border-radius:8px;background:#d71920;border-color:#d71920;color:white;font-size:13px;line-height:1.6;white-space:nowrap}
.coupon-applied{display:flex;align-items:center;flex-wrap:wrap;gap:10px;line-height:1.8}.coupon-applied bdi{background:#f3ece9;border:1px dashed var(--border);border-radius:5px;padding:3px 8px;font-weight:700;overflow-wrap:anywhere;max-width:100%}.coupon-applied .text-button{margin-inline-start:auto;min-height:36px;font-size:12px}
.coupon-notice{margin:12px 0 0;padding:10px;border-radius:7px;background:#f8f2ed;font-size:12px;line-height:1.8;overflow-wrap:anywhere}
@media(max-width:360px){.cart-coupon form .coupon-controls{grid-template-columns:minmax(0,1fr)}.cart-coupon form .coupon-controls .button{width:100%}}
</style>