<script setup>
import {ref,useId,onBeforeUnmount} from 'vue'
import AdminIcon from './AdminIcon.vue'
const id=useId(),menu=ref(null),trigger=ref(null),open=ref(false)
function position(){
 if(!menu.value||!trigger.value)return
 const rect=trigger.value.getBoundingClientRect(),height=menu.value.offsetHeight,width=menu.value.offsetWidth
 menu.value.style.top=Math.max(8,Math.min(innerHeight-height-8,rect.bottom+height+8>innerHeight?rect.top-height-6:rect.bottom+6))+'px'
 menu.value.style.left=Math.max(8,Math.min(innerWidth-width-8,rect.right-width))+'px'
}
function toggled(event){open.value=event.newState==='open';if(open.value)position()}
function close(event){if(event.target.closest('button,a'))menu.value?.hidePopover()}
onBeforeUnmount(()=>menu.value?.hidePopover())
</script>
<template><button ref="trigger" class="row-button" type="button" :popovertarget="id" :aria-expanded="open" aria-haspopup="true" aria-label="المزيد من الإجراءات"><AdminIcon name="more-horizontal"/> المزيد</button><div :id="id" ref="menu" class="admin-action-popover" popover="auto" @toggle="toggled" @click="close"><slot/></div></template>
