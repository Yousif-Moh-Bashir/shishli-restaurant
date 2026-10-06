<script setup>
import { useRouter, RouterLink } from 'vue-router'
import { useCartStore } from '../../stores/cart'
import CartItems from '../../components/site/CartItems.vue'
import CartSummary from '../../components/site/CartSummary.vue'

const router = useRouter()
const cartStore = useCartStore()
void cartStore.initializeCart(true)
</script>

<template>
  <main id="main" class="container product-main cart-page" :aria-busy="cartStore.isLoading">
    <RouterLink class="auth-back" to="/menu">→ العودة للمنيو</RouterLink>
    <section aria-labelledby="cart-page-title">
      <div class="dialog-heading"><div><span class="section-kicker">طعمك المفضل، في مكان واحد</span><h2 id="cart-page-title">سلة طلباتك</h2></div></div>
      <p class="demo-note">راجع أطباقك وكمياتها قبل إكمال الطلب.</p>
      <p v-if="cartStore.pendingCheckout" role="alert">نتيجة طلبك السابق تحتاج إلى تحقق. <RouterLink to="/checkout">استعادة نتيجة الطلب</RouterLink></p>
      <div class="cart-page-layout"><CartItems id="cart-page-items" /><CartSummary total-id="cart-page-total" @continue="router.push('/menu')" /></div>
    </section>
  </main>
</template>

