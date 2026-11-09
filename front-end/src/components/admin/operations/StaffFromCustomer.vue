<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { staffApi } from '../../../api/adminOperations.api'
import { useApiMutation } from '../../../composables/useApiState'
import { useModalDraft } from '../../../composables/useModalDraft'
import AdminModal from '../AdminModal.vue'
import DomainFields from '../DomainFields.vue'
import PhoneInput from '../../common/PhoneInput.vue'
import ApiFeedback from '../catalog/ApiFeedback.vue'

const props = defineProps({ roleOptions: { type: Array, default: () => [] }, initialPhone: { type: String, default: '' } })
const emit = defineEmits(['close', 'saved'])
const opened = ref(false), customer = ref(null), mutation = useApiMutation()
const form = reactive({ phone: props.initialPhone, roles: [], password: '', confirmed: false })
const fields = computed(() => [
  { key: 'roles', label: 'الأدوار الإدارية', type: 'multi', options: props.roleOptions },
  { key: 'password', label: 'كلمة المرور الإدارية الجديدة', type: 'password', required: true, minlength: 12, max: 72, help: '12 حرفًا على الأقل، بحروف كبيرة وصغيرة وأرقام. ستحل محل كلمة مرور الحساب الحالية.' },
])
const beforeClose = useModalDraft(opened, form, mutation.busy, customer)
async function submit() {
  if (mutation.busy.value) return
  if (!customer.value) {
    const result = await mutation.run(() => staffApi.customerLookup(form.phone))
    if (result.ok) customer.value = result.data
    return
  }
  const result = await mutation.run(() => staffApi.fromCustomer({ customer_uuid: customer.value.id, roles: form.roles, password: form.password, confirmed: form.confirmed }))
  if (result.ok) { form.password = ''; opened.value = false; emit('saved', result.data) }
}
function changeCustomer() { customer.value = null; form.password = ''; form.roles = []; form.confirmed = false; mutation.error.value = null }
onMounted(() => { opened.value = true })
onBeforeUnmount(() => { form.password = '' })
</script>
<template>
  <AdminModal :open="opened" title="إضافة موظف من حساب عميل موجود" :busy="mutation.busy.value" :before-close="beforeClose" @update:open="!$event && emit('close')" @submit="submit">
    <p class="form-note">استخدم الحساب المسجل بالرقم نفسه دون إنشاء حساب مكرر. تبقى طلباته وعناوينه وبياناته محفوظة.</p>
    <template v-if="!customer">
      <label class="field">رقم جوال العميل<PhoneInput v-model="form.phone" required :disabled="mutation.busy.value" /></label>
      <p v-if="mutation.error.value?.status === 404" class="field-error" role="alert">لم يُعثر على حساب عميل نشط قابل للإضافة بهذا الرقم. تحقّق من الرقم؛ الحسابات المرتبطة بصلاحيات أخرى تُدار من صفحاتها الحالية.</p>
    </template>
    <template v-else>
      <section class="staff-existing-customer" aria-label="الحساب المختار"><strong>{{ customer.name }}</strong><bdi>{{ customer.phone }}</bdi><button type="button" class="row-button" :disabled="mutation.busy.value" @click="changeCustomer">البحث عن حساب آخر</button></section>
      <DomainFields :fields="fields" :form="form" :errors="mutation.error.value?.errors" :disabled="mutation.busy.value" />
      <p class="form-note">سيصبح الحساب قادرًا على دخول الإدارة حسب الأدوار المختارة، وستنتهي جلساته الحالية ويحتاج لتسجيل الدخول بكلمة المرور الجديدة.</p>
      <label class="check"><input v-model="form.confirmed" type="checkbox" required :disabled="mutation.busy.value">تحققت من هوية صاحب الحساب وأوافق على منحه الأدوار المختارة وتغيير كلمة مروره.</label>
    </template>
    <ApiFeedback v-if="customer || mutation.error.value?.status !== 404" :error="mutation.error.value" @retry="mutation.error.value = null" />
    <div class="form-actions"><button type="button" class="row-button" :disabled="mutation.busy.value" @click="beforeClose() && emit('close')">إلغاء</button><button class="button" type="submit" :disabled="mutation.busy.value || !!customer && (!form.roles.length || !form.confirmed)">{{ mutation.busy.value ? 'جارٍ التنفيذ…' : customer ? 'إضافة الحساب إلى الموظفين' : 'البحث عن حساب العميل' }}</button></div>
  </AdminModal>
</template>
<style scoped>
.staff-existing-customer{display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding:16px;background:#faf7f2;border:1px solid var(--border,#e9dfd8);border-radius:10px;margin:16px 0}
.staff-existing-customer strong{flex:1 1 160px}.staff-existing-customer bdi{overflow-wrap:anywhere}.field,.check{margin-block:16px}.check{align-items:flex-start;line-height:1.8}
@media(max-width:480px){.staff-existing-customer{align-items:flex-start;flex-direction:column}.staff-existing-customer strong{flex:auto}.staff-existing-customer button{width:100%}.form-actions>button{min-height:44px}}
</style>
