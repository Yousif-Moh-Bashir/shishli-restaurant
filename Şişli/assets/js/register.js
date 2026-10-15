(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const fields = [[$('register-name'), 'name-error'], [$('login-phone'), 'phone-error'], [$('login-password'), 'password-error'], [$('confirm-password'), 'confirm-error']];
  const status = $('auth-status');
  function error(field, id, message) {
    field.setAttribute('aria-invalid', String(Boolean(message)));
    $(id).textContent = message;
    $(id).hidden = !message;
  }
  fields.forEach(([field, id]) => field.addEventListener('input', () => { error(field, id, ''); status.hidden = true; }));
  function toggle(field, button, visible) {
    field.type = visible ? 'text' : 'password';
    button.textContent = visible ? 'إخفاء' : 'إظهار';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', `${visible ? 'إخفاء' : 'إظهار'} ${field.id === 'confirm-password' ? 'تأكيد كلمة المرور' : 'كلمة المرور'}`);
  }
  const toggles = [[$('login-password'), $('toggle-password')], [$('confirm-password'), $('toggle-confirm')]];
  toggles.forEach(([field, button]) => button.addEventListener('click', () => toggle(field, button, field.type === 'password')));
  $('register-form').addEventListener('submit', event => {
    event.preventDefault();
    status.hidden = true;
    const phone = $('login-phone').value.replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit)).replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[\s()-]/g, '');
    const password = $('login-password').value;
    const messages = [
      $('register-name').value.trim().length >= 2 ? '' : 'أدخل اسمًا من حرفين على الأقل.',
      /^(?:05\d{8}|(?:\+966|00966|966)5\d{8})$/.test(phone) ? '' : 'أدخل رقم جوال سعودي صحيحًا.',
      password.trim().length >= 8 ? '' : 'استخدم كلمة مرور من 8 أحرف على الأقل.',
      $('confirm-password').value && $('confirm-password').value === password ? '' : 'تأكيد كلمة المرور غير مطابق.'
    ];
    fields.forEach(([field, id], index) => error(field, id, messages[index]));
    const invalid = messages.findIndex(Boolean);
    if (invalid !== -1) { fields[invalid][0].focus(); return; }
    toggles.forEach(([field, button]) => { field.value = ''; toggle(field, button, false); });
    // Front-end preview only. Do not persist credentials or simulate an authenticated session.
    status.textContent = 'البيانات مستوفية لشروط النموذج. إنشاء الحساب الفعلي غير متاح حتى ربط الموقع بالخادم؛ لم يتم إنشاء حساب أو إرسال البيانات أو حفظها.';
    status.hidden = false;
  });
})();
