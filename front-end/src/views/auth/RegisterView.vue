<script setup>
import { RouterLink, useRoute } from 'vue-router'
import RegistrationOffer from '../../components/site/RegistrationOffer.vue'
import PhoneInput from '../../components/common/PhoneInput.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'
import { useAuthForm } from '../../composables/useAuthForm'

const { form, status, generalError, isSubmitting, showPassword, showConfirmation, setInput, errorText, clearError, submit } = useAuthForm('register')
const route = useRoute()
</script>

<template>
  <main id="main" class="auth-main container">
    <RouterLink class="auth-back" to="/">→ العودة للرئيسية</RouterLink>
    <section class="auth-layout" aria-labelledby="login-title">
      <div class="auth-form-panel">
        <span class="section-kicker">أهلاً بك في عائلة شيشلي فرش</span>
        <h1 id="login-title">إنشاء حساب</h1>
        <p class="auth-intro">خطوتك الأولى إلى مذاقك المفضل.</p>
        <RegistrationOffer :show-action="false"/>
        <form id="register-form" novalidate @submit.prevent="submit" :aria-busy="isSubmitting">
<div class="auth-field"><label for="register-name">الاسم</label><input id="register-name" v-model="form.name" :ref="element => setInput('name', element)" @input="clearError('name')" :aria-invalid="Boolean(errorText('name'))" type="text" autocomplete="name" required maxlength="255" aria-describedby="name-error"><p class="auth-error" id="name-error" :hidden="!errorText('name')">{{ errorText('name') }}</p></div>
          <div class="auth-field"><label for="register-email">البريد الإلكتروني</label><input id="register-email" v-model="form.email" :ref="element => setInput('email', element)" @input="clearError('email')" :aria-invalid="Boolean(errorText('email'))" type="email" autocomplete="email" required maxlength="255" dir="ltr" aria-describedby="email-error"><p class="auth-error" id="email-error" :hidden="!errorText('email')">{{ errorText('email') }}</p></div>
          <div class="auth-field"><label for="login-phone">رقم الجوال (اختياري)</label><PhoneInput id="login-phone" v-model="form.phone" :ref="element => setInput('phone', element)" @input="clearError('phone')" :aria-invalid="Boolean(errorText('phone'))" aria-describedby="phone-hint phone-error"/><small id="phone-hint">مثال: 0551234567</small><p class="auth-error" id="phone-error" :hidden="!errorText('phone')">{{ errorText('phone') }}</p></div>
          <div class="auth-field"><label for="login-password">كلمة المرور</label><div class="auth-password"><input id="login-password" v-model="form.password" :ref="element => setInput('password', element)" @input="clearError('password')" :aria-invalid="Boolean(errorText('password'))" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" maxlength="72" minlength="8" required aria-describedby="password-hint password-error"><button type="button" id="toggle-password" @click="showPassword = !showPassword" :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'" :aria-pressed="showPassword" aria-controls="login-password">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button></div><small id="password-hint">استخدم 8 أحرف على الأقل.</small><p class="auth-error" id="password-error" :hidden="!errorText('password')">{{ errorText('password') }}</p></div>
          <div class="auth-field"><label for="confirm-password">تأكيد كلمة المرور</label><div class="auth-password"><input id="confirm-password" v-model="form.password_confirmation" :ref="element => setInput('password_confirmation', element)" @input="clearError('password_confirmation')" :aria-invalid="Boolean(errorText('password_confirmation'))" :type="showConfirmation ? 'text' : 'password'" autocomplete="new-password" maxlength="72" required aria-describedby="confirm-error"><button type="button" id="toggle-confirm" @click="showConfirmation = !showConfirmation" :aria-label="showConfirmation ? 'إخفاء تأكيد كلمة المرور' : 'إظهار تأكيد كلمة المرور'" :aria-pressed="showConfirmation" aria-controls="confirm-password">{{ showConfirmation ? 'إخفاء' : 'إظهار' }}</button></div><p class="auth-error" id="confirm-error" :hidden="!errorText('password_confirmation')">{{ errorText('password_confirmation') }}</p></div>
          <p v-if="generalError" class="auth-status" role="alert">{{ generalError }}</p>
          <button class="button button-primary auth-submit" type="submit" :disabled="isSubmitting">{{ isSubmitting ? 'جارٍ إنشاء الحساب…' : 'إنشاء حساب' }} <SiteIcon name="arrow-left" /></button>
          <div class="auth-status" id="auth-status" role="status" :hidden="!status">{{ status }} <RouterLink v-if="status" :to="{name:'login',query:route.query.redirect?{redirect:route.query.redirect}:{}}">تسجيل الدخول وإكمال الطلب</RouterLink></div>
        </form>
        <p class="auth-switch">لديك حساب بالفعل؟ <RouterLink :to="{name:'login',query:route.query.redirect?{redirect:route.query.redirect}:{}}">تسجيل الدخول</RouterLink></p>
        <div class="auth-guest"><span>تفضل التصفح الآن؟</span><RouterLink to="/menu">تصفح المنيو بدون حساب ←</RouterLink></div>
      </div>
      <aside class="auth-visual" aria-label="مطعم شيشلي فرش"><div class="auth-food" aria-hidden="true"></div><div class="auth-visual-copy"><span class="section-kicker">من الفحم… إلى قلبك</span><h2>طعم يجمعنا.<br>ومذاق نعود إليه.</h2><p>دجاج تركي وشيش ومشويات، تُحضّر بعناية لتكتمل لحظاتك الحلوة.</p><RouterLink class="button button-outline" to="/menu">اكتشف أطباقنا <SiteIcon name="arrow-left" /></RouterLink></div></aside>
    </section>
  </main>
</template>
