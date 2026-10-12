<script setup>
import {RouterLink} from 'vue-router'
import AddressBook from '../../components/site/AddressBook.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'
</script>
<template><main id="main" class="container addresses-main"><header class="addresses-heading"><div><span class="section-kicker">حسابي</span><h1>عناوين التوصيل</h1><p>عناوينك جاهزة، لطلب أسهل في كل مرة.</p></div><RouterLink class="addresses-back" to="/account/profile">العودة لحسابي <SiteIcon name="arrow-left"/></RouterLink></header><nav class="addresses-nav" aria-label="روابط حسابي"><RouterLink to="/account/profile"><SiteIcon name="user-round"/> حسابي</RouterLink><RouterLink to="/account/orders"><SiteIcon name="shopping-bag"/> طلباتي</RouterLink><RouterLink to="/account/addresses" aria-current="page"><SiteIcon name="map-pin"/> عناويني</RouterLink></nav><AddressBook management/></main></template>
<style scoped>
.addresses-main{padding-block:36px 64px;max-width:1120px}
.addresses-heading{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:24px}.addresses-heading h1{font-size:30px;margin-block:4px}.addresses-heading p{font-size:14px;color:var(--muted)}.addresses-back{display:inline-flex;align-items:center;gap:8px;color:var(--primary);font-size:13px;font-weight:700}.addresses-back .icon{width:17px}.addresses-nav{display:flex;gap:8px;padding:7px;background:#5a172506;border:1px solid var(--border);border-radius:12px;margin-bottom:28px}.addresses-nav a{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:8px 20px;color:var(--muted);font-size:13px;border-radius:8px}.addresses-nav .icon{width:18px;height:18px}.addresses-nav a[aria-current=page]{background:#fff;color:var(--primary);font-weight:700;box-shadow:0 2px 5px #35101709}
.addresses-heading>div{min-width:0;overflow-wrap:anywhere}
@media(max-width:600px){.addresses-main{padding-block:24px 40px}.addresses-heading h1{font-size:26px}.addresses-heading>div{flex-basis:100%}.addresses-heading{gap:10px}.addresses-nav{gap:4px;margin-bottom:24px}.addresses-nav a{flex:1;padding:8px 4px;font-size:12px;gap:6px}.addresses-nav .icon{width:16px;height:16px}}
</style>
