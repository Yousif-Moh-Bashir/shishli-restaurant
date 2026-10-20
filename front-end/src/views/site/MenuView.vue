<script setup>
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import {computed,inject,ref,watch,nextTick} from 'vue'
import {RouterLink} from 'vue-router'
import ProductCard from '../../components/site/ProductCard.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'
import BranchSelector from '../../components/site/BranchSelector.vue'
import {usePublicMenu} from '../../composables/usePublicMenu'
import {useCartStore} from '../../stores/cart'
const {openCart,searchRequest}=inject('site'),menu=usePublicMenu(),searchInput=ref(null),catalogElement=ref(null)
const cart=useCartStore()
const hasFilters=computed(()=>Boolean(menu.query.value||menu.category.value||menu.available.value||menu.featured.value))
const categoryLabel=computed(()=>menu.categories.value.find(item=>item.id===menu.category.value)?.label||'كل الأطباق')
function resetFilters(){menu.query.value='';menu.category.value='';menu.available.value='';menu.featured.value=''}
watch(searchRequest,async()=>{await nextTick();catalogElement.value?.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});searchInput.value?.focus({preventScroll:true})})
</script>
<template><main id="main" class="site-menu">
<section class="menu-intro"><div class="container"><RouterLink class="menu-breadcrumb" to="/">الرئيسية / المنيو</RouterLink><span class="section-kicker">طازج من شوايتنا</span><h1>اختر وجبتك… على ذوقك</h1><p>دجاج تركي، شيش ومشويات على الفحم. أضف أطباقك المفضلة وراجع طلبك في السلة.</p></div></section>
<section id="popular" ref="catalogElement" class="container section-space menu-catalog" aria-labelledby="catalog-title">
<div class="section-heading"><div><span class="section-kicker">أطباقك المفضلة، على ذوقك</span><h2 id="catalog-title">منيو شيشلي فرش</h2></div><button class="button button-primary menu-cart-button" type="button" @click="openCart"><SiteIcon name="shopping-bag"/> مراجعة الطلب <span v-if="cart.itemsCount" class="menu-cart-count">{{ cart.itemsCount }}</span></button></div>
<div class="menu-browse-panel">
<div class="shop-toolbar"><div class="search-bar"><label for="product-search">ابحث عن وجبتك</label><div><SiteIcon name="search"/><input id="product-search" ref="searchInput" v-model="menu.query.value" type="search" maxlength="150" placeholder="دجاج، شيش، كباب…" autocomplete="off"><button class="icon-button" type="button" aria-label="مسح البحث" @click="menu.query.value='';searchInput?.focus()"><SiteIcon name="x"/></button></div></div><BranchSelector/></div>
<AppSkeleton v-if="menu.categoriesLoading.value" variant="inline" label="جارٍ تحميل التصنيفات…"/><div class="category-filters" role="group" aria-label="تصنيف الأطباق"><button type="button" :aria-pressed="menu.category.value===''" @click="menu.category.value=''">الكل</button><button v-for="category in menu.categories.value" :key="category.id" type="button" :aria-pressed="menu.category.value===category.id" @click="menu.category.value=category.id">{{ category.label }}</button></div>
<details class="site-menu-filters"><summary>خيارات التصفية <SiteIcon name="search"/></summary><div class="shop-toolbar"><label class="shop-sort">التوفر <select v-model="menu.available.value"><option value="">كل الأطباق</option><option value="1">المتوفرة فقط</option><option value="0">غير المتوفرة</option></select></label><label class="shop-sort">التمييز <select v-model="menu.featured.value"><option value="">الكل</option><option value="1">المميزة فقط</option><option value="0">غير المميزة</option></select></label></div></details>
<p v-if="menu.categoriesError.value" role="alert">{{ menu.categoriesError.value.message }} <button class="text-button" @click="menu.loadCategories">إعادة تحميل التصنيفات</button></p>
</div>
<div class="menu-results-heading"><div><h3>{{ categoryLabel }}</h3><p v-if="menu.state.data.value&&!menu.state.loading.value" role="status">{{ menu.products.value.length }} طبق{{ menu.query.value?' · نتائج البحث عن «'+menu.query.value+'»':'' }}</p><p v-else-if="menu.state.loading.value" role="status">جارٍ تحميل الأطباق…</p></div><button v-if="hasFilters" class="text-button menu-reset" type="button" @click="resetFilters">مسح الفلاتر <SiteIcon name="x"/></button></div>
<p v-if="!menu.branch.selectedBranch&&!menu.branch.isLoading&&!menu.branch.error" class="empty-search">اختر الفرع لعرض المنيو وأسعاره.</p>
<AppSkeleton v-if="menu.state.loading.value" variant="cards" label="جارٍ تحميل منيو الفرع…"/>
<div v-else-if="menu.state.error.value" class="empty-search" role="alert"><p>{{ menu.state.error.value.message }}</p><button class="button button-primary" @click="menu.load">إعادة المحاولة</button></div>
<template v-else-if="menu.state.data.value"><p v-if="menu.state.data.value.availability?.reason" class="catalog-note">{{ menu.state.data.value.availability.reason.message }}</p><div class="products-grid"><ProductCard v-for="product in menu.products.value" :key="product.id" :product="product"/></div><div v-if="!menu.products.value.length" class="empty-search menu-empty"><SiteIcon name="search"/><h3>لا توجد أطباق مطابقة</h3><p>جرّب اسمًا آخر أو غيّر خيارات التصفية في هذا الفرع.</p><button v-if="hasFilters" class="button button-primary" type="button" @click="resetFilters">عرض كل الأطباق</button></div></template>
<div class="menu-catalog-footer"><p>الأسعار والتوفر حسب الفرع المختار، وتُراجع عند تأكيد الطلب.</p><RouterLink class="text-button" to="/offers">استعرض العروض الحالية <SiteIcon name="arrow-left"/></RouterLink></div>
</section></main></template>

