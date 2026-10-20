<script setup>
import {watch,ref} from 'vue'
import {useRoute} from 'vue-router'
import {cmsApi} from '../../api/cms.api'
import {useCmsPage} from '../../composables/useCmsPage'
import {useApiState} from '../../composables/useApiState'
import CmsSection from '../../components/site/CmsSection.vue'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import SiteGallery from '../../components/site/SiteGallery.vue'
const route=useRoute(),key=route.meta.cmsKey,page=useCmsPage(key),gallery=useApiState(),galleryPage=ref(1)
function loadGallery(){return gallery.run(signal=>cmsApi.gallery({page:galleryPage.value,per_page:24},signal))}
page.load();if(key==='gallery')loadGallery();watch(galleryPage,loadGallery)
</script>
<template>
  <main id="main">
    <div v-if="key!=='gallery' && (page.loading.value||page.error.value)" class="container section-space"><h1 v-if="page.error.value">{{ page.error.value.status===404?'الصفحة غير منشورة':'تعذر تحميل الصفحة' }}</h1><ApiFeedback variant="page" :loading="page.loading.value" :error="page.error.value" @retry="page.load"/></div>
    <template v-if="page.data.value"><CmsSection v-for="section in page.data.value.sections" :key="section.id" :section="section" :page-title="page.data.value.title"/></template>
    <section v-if="key==='gallery'" class="container section-space" aria-label="معرض الصور">
      <div v-if="!page.data.value?.sections?.length" class="section-heading"><div><span class="section-kicker">لقطات من شيشلي فرش</span><h1>معرض الصور</h1><p>من أطباقنا وأجوائنا… حكاية في كل صورة.</p></div></div>
      <ApiFeedback variant="cards" :loading="gallery.loading.value" :error="gallery.error.value" @retry="loadGallery"/>
      <SiteGallery :items="gallery.data.value?.data||[]"/>
      <p v-if="gallery.data.value&&!gallery.data.value.data.length" class="empty-search">لا توجد صور منشورة حاليًا.</p>
      <ApiPagination :meta="gallery.data.value?.meta" :links="gallery.data.value?.links" :busy="gallery.loading.value" @page="galleryPage=$event"/>
    </section>
  </main>
</template>
