<script setup>
import {RouterLink} from 'vue-router'
import {formatCurrency} from '../../utils/formatters'
defineProps({offers:{type:Array,default:()=>[]},title:String,subtitle:String})
</script>
<template><section v-if="offers.length" id="offers" class="offers-section container"><div class="section-heading"><div><span class="section-kicker">{{ subtitle }}</span><h2>{{ title||'عروض شيشلي' }}</h2><p>يطبّق الخادم الخصم المناسب بعد مراجعة السلة.</p></div></div><div class="offers-grid"><article v-for="offer in offers" :key="offer.id" class="offer-card"><div class="offer-content"><span class="offer-badge">{{ offer.type==='coupon'?'كوبون':'عرض تلقائي' }}</span><h3>{{ offer.name }}</h3><p>{{ offer.description }}</p><div class="offer-prices"><strong>{{ offer.discount.type==='percentage'?offer.discount.value+'٪':formatCurrency(offer.discount.value) }}</strong><span>{{ offer.discount.type==='fixed_price'?'سعر خاص':'خصم' }}</span></div><p v-if="offer.minimum_subtotal">الحد الأدنى: {{ formatCurrency(offer.minimum_subtotal) }}</p><RouterLink class="button button-primary" to="/menu">اختر أطباقك</RouterLink></div></article></div></section></template>
