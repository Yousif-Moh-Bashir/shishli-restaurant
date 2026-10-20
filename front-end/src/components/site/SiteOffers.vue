<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import SiteIcon from './SiteIcon.vue'
import { formatDateTime } from '../../utils/formatters'
import { promotionPresentation } from '../../utils/promotionPresentation'
import { usePublicSettingsStore } from '../../stores/publicSettings'

const props = defineProps({ offers: { type: Array, default: () => [] }, title: String, subtitle: String, showHeading: { type: Boolean, default: true } })
const settings = usePublicSettingsStore()
const cards = computed(() => props.offers.map(offer => ({ id: offer.id, ...promotionPresentation(offer) })))
const timezone = computed(() => settings.data?.restaurant?.timezone || 'Asia/Riyadh')
</script>

<template>
  <section v-if="cards.length" id="offers" class="offers-section site-offers container" aria-label="عروض شيشلي فرش">
    <div v-if="showHeading" class="section-heading site-offers-heading">
      <div><span v-if="subtitle" class="section-kicker">{{ subtitle }}</span><h2>{{ title || 'عروض شيشلي فرش' }}</h2><p>اختر ما تحب، وتُحتسب الخصومات المؤهلة في السلة.</p></div>
      <span class="site-offers-mark"><SiteIcon name="flame"/></span>
    </div>
    <div class="site-offers-grid">
      <article v-for="card in cards" :key="card.id" class="promotion-card">
        <div class="promotion-value-panel">
          <div class="promotion-value"><span class="promotion-value-label">{{ card.valueLabel }}</span><strong :aria-label="card.value"><bdi>{{ card.amount }}</bdi><small v-if="card.currency"> ريال</small></strong></div>
          <div class="promotion-kind"><span class="promotion-icon"><SiteIcon :name="card.coupon ? 'shopping-bag' : 'badge-check'"/></span><span>{{ card.coupon ? 'كوبون خصم' : 'عرض تلقائي' }}</span></div>
        </div>
        <div class="promotion-body">
          <h3 class="promotion-title" :title="card.name">{{ card.name }}</h3>
          <p v-if="card.summary" class="promotion-description">{{ card.summary }}</p>
          <ul v-if="card.conditions.length" class="promotion-conditions" aria-label="شروط العرض"><li v-for="condition in card.conditions" :key="condition"><SiteIcon name="badge-check"/><span>{{ condition }}</span></li></ul>
          <div class="promotion-footer">
            <p v-if="card.endsAt" class="promotion-expiry"><SiteIcon name="clock"/><span>ينتهي <time :datetime="card.endsAt">{{ formatDateTime(card.endsAt, timezone) }}</time></span></p>
            <RouterLink class="button button-primary promotion-action" :to="card.href" :aria-label="card.action + ': ' + card.name">{{ card.action }}<SiteIcon name="arrow-left"/></RouterLink>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.offers-section.site-offers{margin-top:32px;padding:24px;background:#f8efe6;border:1px solid #eadfd6;border-radius:16px;min-width:0}
.site-offers-heading{margin-bottom:22px}.site-offers-heading>div{min-width:0}.site-offers-heading h2{font-size:28px}.site-offers-heading p{font-size:13px;line-height:1.8}.site-offers-mark{display:grid;place-items:center;width:44px;height:44px;flex-shrink:0;border:1px solid #5a17251c;border-radius:12px;color:var(--primary,#5a1725);background:#fff8f2}.site-offers-mark .icon{width:24px;height:24px}
.site-offers-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:18px;align-items:stretch}
.promotion-card{display:flex;flex-direction:column;min-width:0;border:1px solid #e5d5ce;border-radius:12px;overflow:hidden;background:#fffdfa;box-shadow:0 4px 14px #35101705}
.promotion-value-panel{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;background:linear-gradient(115deg,#351017,#5a1725);color:#fff8f2}
.promotion-value{min-width:0}.promotion-value-label{display:block;font-size:12px;color:#f0cfb0;line-height:1.6}.promotion-value strong{display:block;font-size:clamp(34px,4vw,46px);font-weight:900;line-height:1.2;letter-spacing:-1px;overflow-wrap:anywhere}.promotion-value bdi{unicode-bidi:isolate}
.promotion-value strong small{font-size:15px;font-weight:600;letter-spacing:0;margin-inline-start:5px;white-space:nowrap}
.promotion-kind{display:flex;flex-direction:column;align-items:center;gap:6px;flex-shrink:0;font-size:10px;color:#f7e0d8}.promotion-icon{display:grid;place-items:center;width:38px;height:38px;border:1px solid #ffffff30;border-radius:11px;background:#ffffff0d}.promotion-icon .icon{width:22px;height:22px;stroke:#f4d6b3}
.promotion-body{display:flex;flex-direction:column;flex:1;min-width:0;gap:12px;padding:18px 20px}.promotion-title{font-size:18px;font-weight:800;line-height:1.6;color:var(--primary-dark,#351017);overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}.promotion-description{font-size:13px;line-height:1.8;color:#76615d;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
.promotion-conditions{list-style:none;padding:0;margin:0;display:grid;gap:6px}.promotion-conditions li{display:flex;align-items:flex-start;gap:7px;font-size:11px;line-height:1.8;color:#65443f;overflow-wrap:anywhere}.promotion-conditions .icon{width:14px;height:14px;flex-shrink:0;margin-top:3px;stroke:#8a4b43}.promotion-conditions span{min-width:0}
.promotion-footer{display:grid;gap:12px;margin-top:auto;padding-top:2px}.promotion-expiry{display:flex;align-items:flex-start;gap:6px;color:#80645e;font-size:11px;line-height:1.8;overflow-wrap:anywhere}.promotion-expiry .icon{width:14px;height:14px;flex-shrink:0;margin-top:3px}.promotion-expiry time{unicode-bidi:plaintext}.promotion-action.button{width:100%;min-width:0;min-height:44px;box-sizing:border-box;justify-content:space-between;gap:10px;margin:0;padding:10px 14px;border-radius:8px;background:var(--secondary,#d71920);color:white;font-size:13px;line-height:1.6;white-space:normal;text-align:start;box-shadow:none}.promotion-action .icon{width:17px;height:17px;flex-shrink:0}.promotion-action:focus-visible{outline:3px solid var(--gold,#c9963b);outline-offset:3px}
@media(max-width:599px){.offers-section.site-offers{padding:16px;margin-top:24px;border-radius:12px}.site-offers-grid{gap:14px}.site-offers-heading{gap:10px;margin-bottom:16px}.site-offers-heading h2{font-size:24px}.site-offers-heading p{font-size:12px}.promotion-value-panel{padding:16px;gap:12px}.promotion-body{padding:16px;gap:10px}.promotion-title{font-size:17px}.promotion-value strong{font-size:40px}.promotion-description{font-size:12px}}
</style>
