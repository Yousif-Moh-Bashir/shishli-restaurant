<script setup>
import PublicBusinessHours from "./PublicBusinessHours.vue"
import SiteIcon from './SiteIcon.vue'
import { RouterLink } from 'vue-router'
import { ref } from 'vue'
import { useRestaurantInfo } from '../../composables/useRestaurantInfo'
const restaurant=useRestaurantInfo()
const socialDialog = ref(null)
const selectedSocial = ref('')
const socialAccounts = [
  { name: 'إنستغرام', icon: 'instagram' },
  { name: 'تيك توك', icon: 'music-2' },
  { name: 'سناب شات', icon: 'ghost' },
]
function openSocial(name) {
  selectedSocial.value = name
  socialDialog.value?.showModal()
}
</script>
<template>
  <footer class="site-footer" id="contact"><div class="container footer-grid">
    <div class="footer-brand"><RouterLink to="/#home" class="brand" aria-label="شيشلي، العودة إلى الرئيسية"><span class="brand-image" role="img" aria-label="شعار مطعم شيشلي"></span></RouterLink><p>أصول تركية، ونكهة تجمعنا.</p></div>
    <div id="location"><h3><SiteIcon name="map-pin" /> موقعنا</h3><p v-if="restaurant.address">{{ restaurant.address }}</p><a v-if="restaurant.mapHref" class="footer-link" :href="restaurant.mapHref" target="_blank" rel="noopener noreferrer">اعرض الموقع على الخريطة <SiteIcon name="arrow-up-left" /></a><RouterLink v-else class="footer-link" to="/branches">استعرض فروعنا <SiteIcon name="arrow-up-left"/></RouterLink></div>
    <div><h3><SiteIcon name="phone" /> اتصل بنا</h3><a v-if="restaurant.phoneHref" class="phone-number" :href="restaurant.phoneHref" dir="ltr">{{ restaurant.phone }}</a><RouterLink v-else class="footer-link" to="/contact">تواصل معنا</RouterLink><p>يسعدنا تواصلكم واستفساراتكم</p></div>
    <div><h3><SiteIcon name="clock" /> أوقات العمل</h3><PublicBusinessHours/></div>
  </div><div class="container footer-bottom"><p>© 2026 مطعم شيشلي للدجاج التركي - جميع الحقوق محفوظة</p><div class="social-links" aria-label="التواصل الاجتماعي"><span>تابعنا على</span><button v-for="account in socialAccounts" :key="account.name" type="button" :data-social="account.name" :aria-label="account.name" @click="openSocial(account.name)"><SiteIcon :name="account.icon"/></button><a v-if="restaurant.whatsappHref" :href="restaurant.whatsappHref" target="_blank" rel="noopener noreferrer" aria-label="تواصل عبر واتساب"><SiteIcon name="message-circle" /></a></div></div>
  <dialog ref="socialDialog" class="info-dialog" aria-labelledby="social-dialog-title" @click="event => { if(event.target === socialDialog) socialDialog.close() }">
    <div class="dialog-heading"><h2 id="social-dialog-title">شيشلي على {{ selectedSocial }}</h2><button type="button" class="icon-button" aria-label="إغلاق" @click="socialDialog.close()"><SiteIcon name="x"/></button></div>
    <p>رابط الحساب الرسمي غير متاح حاليًا. يسعدنا تواصلك معنا.</p>
    <a v-if="restaurant.whatsappHref" class="button button-primary" :href="restaurant.whatsappHref" target="_blank" rel="noopener noreferrer">تواصل عبر واتساب</a>
    <RouterLink v-else class="button button-primary" to="/contact" @click="socialDialog.close()">تواصل معنا</RouterLink>
  </dialog></footer>
</template>
