<script setup>
import {computed} from 'vue'
import {RouterLink} from 'vue-router'
import {safeContentUrl} from '../../utils/contentUrl'
const props=defineProps({url:String})
const href=computed(()=>safeContentUrl(props.url))
const internal=computed(()=>{if(!href.value)return null;const url=new URL(href.value,location.origin);return url.origin===location.origin?url.pathname+url.search+url.hash:null})
</script>
<template><RouterLink v-if="internal" :to="internal"><slot/></RouterLink><a v-else-if="href" :href="href" rel="noopener noreferrer"><slot/></a><span v-else><slot/></span></template>
