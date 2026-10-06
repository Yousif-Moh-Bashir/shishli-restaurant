<script setup>
import {computed,ref,inject,watch,nextTick} from 'vue'
import {RouterLink} from 'vue-router'
import {cmsApi} from '../../api/cms.api'
import {useCmsPage} from '../../composables/useCmsPage'
import {useApiState} from '../../composables/useApiState'
import {usePublicMenu} from '../../composables/usePublicMenu'
import {safeContentUrl} from '../../utils/contentUrl'
import SiteIcon from '../../components/site/SiteIcon.vue'
import ProductCard from '../../components/site/ProductCard.vue'
import BranchSelector from '../../components/site/BranchSelector.vue'
import SiteOffers from '../../components/site/SiteOffers.vue'
import AppSkeleton from '../../components/common/AppSkeleton.vue'
import ContentLink from '../../components/site/ContentLink.vue'
import SiteGallery from '../../components/site/SiteGallery.vue'
const page=useCmsPage('home'),menu=usePublicMenu(),gallery=useApiState(),bannerIndex=ref(0),aboutExpanded=ref(false),popularElement=ref(null),searchInput=ref(null)
const {searchOpen,closeSearch}=inject('site')
menu.featured.value='1'
const sections=computed(()=>Object.fromEntries((page.data.value?.page?.sections||[]).map(section=>[section.key,section])))
const section=key=>sections.value[key]||{}
const banner=computed(()=>page.data.value?.banners?.[bannerIndex.value])
watch(page.data,()=>bannerIndex.value=0)
const heroImage=computed(()=>safeContentUrl(banner.value?.image_url||section('hero').data?.image_url))
const heroStyle=computed(()=>heroImage.value?{'--home-hero-image':`url(${JSON.stringify(heroImage.value)})`,'--home-hero-mobile-image':`url(${JSON.stringify(safeContentUrl(banner.value?.mobile_image_url)||heroImage.value)})`}:undefined)
const heroTitle=computed(()=>banner.value?.title||section('hero').title)
const heroButton=kind=>({text:banner.value?.[kind==='primary'?'button_text':'secondary_button_text']||section('hero').data?.[kind+'_button_text']||(kind==='primary'?'اطلب الآن':'شاهد المنيو'),url:safeContentUrl(banner.value?.[kind==='primary'?'button_url':'secondary_button_url']||section('hero').data?.[kind+'_button_url'])||'/menu'})
const features=computed(()=>section('features').data?.items?.length?section('features').data.items:[{title:'دجاج طازج يوميًا',description:'من أفضل المزارع'},{title:'مشويات تركية',description:'على الفحم الطبيعي'},{title:'جودة عالية',description:'في جميع المكونات'},{title:'خدمة توصيل سريعة',description:'لجميع مناطق التوصيل'}])
const originalPhotos=['chicken','kebab','mixed','shish','rice'].map(photo=>({photo,title:'من أطباق شيشلي',description:'نكهة تركية أصيلة'}))
const galleryItems=computed(()=>gallery.data.value?.data||[])
function loadCms(){return page.run(signal=>cmsApi.home({branch:menu.branch.selectedUuid||undefined},signal))}
function loadGallery(){return gallery.run(signal=>cmsApi.gallery({per_page:6},signal))}
watch(()=>menu.branch.selectedUuid,loadCms);loadCms();loadGallery()
watch(searchOpen,async open=>{if(open){await nextTick();popularElement.value?.scrollIntoView({behavior:'smooth'});searchInput.value?.focus()}else menu.query.value=''})
</script>
<template>
<main id="main">
  <section class="hero" id="home" aria-labelledby="hero-title"><div class="hero-art" :class="{'home-custom-art':heroImage}" :style="heroStyle" role="img" aria-label="شيش طاووق ومشويات تركية مع الأرز والخضروات"></div><div class="hero-shade"></div><div class="container hero-inner"><div class="hero-copy">
    <span class="eyebrow"><span></span>{{ section('hero').subtitle||'من الفحم… إلى قلبك' }}</span><h1 id="hero-title"><template v-if="!heroTitle"><span class="restaurant-word">مطعم</span> شيشلي</template><template v-else>{{ heroTitle }}</template></h1><h2>للدجاج التركي والشيش</h2><p>{{ banner?.subtitle||section('hero').content||'مذاق تركي أصيل… يُحضّر يوميًا بعناية' }}</p>
    <div class="hero-buttons"><ContentLink class="button button-primary" :url="heroButton('primary').url"><SiteIcon name="utensils"/> {{ heroButton('primary').text }}</ContentLink><ContentLink class="button button-outline" :url="heroButton('secondary').url">{{ heroButton('secondary').text }} <SiteIcon name="arrow-left"/></ContentLink></div><div class="hero-note"><span class="tiny-line"></span> مكونات طازجة. تتبيلة أصيلة. طعم لا يُنسى.</div>
  </div></div><div class="hero-caption"><span></span> مشوي على الفحم، بكل حب</div><nav v-if="page.data.value?.banners?.length>1" class="home-banner-controls" aria-label="صور العرض الرئيسي"><button v-for="(item,index) in page.data.value.banners" :key="item.id" :aria-label="item.title||('الصورة '+(index+1))" :aria-pressed="bannerIndex===index" @click="bannerIndex=index"></button></nav></section>
  <section class="features" aria-label="ما يميز شيشلي"><div class="container features-grid"><article v-for="(item,index) in features" :key="index"><SiteIcon :name="['drumstick','flame','badge-check','truck'][index%4]"/><div><h3>{{ item.title }}</h3><p>{{ item.description }}</p></div></article></div></section>
  <section ref="popularElement" class="popular section-space container home-popular" id="popular" aria-labelledby="popular-title"><div class="section-heading"><div><span class="section-kicker">{{ section('featured_menu').subtitle||'من شوايتنا إلى مائدتك' }}</span><h2 id="popular-title">{{ section('featured_menu').title||'الأكثر طلبًا' }} <span class="title-flourish">✦</span></h2><p>{{ section('featured_menu').content||'أطباقنا المميزة التي يفضلها عملاؤنا' }}</p></div><RouterLink class="text-button" to="/menu">شاهد المنيو الكامل <SiteIcon name="arrow-left"/></RouterLink></div>
    <div class="search-bar" id="search-bar" :hidden="!searchOpen"><label for="product-search">ابحث عن طبقك المفضل</label><div><SiteIcon name="search"/><input ref="searchInput" id="product-search" v-model="menu.query.value" type="search" placeholder="دجاج، شيش، كباب…" autocomplete="off"><button class="icon-button" type="button" @click="closeSearch" aria-label="إغلاق البحث"><SiteIcon name="x"/></button></div></div><div class="popular-browse-panel"><div class="popular-browse-copy"><strong>اختيارات مميزة من المنيو</strong><p>اختر الفرع لعرض أطباقه وأسعاره وتوفرها.</p></div><BranchSelector/></div>
    <AppSkeleton v-if="menu.state.loading.value" variant="cards" label="جارٍ تحميل أطباق الفرع…"/><div v-else-if="menu.state.error.value" class="empty-search" role="alert"><p>تعذر تحميل الأطباق.</p><button class="text-button" @click="menu.load">إعادة المحاولة</button></div><div v-else class="products-grid" id="products-grid"><ProductCard v-for="product in menu.products.value" :key="product.id" :product="product"/></div><p v-if="!menu.state.loading.value&&!menu.state.error.value&&!menu.products.value.length" class="empty-search">{{ menu.branch.selectedUuid?'لا توجد أطباق مميزة مطابقة.':'اختر الفرع لعرض أطباقه وأسعاره.' }}</p>
  </section>
  <SiteOffers v-if="page.data.value?.active_offers?.length" :offers="page.data.value.active_offers" :title="section('offers').title" :subtitle="section('offers').subtitle"/>
  <section class="about container home-about" id="about" aria-labelledby="about-title">
    <div class="about-photo" role="img" aria-label="صورة توضيحية لواجهة مطعم شيشلي" :style="safeContentUrl(section('about_preview').data?.image_url)?{backgroundImage:`url(${JSON.stringify(safeContentUrl(section('about_preview').data?.image_url))})`,backgroundSize:'cover',backgroundPosition:'center'}:undefined"><span class="about-photo-caption"><SiteIcon name="flame"/> أصول تركية، ونكهة تجمعنا</span></div>
    <div class="about-copy"><span class="section-kicker">{{ section('about_preview').subtitle||'حكاية طعم… وأصول تركية' }}</span><h2 id="about-title">{{ section('about_preview').title||'أهلاً بكم في مطعم شيشلي' }}</h2><p class="about-description">{{ section('about_preview').content||'نقدم لكم أشهى المأكولات التركية الأصيلة بجودة عالية ومكونات طازجة، لنمنحكم تجربة طعام لا تُنسى.' }}</p>
      <div class="about-highlights"><span><SiteIcon name="flame"/> مذاق الفحم</span><span><SiteIcon name="utensils"/> نكهة تركية أصيلة</span></div>
      <div class="about-actions"><button class="button button-primary button-small" type="button" @click="aboutExpanded=!aboutExpanded" :aria-expanded="aboutExpanded" aria-controls="about-details">{{ aboutExpanded?'عرض أقل':'تعرف علينا أكثر' }} <SiteIcon name="arrow-left" :class="{'about-chevron-open':aboutExpanded}"/></button><RouterLink class="about-branches-link" to="/branches"><SiteIcon name="map-pin"/> استعرض فروعنا <SiteIcon name="arrow-left"/></RouterLink></div>
      <div id="about-details" class="about-expanded-copy" :hidden="!aboutExpanded"><p>من تتبيلة الدجاج إلى آخر لمسة على الفحم، نهتم بالتفاصيل التي تجعل وجبتكم مميزة. يسعدنا استقبالكم ومشاركتكم مذاق شيشلي.</p><RouterLink class="footer-link" to="/menu">اكتشف أطباقنا <SiteIcon name="arrow-left"/></RouterLink></div>
    </div>
  </section>
  <section class="gallery-banner container" id="gallery" aria-labelledby="gallery-title"><div class="gallery-banner-heading"><div><span class="section-kicker">لقطات تفتح النفس</span><h2 id="gallery-title">{{ section('gallery_preview').title||'معرض الصور' }}</h2><p>{{ section('gallery_preview').subtitle||'من أطباقنا… حكاية طعم في كل صورة' }}</p></div><RouterLink class="button button-outline" to="/gallery">استعرض المعرض <SiteIcon name="arrow-left"/></RouterLink></div><div class="gallery-carousel"><SiteGallery :items="galleryItems.length ? galleryItems : originalPhotos" carousel/><p v-if="gallery.error.value" class="home-gallery-error" role="status">تعذر تحميل الصور المنشورة. <button class="text-button" @click="loadGallery">إعادة المحاولة</button></p></div></section>
  <section class="cta container" aria-labelledby="cta-title"><div class="cta-symbol"><SiteIcon name="utensils"/></div><div><h2 id="cta-title">{{ section('cta').title||'جاهز لتجربة مذاق شيشلي؟' }}</h2><p>{{ section('cta').content||section('cta').subtitle||'اختر وجبتك المفضلة واطلبها الآن' }}</p></div><ContentLink class="button button-primary" :url="safeContentUrl(section('cta').data?.primary_button_url)||'/menu'">{{ section('cta').data?.primary_button_text||'ابدأ طلبك' }} <SiteIcon name="arrow-left"/></ContentLink></section>
  <section v-for="item in page.data.value?.secondary_banners||[]" :key="item.id" class="container home-secondary-banner"><img v-if="safeContentUrl(item.image_url)" class="cms-secondary-banner" :src="safeContentUrl(item.image_url)" :alt="item.title||''" loading="lazy"><h2>{{ item.title }}</h2><p>{{ item.subtitle }}</p><ContentLink v-if="safeContentUrl(item.button_url)&&item.button_text" class="button button-primary" :url="item.button_url">{{ item.button_text }}</ContentLink></section>
</main>
</template>

