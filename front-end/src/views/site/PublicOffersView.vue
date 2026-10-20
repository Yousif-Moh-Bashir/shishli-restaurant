<script setup>
import {watch} from 'vue'
import {publicPromotionsApi} from '../../api/promotions.api'
import {useBranchStore} from '../../stores/branch'
import {useServerList} from '../../composables/useServerList'
import SiteOffers from '../../components/site/SiteOffers.vue'
import BranchSelector from '../../components/site/BranchSelector.vue'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
const branch=useBranchStore(),list=useServerList(publicPromotionsApi)
function load(){list.filters.branch_uuid=branch.selectedUuid||'';return list.search()}
watch(()=>branch.selectedUuid,load);branch.load().then(load)
</script>
<template><main id="main" class="section-space"><div class="container section-heading public-offers-heading"><h1>عروض شيشلي فرش</h1><BranchSelector/></div><div class="container"><ApiFeedback variant="cards" :loading="list.state.loading.value" :error="list.state.error.value" @retry="load"/><p v-if="list.state.data.value&&!list.state.data.value.data.length" class="empty-search">لا توجد عروض سارية لهذا الفرع.</p></div><SiteOffers :show-heading="false" :offers="list.state.data.value?.data||[]"/><div class="container"><ApiPagination :meta="list.state.data.value?.meta" :links="list.state.data.value?.links" :busy="list.state.loading.value" @page="list.changePage"/></div></main></template>
<style scoped>
.public-offers-heading{align-items:center;gap:18px}.public-offers-heading h1{line-height:1.4}
@media(max-width:599px){.public-offers-heading{flex-direction:column;align-items:stretch;gap:14px}.public-offers-heading h1{font-size:30px}}
</style>
