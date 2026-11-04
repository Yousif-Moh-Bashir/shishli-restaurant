<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useFinanceSession } from '../session'
import { errorMessage } from '../utils'
import FieldErrors from '../components/FieldErrors.vue'
import logo from '../../assets/images/logo/shishli-logo.webp'
const session = useFinanceSession(), router = useRouter()
const form = reactive({ email: '', password: '' }), busy = ref(false), error = ref(''), errors = ref({})
async function submit() {
  if (busy.value) return
  busy.value = true; error.value = ''; errors.value = {}
  try { await session.login(form); await router.replace(session.can('reports.view') ? '/' : '/revenues') }
  catch (e) { error.value = e.response?.status === 403 ? 'هذا الحساب لا يملك صلاحية الدخول إلى النظام المالي.' : errorMessage(e); errors.value = e.response?.data?.errors || {} }
  finally { busy.value = false; form.password = '' }
}
</script>
<template>
  <main class="login-page" dir="rtl">
    <section class="login-intro" aria-label="شيشلي المالية">
      <div class="finance-login-brand"><img :src="logo" alt="Shishli Fresh"><div><span lang="en" dir="ltr">Shishli <b>Finance</b></span><small>الإدارة المالية الداخلية</small></div></div>
      <div class="finance-login-story"><span class="eyebrow">وضوح في كل خطوة</span><h1>أرقام واضحة.<br>قرارات أدق.</h1><p>إيراداتك ومصروفاتك ومشترياتك،<br>في مساحة واحدة منظّمة.</p><div class="finance-login-features"><span><FinanceIcon name="wallet" />الإيرادات اليومية</span><span><FinanceIcon name="shopping-bag" />المصروفات والمشتريات</span><span><FinanceIcon name="chart-column" />التقارير المالية</span></div></div>
      <small>شيشلي فريش · إدارة مالية بثقة ووضوح</small>
    </section>
    <div class="finance-login-workspace">
      <section class="login-form card" aria-labelledby="finance-login-title">
        <span class="finance-login-symbol" aria-hidden="true"><FinanceIcon name="shield-check" /></span>
        <span class="eyebrow">مرحبًا بك مجددًا</span><h2 id="finance-login-title">تسجيل الدخول</h2><p class="muted">أدخل بيانات حسابك للمتابعة إلى لوحة المالية.</p>
        <div v-if="error" class="notice danger" role="alert">{{ error }}</div><FieldErrors :errors="errors" />
        <form :aria-busy="busy" @submit.prevent="submit">
          <label for="finance-login-email">البريد الإلكتروني<input id="finance-login-email" v-model="form.email" type="email" autocomplete="username" inputmode="email" autocapitalize="none" :spellcheck="false" placeholder="name@example.com" required dir="ltr"></label>
          <label for="finance-login-password">كلمة المرور<input id="finance-login-password" v-model="form.password" type="password" autocomplete="current-password" placeholder="أدخل كلمة المرور" required dir="ltr"></label>
          <button class="primary" type="submit" :disabled="busy"><span>{{ busy ? 'جارٍ التحقق…' : 'الدخول إلى Shishli Finance' }}</span><FinanceIcon :name="busy ? 'clock' : 'arrow-left'" /></button>
        </form>
        <p class="secure-note"><FinanceIcon name="shield-check" />الوصول للمستخدمين ذوي الصلاحيات المالية فقط.</p>
      </section>
      <p class="finance-login-footer">Shishli Finance <span aria-hidden="true">·</span> الإدارة المالية الداخلية</p>
    </div>
  </main>
</template>
