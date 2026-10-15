<script setup>
/*
 * Halaman masuk — rute publik, berdiri sendiri di luar AppShell.
 *
 * Rangka dua kolom: kiri judul display + poin nilai produk,
 * kanan formulir panel kaca minimalis (tanpa gradien, semi
 * transparan). Logo menempel di pojok kiri atas halaman; di
 * mobile logo berpindah ke dalam panel, di atas judul "Masuk".
 * Logika autentikasi tidak berubah (lihat lib/motion.js).
 */
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { login } from '../lib/auth'
import { ApiError } from '../lib/http'
import { gsap } from 'gsap'
import { usePageMotion } from '../lib/motion'

const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const sending = ref(false)
const showPassword = ref(false)
const banner = ref(null)
const errors = ref({})

async function onSubmit() {
  sending.value = true
  banner.value = null
  errors.value = {}

  try {
    await login({ email: email.value, password: password.value })
    router.replace(route.query.redirect ?? { name: 'home' })
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      errors.value = error.errors ?? {}
    } else if (error instanceof ApiError) {
      banner.value = error.message
    } else {
      banner.value = 'Tidak bisa terhubung ke server. Coba lagi.'
    }
  } finally {
    sending.value = false
  }
}

const page = ref(null)
const motion = usePageMotion(page)

onMounted(() => {
  motion.run(() => {
    // Blok intro disembunyikan di mobile (≤60rem); elemen tersembunyi tidak
    // dianimasikan agar tidak ada kerja gerak yang sia-sia (R-19).
    const intro = document.querySelector('.auth__intro')
    const showIntro = Boolean(intro) && intro.offsetParent !== null

    const tl = gsap
      .timeline({ defaults: { ease: 'power3.out' } })
      .from('.bg', { autoAlpha: 0, duration: 0.9, ease: 'power2.out' }, 0)
      .from('.auth__brand', { y: -8, autoAlpha: 0, duration: 0.35 }, 0.05)

    if (showIntro) {
      tl.from('.auth__title', { y: 18, autoAlpha: 0, duration: 0.5, ease: 'power4.out' }, '-=0.15')
        .from('.auth__lede', { y: 10, autoAlpha: 0, duration: 0.36 }, '-=0.3')
        .from(
          '.auth__sweep',
          { scaleX: 0, transformOrigin: 'left center', duration: 0.45 },
          '-=0.2',
        )
        .from('.auth__point', { y: 10, autoAlpha: 0, duration: 0.3, stagger: 0.06 }, '-=0.3')
    }

    tl.from(
      '.auth__panel',
      { y: 26, autoAlpha: 0, duration: 0.55, ease: 'power4.out' },
      showIntro ? '-=0.55' : 0.15,
    )
      // Logo panel (mobile): dianimasikan bersama panel induknya agar
      // muncul serempak, bukan sebelum panel terlihat.
      .from('.auth__panel-brand', { y: -8, autoAlpha: 0, duration: 0.35 }, '-=0.3')
  })
})
</script>

