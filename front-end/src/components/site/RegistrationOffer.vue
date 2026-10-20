<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useRegistrationOfferStore } from '../../stores/registrationOffer'
import SiteIcon from './SiteIcon.vue'

const props = defineProps({ placement: { type: String, default: 'card' }, showAction: { type: Boolean, default: true } })
const auth = useAuthStore(), campaign = useRegistrationOfferStore(), route = useRoute()
const offer = computed(() => !auth.token && auth.isInitialized ? campaign.activeOffer : null)
const discount = computed(() => Number(offer.value?.discount.value).toLocaleString('ar-SA') + '٪')
const destination = computed(() => ({ name: 'register', query: { redirect: props.placement === 'cart' ? '/checkout' : route.fullPath } }))
</script>

<template>
  <section v-if="offer" class="registration-offer" :class="'registration-offer--'+placement" aria-label="عرض إنشاء حساب">
    <div class="registration-offer-inner" :class="{container:placement==='bar'}">
      <template v-if="placement==='bar'">
        <span class="registration-offer-tag" aria-hidden="true">مكافأة التسجيل</span>
        <div class="registration-offer-ticker">
          <span class="registration-offer-accessible">انضم إلى عائلة شيشلي فرش واستمتع بخصم {{ discount }} على طلبك الأول عند إنشاء حسابك. سجّل الآن، واطلب أشهى وجباتك بسعر أقل!</span>
          <div class="registration-offer-track" aria-hidden="true">
            <div v-for="group in 2" :key="group" class="registration-offer-group">
              <template v-for="item in 2" :key="item">
                <span dir="rtl">انضم إلى عائلة <strong>شيشلي فرش</strong> واستمتع بخصم <strong>{{ discount }} على طلبك الأول</strong> عند إنشاء حسابك <span class="registration-offer-dot">◆</span></span>
                <span dir="rtl">سجّل الآن، واطلب أشهى وجباتك <strong>بسعر أقل!</strong> <span class="registration-offer-dot">◆</span></span>
              </template>
            </div>
          </div>
        </div>
      </template>
      <span v-else-if="placement==='cart'" class="registration-offer-copy">سجّل الآن ووفر <strong>{{ discount }}</strong> على طلبك!</span>
      <span v-else-if="route.name==='register'" class="registration-offer-copy">سجّل الآن واحصل على <strong>خصم {{ discount }}</strong></span>
      <span v-else class="registration-offer-copy">أول طلب؟ <strong>لك خصم {{ discount }}</strong></span>
      <RouterLink v-if="showAction" class="registration-offer-action" :to="destination">سجّل الآن <SiteIcon name="arrow-left"/></RouterLink>
    </div>
  </section>
</template>

<style scoped>
.registration-offer{margin:12px 0;border:1px solid #c9963b28;border-radius:12px;background:linear-gradient(110deg,#fffaf1,#fffdf9);color:var(--primary,#5a1725)}.registration-offer-inner{display:flex;align-items:center;justify-content:center;gap:16px;padding:9px 14px;min-width:0}.registration-offer-copy{font-size:13px;line-height:1.6;white-space:nowrap;color:#765a55}.registration-offer-copy strong{font-weight:800;color:var(--primary,#5a1725);margin-inline-start:4px}.registration-offer-action{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;min-height:28px;flex-shrink:0;border-radius:20px;background:#5a172508;color:var(--primary,#5a1725);box-shadow:inset 0 0 0 1px #5a172516;text-decoration:none;font-size:11px;font-weight:700;white-space:nowrap;transition:background .15s}.registration-offer-action .icon{width:12px;height:12px}.registration-offer-action:hover{background:#5a172512}.registration-offer-action:focus-visible{outline:2px solid #c9963b;outline-offset:3px}.registration-offer--bar{margin:0;border:0;border-bottom:1px solid #c9963b22;border-radius:0;background:linear-gradient(90deg,#faf6ee,#fffaf1 50%,#faf6ee)}.registration-offer--bar .registration-offer-inner{padding-block:5px}.registration-offer--card .registration-offer-inner{justify-content:center}.registration-offer--cart .registration-offer-inner{gap:10px;padding:10px}
@media(max-width:430px){.registration-offer-inner{gap:10px;padding-inline:10px}.registration-offer-copy{font-size:12px}.registration-offer-action{padding-inline:10px;font-size:10px}.registration-offer--cart .registration-offer-inner{flex-wrap:wrap;gap:6px 10px}}
.registration-offer--bar{background:linear-gradient(100deg,#43111d,#651e2e);border-bottom:1px solid #c9963b66;color:#fff}.registration-offer--bar .registration-offer-inner{gap:12px;padding-block:5px;flex-wrap:nowrap}.registration-offer-tag{flex-shrink:0;padding:4px 8px;border-radius:4px;background:#edcb8d;color:#491725;font-size:10px;font-weight:800;white-space:nowrap}.registration-offer-ticker{flex:1;min-width:0;overflow:hidden;direction:ltr;mask-image:linear-gradient(to right,transparent,#000 14px,#000 calc(100% - 14px),transparent)}.registration-offer-track{display:flex;width:max-content;direction:ltr;transform:translateX(-50%);animation:registration-news 28s linear infinite;will-change:transform}.registration-offer-group{display:flex;align-items:center;justify-content:space-around;gap:28px;padding-inline:14px;min-width:max(650px,calc(100vw - 230px));flex-shrink:0;box-sizing:border-box}.registration-offer-group>span{font-size:12px;line-height:24px;white-space:nowrap;color:#fff2e6}.registration-offer-group strong{font-weight:800;color:#f0cf93}.registration-offer-dot{margin-inline-start:22px;font-size:8px;color:#d0a35e}.registration-offer--bar .registration-offer-action{background:transparent;box-shadow:none;border-inline-start:1px solid #ffffff28;border-radius:0;color:#f2d6a4;padding-inline:12px 0}.registration-offer--bar .registration-offer-action:hover{color:#fff;background:transparent}.registration-offer--bar:hover .registration-offer-track,.registration-offer--bar:focus-within .registration-offer-track{animation-play-state:paused}.registration-offer-accessible{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
@keyframes registration-news{from{transform:translateX(-50%)}to{transform:translateX(0)}}
@media(max-width:430px){.registration-offer--bar .registration-offer-inner{gap:8px}.registration-offer-tag{font-size:9px;padding-inline:6px}.registration-offer-group>span{font-size:11px}.registration-offer--bar .registration-offer-action{padding-inline-start:8px}.registration-offer--bar .registration-offer-action .icon{display:none}}
@media(prefers-reduced-motion:reduce){.registration-offer-track{animation:none;transform:none;width:100%;will-change:auto}.registration-offer-ticker{mask-image:none}.registration-offer-group{min-width:0;display:block;padding:0;width:100%}.registration-offer-group:nth-child(2),.registration-offer-group>span:not(:first-child),.registration-offer-dot{display:none}.registration-offer-group>span{white-space:normal;display:block;line-height:1.5}}
</style>
