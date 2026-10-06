<script setup>
import {notificationLabel,notificationBadge} from '../../utils/notificationStatus'
defineProps({status:String})
</script>
<template><span class="badge notification-badge" :class="notificationBadge(status)">{{ notificationLabel(status) }}</span></template>