<template>
  <div ref="page" class="auth">
    <!-- Latar belakang: gelombang + cahaya dari pojok kiri atas -->
    <div class="bg" aria-hidden="true">
      <div class="bg__glow bg__glow--one"></div>
      <div class="bg__glow bg__glow--two"></div>

      <div class="waves">
        <svg
          class="wave wave--1"
          viewBox="0 0 2880 320"
          preserveAspectRatio="none"
          focusable="false"
        >
          <path
            d="M0,120 C240,84 480,84 720,120 C960,156 1200,156 1440,120 C1680,84 1920,84 2160,120 C2400,156 2640,156 2880,120 L2880,320 L0,320 Z"
          />
        </svg>
        <svg
          class="wave wave--2"
          viewBox="0 0 2880 320"
          preserveAspectRatio="none"
          focusable="false"
        >
          <path
            d="M0,160 C240,96 480,96 720,160 C960,224 1200,224 1440,160 C1680,96 1920,96 2160,160 C2400,224 2640,224 2880,160 L2880,320 L0,320 Z"
          />
        </svg>
        <svg
          class="wave wave--3"
          viewBox="0 0 2880 320"
          preserveAspectRatio="none"
          focusable="false"
        >
          <path
            d="M0,200 C240,130 480,130 720,200 C960,270 1200,270 1440,200 C1680,130 1920,130 2160,200 C2400,270 2640,270 2880,200 L2880,320 L0,320 Z"
          />
        </svg>
      </div>

      <div class="bg__beam"></div>
      <div class="bg__spot"></div>
    </div>

    <!-- Logo di pojok kiri atas (disembunyikan di mobile, digantikan logo panel) -->
    <RouterLink :to="{ name: 'landing' }" class="auth__brand">
      <img
        class="brand__img brand__img--default"
        src="/brand/lendora-lockup.svg"
        alt="Lendora"
        width="180"
        height="44"
      />
      <img
        class="brand__img brand__img--inverse"
        src="/brand/lendora-lockup-inverse.svg"
        alt=""
        aria-hidden="true"
        width="180"
        height="44"
      />
    </RouterLink>

    <main class="auth__grid">
      <!-- Kolom kiri: judul display + poin nilai produk -->
      <section class="auth__intro">
        <h1 class="auth__title">Masuk ke <span class="auth__title-muted">Lendora.</span></h1>

        <p class="lede auth__lede">
          Kelola peminjaman aset satu alur: minta, serah terima, kembalikan, periksa.
        </p>

        <span class="auth__sweep" aria-hidden="true"></span>

        <ul class="auth__points">
          <li class="auth__point">
            <span class="auth__point-icon" aria-hidden="true">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <rect x="3" y="3" width="7" height="7" rx="1.5" />
                <rect x="14" y="3" width="7" height="7" rx="1.5" />
                <rect x="3" y="14" width="7" height="7" rx="1.5" />
                <rect x="14" y="14" width="7" height="7" rx="1.5" />
              </svg>
            </span>
            <span class="auth__point-body">
              <span class="auth__point-label">Katalog</span>
              <span class="auth__point-text">Telusuri aset yang tersedia beserta jadwalnya.</span>
            </span>
          </li>
          <li class="auth__point">
            <span class="auth__point-icon" aria-hidden="true">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path d="M17 4l3 3-3 3" />
                <path d="M20 7H7a4 4 0 0 0-4 4v1" />
                <path d="M7 20l-3-3 3-3" />
                <path d="M4 17h13a4 4 0 0 0 4-4v-1" />
              </svg>
            </span>
            <span class="auth__point-body">
              <span class="auth__point-label">Alur</span>
              <span class="auth__point-text">Ajukan, setujui, serah terima, kembalikan.</span>
            </span>
          </li>
          <li class="auth__point">
            <span class="auth__point-icon" aria-hidden="true">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path
                  d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"
                />
                <rect x="9" y="3" width="6" height="4" rx="1" />
                <path d="m9 14 2 2 4-4" />
              </svg>
            </span>
            <span class="auth__point-body">
              <span class="auth__point-label">Kondisi</span>
              <span class="auth__point-text">Riwayat pemeriksaan aset tetap tercatat.</span>
            </span>
          </li>
        </ul>
      </section>

      <!-- Kolom kanan: formulir panel minimalis -->
      <section class="auth__form-col">
        <div class="auth__panel">
          <!-- Logo di dalam panel: hanya tampil di mobile, di atas judul -->
          <RouterLink
            :to="{ name: 'landing' }"
            class="auth__panel-brand"
            aria-label="Lendora, ke halaman utama"
          >
            <img
              class="brand__img brand__img--default"
              src="/brand/lendora-lockup.svg"
              alt=""
              aria-hidden="true"
              width="180"
              height="44"
            />
            <img
              class="brand__img brand__img--inverse"
              src="/brand/lendora-lockup-inverse.svg"
              alt=""
              aria-hidden="true"
              width="180"
              height="44"
            />
          </RouterLink>

          <h2 class="auth__panel-title">Masuk</h2>
          <p class="text-2 auth__panel-note">Gunakan email yang terdaftar di organisasimu.</p>

          <p v-if="banner" class="form-error" role="alert">{{ banner }}</p>

          <form @submit.prevent="onSubmit" novalidate>
            <div class="field">
              <label for="email">Email</label>
              <input
                id="email"
                v-model="email"
                class="input"
                type="email"
                name="email"
                autocomplete="email"
                required
              />
              <p v-if="errors.email" class="field-error" role="alert">{{ errors.email[0] }}</p>
            </div>

            <div class="field">
              <label for="password">Kata sandi</label>
              <div class="field__control">
                <input
                  id="password"
                  v-model="password"
                  class="input"
                  :type="showPassword ? 'text' : 'password'"
                  name="password"
                  autocomplete="current-password"
                  required
                />
                <button
                  type="button"
                  class="field__toggle"
                  @click="showPassword = !showPassword"
                  :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                >
                  <svg
                    v-if="showPassword"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"
                    />
                    <line x1="1" y1="1" x2="23" y2="23" />
                  </svg>
                  <svg
                    v-else
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
              </div>
              <p v-if="errors.password" class="field-error" role="alert">
                {{ errors.password[0] }}
              </p>
            </div>

            <button type="submit" class="btn btn--primary auth__submit" :disabled="sending">
              <span v-if="sending" class="spinner" aria-hidden="true"></span>
              {{ sending ? 'Memeriksa akun…' : 'Masuk' }}
            </button>
          </form>

          <p class="auth__alt">
            Belum punya akun?
            <RouterLink :to="{ name: 'register' }" class="link-sweep">Daftar</RouterLink>
            dan tunggu aktivasi admin.
          </p>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
