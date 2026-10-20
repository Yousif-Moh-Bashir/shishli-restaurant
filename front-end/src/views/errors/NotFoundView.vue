<script setup>
import { RouterLink } from 'vue-router'
import SiteIcon from '../../components/site/SiteIcon.vue'
</script>

<template>
  <main id="main" class="container not-found-page" tabindex="-1">
    <section class="not-found-card" aria-labelledby="not-found-title" aria-describedby="not-found-description">
      <p class="not-found-eyebrow"><SiteIcon name="flame"/> شيشلي فرش</p>
      <div class="not-found-art" role="img" aria-label="خطأ 404">
        <div class="not-found-code" aria-hidden="true">
          <span>4</span>
          <span class="not-found-plate">
            <svg viewBox="0 0 120 120" focusable="false" aria-hidden="true">
              <path d="M31 91 89 25M27 96l8-3M87 28l7-8" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>
              <g transform="rotate(40 60 60)" stroke="currentColor" stroke-width="2.5">
                <rect x="44" y="23" width="31" height="23" rx="7" fill="var(--gold)"/>
                <rect x="44" y="51" width="31" height="23" rx="7" fill="var(--secondary)"/>
                <rect x="44" y="79" width="31" height="23" rx="7" fill="var(--gold)"/>
                <path d="m50 29 5 4m9-3 5 4M50 57l5 4m9-3 5 4M50 85l5 4m9-3 5 4" fill="none" stroke-linecap="round"/>
              </g>
            </svg>
          </span>
          <span>4</span>
        </div>
        <span class="not-found-rule" aria-hidden="true"></span>
      </div>
      <h1 id="not-found-title">عذرًا، هذه الصفحة غير موجودة!</h1>
      <p id="not-found-description" class="not-found-description">يبدو أن الرابط الذي تبحث عنه غير متاح، لكن أشهى المشويات بانتظارك!</p>
      <nav class="not-found-actions" aria-label="الانتقال من الصفحة غير الموجودة">
        <RouterLink class="button button-primary" to="/menu"><SiteIcon name="utensils"/> استعرض قائمة الطعام</RouterLink>
        <RouterLink class="button not-found-home" to="/">العودة للرئيسية <SiteIcon name="arrow-left"/></RouterLink>
      </nav>
    </section>
  </main>
</template>

<style scoped>
.not-found-page{display:grid;place-items:center;min-height:65svh;padding-block:clamp(28px,6vw,72px)}
.not-found-card{position:relative;isolation:isolate;width:100%;max-width:900px;padding:clamp(28px,5vw,64px) clamp(18px,4vw,56px);overflow:hidden;text-align:center;border:1px solid var(--border);border-radius:24px;background:radial-gradient(ellipse at 50% 20%,#c9963b12,transparent 65%),#fffaf5;box-shadow:0 18px 50px #35101708;animation:not-found-enter .5s ease-out both}
.not-found-eyebrow{display:inline-flex;align-items:center;gap:8px;margin:0;color:var(--primary);font-size:14px;font-weight:700}
.not-found-eyebrow .icon{width:20px;height:20px;color:var(--secondary)}
.not-found-art{margin:clamp(16px,3vw,28px) auto 24px;max-width:460px}
.not-found-code{display:flex;align-items:center;justify-content:center;gap:clamp(10px,3vw,24px);direction:ltr;color:var(--primary);font-size:clamp(100px,20vw,190px);font-weight:900;line-height:1.05;letter-spacing:-.04em}
.not-found-plate{display:grid;place-items:center;flex:0 0 auto;width:clamp(84px,17vw,150px);aspect-ratio:1;border:2px solid var(--gold);border-radius:50%;outline:1px solid #c9963b55;outline-offset:6px;background:var(--cream);box-shadow:inset 0 0 0 9px #fffaf5,0 8px 22px #5a17250a;transform:rotate(-8deg)}
.not-found-plate svg{display:block;width:75%;height:75%;color:var(--primary)}
.not-found-rule{display:block;width:64px;height:3px;border-radius:3px;margin:20px auto 0;background:var(--gold)}
.not-found-card h1{margin:0;color:var(--primary-dark);font-size:clamp(23px,3.5vw,36px);font-weight:800;line-height:1.6;text-wrap:balance}
.not-found-description{max-width:490px;margin:14px auto 0;color:#75635f;font-size:clamp(14px,1.7vw,17px);line-height:1.95;text-wrap:pretty}
.not-found-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:30px}
.not-found-actions .button{min-height:48px;max-width:100%;gap:9px;white-space:normal;text-align:center}
.not-found-home{border:1px solid var(--border);background:transparent;color:var(--primary)}
.not-found-home:hover{border-color:var(--primary);background:#5a172508}
.not-found-actions .button:focus-visible{outline:3px solid var(--gold);outline-offset:4px}
@keyframes not-found-enter{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:480px){.not-found-card{border-radius:18px}.not-found-actions{flex-direction:column;align-items:stretch;margin-top:24px}.not-found-actions .button{font-size:14px;padding:12px}.not-found-art{margin-bottom:22px}}
@media(prefers-reduced-motion:reduce){.not-found-card{animation:none}.not-found-actions .button{transition:none}}
</style>
