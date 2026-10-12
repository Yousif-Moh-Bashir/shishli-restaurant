<script setup>
import { RouterLink } from 'vue-router'
import { branchesApi } from '../../api/branches.api'
import { useApiState } from '../../composables/useApiState'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'
import { validLocation } from '../../utils/location.js'
import { masifLocation } from '../../constants/branchLocations.js'
const list = useApiState(), detail = useApiState()
const map = branch => branch.slug === 'al-masif'
  ? masifLocation.mapUrl
  : validLocation(branch.location?.latitude, branch.location?.longitude)
    ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(branch.location.latitude + ',' + branch.location.longitude) : null
const address = branch => [branch.location?.city, branch.location?.district].filter(Boolean).join('، ')
function whatsapp(branch) {
  let phone = branch.contact?.whatsapp?.replace(/\D/g, '') || ''
  if (!phone) return null
  if (/^05\d{8}$/.test(phone)) phone = '966' + phone.slice(1)
  else if (phone.startsWith('00')) phone = phone.slice(2)
  return 'https://wa.me/' + phone
}
function load() { return list.run(signal => branchesApi.publicList(signal)) }
load()
</script>
<template>
  <main id="main" class="container section-space branches-page">
    <div class="section-heading"><div><span class="eyebrow">زورونا في تبوك</span><h1>فروع شيشلي</h1><p>نكهات شيشلي بالقرب منك. اعرف عنوان الفرع وافتح موقعه للوصول إلينا بسهولة.</p></div><RouterLink class="button button-primary" to="/menu">تصفح المنيو</RouterLink></div>
    <ApiFeedback variant="cards" :loading="list.loading.value" :error="list.error.value" @retry="load"/>
    <div class="branch-cards">
      <article v-for="branch in list.data.value || []" :key="branch.id" class="checkout-panel branch-card">
        <div class="branch-card-heading"><span class="branch-location-icon"><SiteIcon name="map-pin"/></span><div><h2>{{ branch.name }}</h2><p>{{ address(branch) }}</p></div></div>
        <p v-if="branch.location?.address" class="branch-address">{{ branch.location.address }}</p>
        <div class="branch-services"><span v-if="branch.supports_pickup"><SiteIcon name="shopping-bag"/>استلام من الفرع</span><span v-if="branch.supports_delivery"><SiteIcon name="truck"/>توصيل حسب نطاق الخدمة</span></div>
        <a v-if="branch.contact?.phone" class="branch-phone" :href="'tel:' + branch.contact.phone" :aria-label="'اتصل بـ' + branch.name + ' على ' + branch.contact.phone"><SiteIcon name="phone"/><span>اتصل بالفرع:</span><bdi dir="ltr">{{ branch.contact.phone }}</bdi></a>
        <div class="branch-actions"><a v-if="map(branch)" class="button button-primary" :href="map(branch)" target="_blank" rel="noopener noreferrer"><SiteIcon name="map-pin"/>الموقع على الخريطة</a><a v-if="whatsapp(branch)" class="button" :href="whatsapp(branch)" target="_blank" rel="noopener noreferrer">واتساب</a><button class="text-button" type="button" :disabled="detail.loading.value" @click="detail.run(signal => branchesApi.publicShow(branch.id, signal))">تفاصيل الفرع</button></div>
      </article>
    </div>
    <p v-if="list.data.value && !list.data.value.length" class="empty-search">لا توجد فروع متاحة.</p>
    <ApiFeedback variant="detail" :loading="detail.loading.value" :error="detail.error.value" @retry="detail.clear"/>
    <section v-if="detail.data.value" class="checkout-panel branch-details" aria-live="polite"><h2>{{ detail.data.value.name }}</h2><p>{{ address(detail.data.value) }}</p><p>{{ detail.data.value.location?.address }}</p><p>{{ detail.data.value.accepts_orders ? 'استقبال الطلبات مفعّل؛ يُراجع التوفر حسب ساعات العمل عند اختيار الفرع.' : 'استقبال الطلبات متوقف.' }}</p><div class="branch-actions"><a v-if="map(detail.data.value)" class="button button-primary" :href="map(detail.data.value)" target="_blank" rel="noopener noreferrer">الموقع على الخريطة</a><a v-if="detail.data.value.contact?.phone" class="text-button" :href="'tel:' + detail.data.value.contact.phone">اتصل بالفرع</a><a v-if="whatsapp(detail.data.value)" class="text-button" :href="whatsapp(detail.data.value)" target="_blank" rel="noopener noreferrer">واتساب الفرع</a></div></section>
  </main>
</template>
<style scoped>
.branches-page .section-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap}.branches-page .section-heading p{color:var(--muted);line-height:1.9;max-width:580px}.branch-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:24px}.branch-card{display:flex;flex-direction:column;gap:18px;min-width:0;padding:28px}.branch-card-heading{display:flex;gap:14px;align-items:center}.branch-card h2{margin:0 0 6px;font-size:22px}.branch-card-heading p{margin:0;color:var(--muted)}.branch-location-icon{display:flex;align-items:center;justify-content:center;flex-shrink:0;width:52px;height:52px;border-radius:14px;background:#f7eee6;color:var(--primary)}.branch-location-icon :deep(svg){width:26px;height:26px}.branch-address{margin:0;line-height:1.9}.branch-services{display:flex;gap:10px;flex-wrap:wrap}.branch-services span{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px}.branch-services :deep(svg){width:17px;height:17px}.branch-phone{display:inline-flex;align-items:center;gap:7px;align-self:flex-start;font-size:14px;text-decoration:none;color:var(--primary-dark)}.branch-phone :deep(svg){width:17px;height:17px;color:var(--primary)}.branch-phone bdi{font-size:16px;font-weight:600}.branch-phone:hover{text-decoration:underline}.branch-phone:focus-visible{outline:2px solid var(--primary);outline-offset:3px}.branch-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:auto}.branch-actions .button{min-height:44px;white-space:normal;justify-content:center}.branch-details{margin-top:24px;line-height:1.9}.branch-card p,.branch-details p{overflow-wrap:anywhere}@media(max-width:600px){.branch-card{padding:20px;gap:16px}.branch-actions>.button{flex:1 1 100%}.branch-cards{gap:16px}}
</style>
