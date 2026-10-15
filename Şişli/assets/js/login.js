(() => {
  'use strict';
  const form = document.querySelector('#login-form');
  const phone = document.querySelector('#login-phone');
  const password = document.querySelector('#login-password');
  const status = document.querySelector('#auth-status');
  const toggle = document.querySelector('#toggle-password');
  const normalizePhone = value => value.replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit)).replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[\s()-]/g, '');
  function setError(field, id, message) {
    const error = document.getElementById(id);
    field.setAttribute('aria-invalid', String(Boolean(message)));
    error.textContent = message;
    error.hidden = !message;
  }
  function showStatus(message) { status.textContent = message; status.hidden = false; }
  toggle.addEventListener('click', () => {
    const visible = password.type === 'password';
    password.type = visible ? 'text' : 'password';
    toggle.textContent = visible ? 'إخفاء' : 'إظهار';
    toggle.setAttribute('aria-label', visible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
    toggle.setAttribute('aria-pressed', String(visible));
  });
  phone.addEventListener('input', () => { setError(phone, 'phone-error', ''); status.hidden = true; });
  password.addEventListener('input', () => { setError(password, 'password-error', ''); status.hidden = true; });
  document.querySelector('#forgot-password').addEventListener('click', () => showStatus('استعادة كلمة المرور ستتوفر عند تفعيل الحسابات. لم يتم إرسال رمز أو رسالة.'));
  form.addEventListener('submit', event => {
    event.preventDefault();
    status.hidden = true;
    const validPhone = /^(?:05\d{8}|(?:\+966|00966|966)5\d{8})$/.test(normalizePhone(phone.value));
    const validPassword = password.value.trim().length > 0;
    setError(phone, 'phone-error', validPhone ? '' : 'أدخل رقم جوال سعودي صحيحًا، مثل 0512345678.');
    setError(password, 'password-error', validPassword ? '' : 'أدخل كلمة المرور.');
    if (!validPhone || !validPassword) { (validPhone ? password : phone).focus(); return; }
    // UI-only: never store, transmit, or log credentials or create a fake session.
    password.value = '';
    password.type = 'password';
    toggle.textContent = 'إظهار';
    toggle.setAttribute('aria-label', 'إظهار كلمة المرور');
    toggle.setAttribute('aria-pressed', 'false');
    showStatus('صيغة الحقول صحيحة. تسجيل الدخول الفعلي غير متاح في النسخة التجريبية؛ لم يتم إرسال البيانات أو حفظها. يمكنك متابعة تصفح المنيو.');
  });
})();
