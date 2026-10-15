<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import {adminRoleLabel,adminDomainLabel,adminPermissionLabel} from '../../constants/adminPresentation'
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import {ref,computed,inject} from 'vue'
import {rolesApi} from '../../api/adminOperations.api'
import {useApiState,useApiMutation} from '../../composables/useApiState'
import {useAuthStore} from '../../stores/auth'
import ApiFeedback from '../../components/admin/catalog/ApiFeedback.vue'
import {useUnsavedForm} from '../../composables/useUnsavedForm'
const auth=useAuthStore(),{notify}=inject('admin'),state=useApiState(),mutation=useApiMutation(),selected=ref(''),permissions=ref([])
const role=computed(()=>state.data.value?.roles.find(r=>r.name===selected.value)),editable=computed(()=>auth.hasRole('super_admin')&&auth.can('roles.update_permissions')&&!['super_admin','customer','driver'].includes(selected.value))
async function load(){const value=await state.run(async signal=>({roles:await rolesApi.list(signal),groups:await rolesApi.permissions(signal)}));if(value)choose(selected.value||value.roles[0]?.name)}
const dirty=computed(()=>JSON.stringify([...permissions.value].sort())!==JSON.stringify([...(role.value?.permissions||[])].sort()))
useUnsavedForm(dirty,mutation.busy)
function choose(value){if(value!==selected.value&&dirty.value&&!confirm('التغييرات غير محفوظة. تغيير الدور؟'))return;selected.value=value;permissions.value=[...(role.value?.permissions||[])]}
async function save(){if(!editable.value||!(await confirmAction('تحديث صلاحيات الدور لجميع الموظفين المرتبطين به؟')))return;const result=await mutation.run(()=>rolesApi.update(selected.value,permissions.value));if(result.ok){notify('تم تحديث صلاحيات الدور.');await load();await auth.fetchMe()}}
load()
</script>
<template><main id="main"><AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>الأدوار والصلاحيات</h1><p class="muted">حدد ما يمكن لكل دور عرضه وإدارته. أدوار المدير العام والعميل ومندوب التوصيل محمية من التعديل.</p></div></AdminPageHeader><ApiFeedback :loading="state.loading.value" :error="state.error.value" @retry="load"/><form v-if="state.data.value" class="panel" @submit.prevent="save"><label class="field">الدور<select :value="selected" :disabled="mutation.busy.value" @change="choose($event.target.value)"><option v-for="r in state.data.value.roles" :key="r.name" :value="r.name">{{ adminRoleLabel(r.name) }}</option></select></label><div class="permission-grid"><fieldset v-for="(names,group) in state.data.value.groups" :key="group"><legend>{{ adminDomainLabel(group) }}</legend><label v-for="name in names" :key="name" class="check"><input v-model="permissions" type="checkbox" :value="name" :disabled="!editable||mutation.busy.value"><span>{{ adminPermissionLabel(name) }}</span></label></fieldset></div><ApiFeedback :error="mutation.error.value" @retry="mutation.error.value=null"/><button v-if="editable" class="button" :disabled="mutation.busy.value">حفظ الصلاحيات</button></form></main></template>