/* ===================== Rangka ===================== */
.auth {
  position: relative;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-height: 100dvh;
  background: var(--ln-bg);
  color: var(--ln-text);
}

/* ===================== Background gelombang + cahaya ===================== */
.bg {
  position: fixed;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;

  --beam-soft: rgba(255, 255, 255, 0.07);
  --beam-core: rgba(255, 255, 255, 0.15);

  background:
    radial-gradient(56rem 44rem at 0% 0%, rgba(255, 255, 255, 0.08), transparent 55%),
    radial-gradient(60rem 32rem at 85% -10%, rgba(140, 140, 150, 0.14), transparent 60%),
    radial-gradient(52rem 28rem at 0% 110%, rgba(28, 28, 36, 0.18), transparent 60%);
}

[data-theme='dark'] .bg {
  --beam-soft: rgba(255, 255, 255, 0.1);
  --beam-core: rgba(255, 255, 255, 0.22);
}

.bg__beam {
  position: absolute;
  inset: 0;
  background: conic-gradient(
    from 90deg at 0% 0%,
    transparent 0deg,
    var(--beam-soft) 16deg,
    var(--beam-core) 42deg,
    var(--beam-soft) 68deg,
    transparent 88deg
  );
  filter: blur(12px);
  transform-origin: 0 0;
  animation: beam-sway 9s ease-in-out infinite alternate;
  will-change: transform, opacity;
}

.bg__spot {
  position: absolute;
  top: -7rem;
  left: -7rem;
  width: 24rem;
  height: 24rem;
  border-radius: 9999px;
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.26) 0%,
    rgba(255, 255, 255, 0.09) 38%,
    transparent 68%
  );
  filter: blur(8px);
  animation: spot-breathe 6s ease-in-out infinite alternate;
}

.bg__glow {
  position: absolute;
  border-radius: 9999px;
  filter: blur(90px);
}

.bg__glow--one {
  width: 36rem;
  height: 36rem;
  top: -12rem;
  left: -10rem;
  background: radial-gradient(circle at 30% 30%, rgba(158, 158, 168, 0.22), transparent 65%);
  animation: glow-a 16s ease-in-out infinite alternate;
}

.bg__glow--two {
  width: 30rem;
  height: 30rem;
  right: -8rem;
  bottom: 8rem;
  background: radial-gradient(circle at 60% 40%, rgba(45, 45, 55, 0.26), transparent 65%);
  animation: glow-b 20s ease-in-out infinite alternate;
}

.waves {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: min(38vh, 22rem);
  animation: wave-bob 7s ease-in-out infinite alternate;
}

.wave {
  position: absolute;
  bottom: -1px;
  left: 0;
  width: 200%;
  height: 100%;
  animation: wave-drift linear infinite;
  will-change: transform;
}

.wave--1 {
  fill: rgba(150, 150, 160, 0.16);
  animation-duration: 34s;
}
.wave--2 {
  fill: rgba(90, 90, 100, 0.22);
  animation-duration: 24s;
  animation-direction: reverse;
}
.wave--3 {
  fill: rgba(24, 24, 30, 0.38);
  animation-duration: 17s;
}

/* ===================== Logo pojok kiri atas ===================== */
.auth__brand {
  position: absolute;
  top: 1.5rem;
  left: var(--ln-gutter);
  z-index: 2;
  display: inline-flex;
  align-items: center;
}

.brand__img {
  display: block;
  height: 1.75rem;
  width: auto;
}

.brand__img--inverse {
  display: none;
}

[data-theme='dark'] .brand__img--default {
  display: none;
}

[data-theme='dark'] .brand__img--inverse {
  display: block;
}

