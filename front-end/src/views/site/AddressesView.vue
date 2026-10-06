<script setup>
import {RouterLink} from 'vue-router'
import {useAuthStore} from '../../stores/auth'
import AddressBook from '../../components/site/AddressBook.vue'
const auth=useAuthStore()
</script>
<template><main id="main" class="container product-main"><div class="section-heading"><div><span class="section-kicker">حسابي</span><h1>عناوين التوصيل</h1><p>{{ auth.user?.name }}</p></div><RouterLink class="button" to="/account/orders">طلباتي</RouterLink><RouterLink class="button" to="/menu">العودة للمنيو</RouterLink></div><AddressBook/></main></template>
