<script setup>
import { inject } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import SiteIcon from './SiteIcon.vue'
import { useCartStore } from '../../stores/cart'

defineProps({ product: { type: Object, required: true } })
const { notifyCartResult } = inject('site')
const cartStore = useCartStore()
const router = useRouter()
async function addProduct(product) {
  const result = await cartStore.addItem(product)
  if (!result.ok && cartStore.error?.status === 422 && Object.keys(cartStore.error.errors).some(key => key.startsWith('options'))) { router.push({name:'product',params:{slug:product.id}}); return }
  notifyCartResult(result, product.name)
}
</script>

<template>
  <article class="product-card">
    <RouterLink class="product-image-link" :to="{ name: 'product', params: { slug: product.id } }" :aria-label="`تفاصيل ${product.name}`"><div class="product-photo api-product-photo" role="img" :aria-label="product.name"><img v-if="product.image" :src="product.image" alt="" loading="lazy"><span v-else class="api-no-image">لا توجد صورة للمنتج</span><span v-if="product.is_featured" class="product-tag">مميز</span></div></RouterLink>
    <div class="product-content">
      <h3><RouterLink :to="{ name: 'product', params: { slug: product.id } }">{{ product.name }}</RouterLink></h3>
      <p>{{ product.description }}</p>
      <div class="product-bottom"><span class="price">{{ product.price }} <small>ريال</small></span><button class="add-button" type="button" :disabled="product.available !== true || cartStore.isLoading || cartStore.isMutating" @click="addProduct(product)" :aria-label="`اختيار ${product.name}`"><SiteIcon name="shopping-bag" /> {{ !product.available ? 'غير متوفر' : 'إضافة للسلة' }}</button></div><p v-if="!product.available">{{ product.availability_message || 'غير متوفر في الفرع' }}</p>
    </div>
  </article>
</template>