/* ===================== Logo alternatif di dalam panel (mobile) ===================== */
/* Sembunyikan secara default; media query mobile yang menampilkannya. */
.auth__panel-brand {
  display: none;
  margin-bottom: 1.75rem;
}

/* Diperbesar agar terlihat jelas di dalam panel. */
.auth__panel-brand .brand__img {
  height: 2.5rem;
}

/* ===================== Mode gelap: box abu-abu, tidak terlalu hitam ===================== */
[data-theme='dark'] .auth__panel {
  background: rgba(148, 148, 158, 0.16);
  border-color: rgba(255, 255, 255, 0.14);
  box-shadow: 0 16px 48px -24px rgba(0, 0, 0, 0.55);
}

/* Input ikut diangkat biar tidak tampak seperti lubang hitam di dalam box abu-abu */
[data-theme='dark'] .input {
  background: rgba(148, 148, 158, 0.13);
}

[data-theme='dark'] .input:focus-visible {
  background: rgba(148, 148, 158, 0.22);
}

/* ===================== Dua kolom ===================== */
.auth__grid {
  position: relative;
  z-index: 1;
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1.02fr) minmax(0, 0.98fr);
  align-items: center;
  gap: clamp(28px, 4vw, 56px);
  width: min(88rem, 100%);
  margin-inline: auto;
  /* padding-top ekstra agar konten tidak menabrak logo di layar pendek */
  padding: max(var(--ln-section-y), 5.5rem) var(--ln-gutter) var(--ln-section-y);
}

/* ===================== Kolom kiri ===================== */
.auth__intro {
  max-width: 42rem;
}

.auth__title {
  max-width: 12ch;
  margin: 0 0 1.25rem;
}

.auth__title-muted {
  color: var(--ln-text-2);
}

.auth__lede {
  margin-bottom: 2rem;
}

.auth__sweep {
  display: block;
  height: 2px;
  margin-bottom: 1.25rem;
  background: var(--ln-border-strong);
}

