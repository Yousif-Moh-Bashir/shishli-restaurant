<script setup>
import { computed, inject, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { catalogPage } from '../../api/catalogPages.api'
import AppSkeleton from '../../components/common/AppSkeleton.vue'
import { useApiState } from '../../composables/useApiState'
import { routeSeo, setCatalogSchema, setPageSeo } from '../../utils/seo'

const route = useRoute()
const prerender = inject('prerender', null)
const state = useApiState()
const page = computed(() => state.error.value ? null : state.data.value
  || (prerender?.value?.path === route.path ? prerender.value : null))
const related = computed(() => page.value?.links.filter(link => link.href.startsWith('/menu/category/')) || [])

async function load() {
  if (!['grills-tabuk', 'menu-category'].includes(route.name)) return
  const path = route.path.replace(/\/$/, '').toLowerCase()
  const result = await state.run(signal => catalogPage(path, signal))
  if (route.path.replace(/\/$/, '').toLowerCase() !== path) return
  if (result) {
    setPageSeo({ title: result.title, description: result.description, path: result.path })
    setCatalogSchema(result.schema)
  } else if (state.error.value?.status === 404) {
    setPageSeo({ ...routeSeo(route), title: 'التصنيف غير موجود | شيشلي فرش', index: false })
    setCatalogSchema(null)
  }
}
watch(() => route.path, load, { immediate: true })
</script>

<template>
  <main id="main" class="container section-space">
    <template v-if="page">
      <RouterLink class="text-button" to="/menu">المنيو الكامل</RouterLink>
      <h1>{{ page.heading }}</h1>
      <p>{{ page.intro }}</p>
      <section v-for="section in page.sections" :key="section.title" class="section-space">
        <h2>{{ section.title }}</h2>
        <p>{{ section.text }}</p>
        <RouterLink class="text-button" to="/branches">الفروع ومواقعها</RouterLink>
      </section>
      <nav v-if="related.length" class="catalog-category-links" aria-label="أقسام المشويات">
        <RouterLink v-for="link in related" :key="link.href" class="text-button" :to="link.href">{{ link.label }}</RouterLink>
      </nav>
      <div v-if="page.items.length" class="products-grid section-space">
        <article v-for="item in page.items" :key="item.href" class="product-card">
          <RouterLink v-if="item.image" :to="item.href" class="catalog-photo">
            <img :src="item.image" :alt="item.label" loading="lazy" decoding="async">
          </RouterLink>
          <div class="product-content">
            <h2><RouterLink :to="item.href">{{ item.label }}</RouterLink></h2>
            <p v-if="item.text">{{ item.text }}</p>
          </div>
        </article>
      </div>
      <p v-else class="section-space">لا توجد أصناف منشورة في هذه القائمة حاليًا.</p>
      <RouterLink class="button button-primary" to="/menu">اختر الفرع واطلب من المنيو</RouterLink>
    </template>
    <AppSkeleton v-else-if="state.loading.value" variant="page" label="جارٍ تحميل القائمة…"/>
    <div v-else-if="state.error.value" role="alert">
      <h1>{{ state.error.value.status === 404 ? 'التصنيف غير موجود' : 'تعذر تحميل القائمة' }}</h1>
      <RouterLink class="text-button" to="/menu">المنيو الكامل</RouterLink>
      <button v-if="state.error.value.status !== 404" type="button" class="button" @click="load">إعادة المحاولة</button>
    </div>
  </main>
</template>

<style scoped>
.catalog-category-links { display: flex; flex-wrap: wrap; gap: 1rem; margin-block-start: 1rem; }
.catalog-photo { display: block; aspect-ratio: 4 / 3; overflow: hidden; }
.catalog-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.product-content h2 { font-size: 1.15rem; }
</style>
