<script setup>
import {reactive,watch,ref,computed} from 'vue'
import {useUnsavedForm} from '../../composables/useUnsavedForm'
import {RouterLink} from 'vue-router'
import {profileApi} from '../../api/customers.api'
import {useApiState,useApiMutation} from '../../composables/useApiState'
import {useAuthStore} from '../../stores/auth'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
const state=useApiState(),mutation=useApiMutation(),auth=useAuthStore(),form=reactive({name:'',email:''}),notice=ref('')
function load(){return state.run(signal=>profileApi.show(signal))}
const dirty=computed(()=>state.data.value&& (form.name!==state.data.value.name||form.email!==(state.data.value.email||'')))
useUnsavedForm(dirty,mutation.busy)
watch(state.data,value=>{if(value)Object.assign(form,{name:value.name,email:value.email||''})})
async function save(){const result=await mutation.run(()=>profileApi.update({name:form.name,email:form.email||null}));if(result.ok){state.data.value=result.data;auth.user={...auth.user,name:result.data.name,email:result.data.email};notice.value='تم حفظ بيانات الحساب.'}}
async function claim(){const result=await mutation.run(()=>profileApi.claim());if(result.ok){notice.value='تم ربط '+result.data.claimed_orders+' من الطلبات السابقة بحسابك.';load()}}
load()
</script>
<template><main id="main" class="container checkout-main"><div class="section-heading"><h1>حسابي</h1></div><nav class="module-tabs"><RouterLink class="text-button" to="/account/orders">طلباتي</RouterLink><RouterLink class="text-button" to="/account/addresses">عناويني</RouterLink></nav><ApiFeedback variant="detail" :loading="state.loading.value" :error="state.error.value" @retry="load"/><section v-if="state.data.value" class="checkout-panel"><form @submit.prevent="save"><div class="auth-field"><label for="profile-name">الاسم</label><input id="profile-name" v-model="form.name" required maxlength="150" :disabled="mutation.busy.value"><small v-if="mutation.error.value?.errors.name" role="alert">{{ mutation.error.value.errors.name.join(' ') }}</small></div><div class="auth-field"><label for="profile-email">البريد الإلكتروني</label><input id="profile-email" v-model="form.email" type="email" maxlength="255" :disabled="mutation.busy.value"><small v-if="mutation.error.value?.errors.email" role="alert">{{ mutation.error.value.errors.email.join(' ') }}</small></div><p>الهاتف: <bdi>{{ state.data.value.phone }}</bdi></p><p class="muted">تغيير الهاتف والتحقق منه غير متاحين حاليًا في النظام.</p><ApiFeedback :error="mutation.error.value" @retry="mutation.error.value=null"/><p v-if="notice" role="status">{{ notice }}</p><button class="button button-primary" :disabled="mutation.busy.value">حفظ الحساب</button></form><div v-if="state.data.value.phone_verified" class="detail-section"><h2>ربط طلبات الضيف السابقة</h2><p>سيبحث الخادم عن الطلبات المطابقة لهاتف حسابك الموثّق.</p><button class="button" :disabled="mutation.busy.value" @click="claim">ربط الطلبات</button></div></section></main></template>