.auth__points {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.auth__point {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.9rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.auth__point-icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  border: 1px solid var(--ln-border);
  border-radius: 0.75rem;
  background: color-mix(in srgb, var(--ln-surface) 55%, transparent);
  color: var(--ln-text);
}

.auth__point-icon svg {
  width: 1.15rem;
  height: 1.15rem;
}

.auth__point-body {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.auth__point-label {
  font-weight: 600;
  font-size: 0.9375rem;
  letter-spacing: 0.01em;
}

.auth__point-text {
  color: var(--ln-text-2);
  font-size: 0.875rem;
  line-height: 1.55;
}

/* ===================== Kolom kanan ===================== */
.auth__form-col {
  align-self: stretch;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding-left: clamp(24px, 4vw, 56px);
  border-left: 1px solid var(--ln-border);
}

/* Panel minimalis: satu bidang kaca semi transparan, tanpa gradien */
.auth__panel {
  width: 100%;
  max-width: 32rem;
  padding: clamp(1.5rem, 3vw, 2.25rem);
  border: 1px solid var(--ln-border);
  border-radius: 0.75rem;
  background: color-mix(in srgb, var(--ln-surface) 60%, transparent);
  -webkit-backdrop-filter: blur(16px);
  backdrop-filter: blur(16px);
  box-shadow: 0 16px 48px -24px rgba(10, 10, 16, 0.4);
}

.auth__panel-title {
  margin: 0 0 0.5rem;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

.auth__panel-note {
  margin-bottom: 1.5rem;
  font-size: 0.9375rem;
}

/* ===================== Form ===================== */
.field {
  margin-bottom: 1.125rem;
}

.field .input {
  display: block;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}

/* Input semi transparan, tanpa efek gradien */
.input {
  background: color-mix(in srgb, var(--ln-surface-alt) 50%, transparent);
  transition:
    border-color var(--ln-motion-fast) var(--ln-ease),
    background-color var(--ln-motion-fast) var(--ln-ease);
}

.input:hover {
  border-color: var(--ln-border-strong);
}

.input:focus-visible {
  border-color: var(--ln-border-strong);
  background: color-mix(in srgb, var(--ln-surface-alt) 75%, transparent);
}

.field-error {
  animation: auth-rise 0.25s var(--ln-ease) both;
}

/* Tumpukan grid: input membentang penuh, ikon mata di dalam input. */
.field__control {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
}

.field__control .input {
  grid-area: 1 / 1 / 2 / 3;
  min-width: 0;
  padding-right: 3.5rem;
}

.field__toggle {
  grid-area: 1 / 2 / 2 / 3;
  z-index: 1;
  align-self: stretch;
  display: grid;
  place-items: center;
  width: 2.75rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--ln-text-2);
  cursor: pointer;
  transition: color var(--ln-motion-fast) var(--ln-ease);
}

.field__toggle:hover {
  color: var(--ln-text);
}

.field__toggle:focus-visible {
  outline: 2px solid var(--ln-text);
  outline-offset: -2px;
}

.field__toggle svg {
  width: 1.15rem;
  height: 1.15rem;
}

/* ===================== Galat banner ===================== */
.auth__panel .form-error {
  animation: auth-shake 0.45s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}

/* ===================== Tombol ===================== */
.auth__submit {
  width: 100%;
  margin-top: 0.5rem;
}

.auth__submit:disabled {
  cursor: progress;
}

.spinner {
  width: 0.875rem;
  height: 0.875rem;
  flex: none;
  border: 2px solid color-mix(in srgb, currentcolor 32%, transparent);
  border-top-color: currentcolor;
  animation: auth-spin 0.7s linear infinite;
}

/* ===================== Silang halaman ===================== */
.auth__alt {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin: 1.5rem 0 0;
  padding-top: 1.25rem;
  border-top: 1px solid var(--ln-border);
  color: var(--ln-text-2);
  font-size: 0.875rem;
}

.auth__alt .link-sweep {
  min-height: 44px;
  color: var(--ln-text);
}

/* ===================== Responsif ===================== */
@media (max-width: 60rem) {
  .auth__grid {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    gap: 2.5rem;
    /* Logo pojok sudah tidak tampil, padding atas bisa longgar normal. */
    padding-top: max(2rem, env(safe-area-inset-top));
  }

  /* Mobile: fokus ke formulir saja, intro disembunyikan. */
  .auth__intro {
    display: none;
  }

  /* Logo pojok kiri atas disembunyikan; digantikan logo di dalam panel. */
  .auth__brand {
    display: none;
  }

  .auth__form-col {
    width: 100%;
    padding-top: 0;
    padding-left: 0;
    border-top: 0; /* garis di bawah logo — dihapus */
    border-left: 0;
    /* Panel center agar sejajar dengan layout logo di dalamnya. */
    align-items: center;
  }

  .auth__panel {
    max-width: 26rem;
  }

  /* Logo kini tampil di dalam panel: rata tengah, di atas judul "Masuk". */
  .auth__panel-brand {
    display: flex;
    margin-left: 90px;
  }

  .auth__panel-brand .brand__img {
    height: 2.75rem; /* lebar efektif ±17.2rem, masih longgar di panel 26rem */
  }
}

/* ===================== Keyframes ===================== */
@keyframes wave-drift {
  from {
    transform: translate3d(0, 0, 0);
  }
  to {
    transform: translate3d(-50%, 0, 0);
  }
}

@keyframes wave-bob {
  from {
    transform: translateY(0);
  }
  to {
    transform: translateY(-14px);
  }
}

@keyframes glow-a {
  from {
    transform: translate(0, 0);
  }
  to {
    transform: translate(3rem, 2rem);
  }
}

@keyframes glow-b {
  from {
    transform: translate(0, 0);
  }
  to {
    transform: translate(-3rem, -1.5rem);
  }
}

@keyframes beam-sway {
  from {
    transform: rotate(-1.75deg);
    opacity: 0.85;
  }
  to {
    transform: rotate(2.25deg);
    opacity: 1;
  }
}

@keyframes spot-breathe {
  from {
    opacity: 0.7;
    transform: scale(0.96);
  }
  to {
    opacity: 1;
    transform: scale(1.04);
  }
}

@keyframes auth-rise {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

@keyframes auth-shake {
  10%,
  90% {
    transform: translateX(-1px);
  }
  20%,
  80% {
    transform: translateX(2px);
  }
  30%,
  50%,
  70% {
    transform: translateX(-5px);
  }
  40%,
  60% {
    transform: translateX(5px);
  }
}

@keyframes auth-spin {
  to {
    transform: rotate(1turn);
  }
}

@media (prefers-reduced-motion: reduce) {
  .bg,
  .waves,
  .wave,
  .bg__beam,
  .bg__spot,
  .bg__glow {
    animation: none !important;
  }

  .spinner {
    animation: none;
  }
}
</style>
