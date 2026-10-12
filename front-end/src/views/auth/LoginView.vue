<script setup>
import { RouterLink, useRoute } from 'vue-router'
const route = useRoute()
import SiteIcon from '../../components/site/SiteIcon.vue'
import { useAuthForm } from '../../composables/useAuthForm'
import { localPhone } from '../../utils/phone'

const { form, status, generalError, isSubmitting, showPassword, setInput, errorText, clearError, showRecoveryNotice, submit } = useAuthForm('login')
function normalizeIdentity() { if (!form.phone.includes('@')) form.phone = localPhone(form.phone) }
</script>

<template>
  <main id="main" class="auth-main container">
    <RouterLink class="auth-back" to="/">→ العودة للرئيسية</RouterLink>
    <section class="auth-layout" aria-labelledby="login-title">
      <div class="auth-form-panel">
        <span class="section-kicker">أهلاً بعودتك إلى شيشلي</span>
        <h1 id="login-title">تسجيل الدخول</h1>
        <p class="auth-intro">وجبتك المفضلة بانتظارك.</p>
        <form id="login-form" novalidate @submit.prevent="submit" :aria-busy="isSubmitting">
          <div class="auth-field"><label for="login-phone">رقم الجوال أو البريد الإلكتروني</label><input id="login-phone" v-model="form.phone" @blur="normalizeIdentity" :ref="element => setInput('phone', element)" @input="clearError('phone')" :aria-invalid="Boolean(errorText('phone'))" type="text" autocomplete="username" dir="ltr" placeholder="05xxxxxxxx" required aria-describedby="phone-hint phone-error"><small id="phone-hint">مثال: 0551234567، أو بريدك الإلكتروني</small><p class="auth-error" id="phone-error" :hidden="!errorText('phone')">{{ errorText('phone') }}</p></div>
          <div class="auth-field"><label for="login-password">كلمة المرور</label><div class="auth-password"><input id="login-password" v-model="form.password" :ref="element => setInput('password', element)" @input="clearError('password')" :aria-invalid="Boolean(errorText('password'))" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" maxlength="72" required aria-describedby="password-error"><button type="button" id="toggle-password" @click="showPassword = !showPassword" :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'" :aria-pressed="showPassword" aria-controls="login-password">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button></div><p class="auth-error" id="password-error" :hidden="!errorText('password')">{{ errorText('password') }}</p></div>
          <button type="button" class="auth-forgot" id="forgot-password" @click="showRecoveryNotice">نسيت كلمة المرور؟</button>
          <p v-if="generalError" class="auth-status" role="alert">{{ generalError }}</p>
          <button class="button button-primary auth-submit" type="submit" :disabled="isSubmitting">{{ isSubmitting ? 'جارٍ تسجيل الدخول…' : 'تسجيل الدخول' }} <SiteIcon name="arrow-left" /></button>
          <div class="auth-status" id="auth-status" role="status" :hidden="!status">{{ status }}</div>
        </form>
        <p class="auth-switch">ليس لديك حساب؟ <RouterLink :to="{name:'register',query:route.query.redirect?{redirect:route.query.redirect}:{}}">إنشاء حساب</RouterLink></p>
        <div class="auth-guest"><span>تفضل التصفح الآن؟</span><RouterLink to="/menu">تصفح المنيو بدون حساب ←</RouterLink></div>
      </div>
      <aside class="auth-visual" aria-label="مطعم شيشلي"><div class="auth-food" aria-hidden="true"></div><div class="auth-visual-copy"><span class="section-kicker">من الفحم… إلى قلبك</span><h2>طعم يجمعنا.<br>ومذاق نعود إليه.</h2><p>دجاج تركي وشيش ومشويات، تُحضّر بعناية لتكتمل لحظاتك الحلوة.</p><RouterLink class="button button-outline" to="/menu">اكتشف أطباقنا <SiteIcon name="arrow-left" /></RouterLink></div></aside>
    </section>
  </main>
</template>
