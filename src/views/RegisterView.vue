<script setup>
/*
 * Halaman pendaftaran — rute publik, berdiri sendiri di luar AppShell.
 *
 * Rangka dua kolom seperti landing: kiri judul display + kicker mono +
 * poin nilai produk, kanan formulir panel bergaris (dan panel status
 * saat pendaftaran diterima). Logika pendaftaran (payload, validasi,
 * state, emit) tidak berubah; hanya presentasi dan gerak.
 */
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { register } from '../lib/auth'
import { ApiError } from '../lib/http'
import { gsap } from 'gsap'
import { usePageMotion } from '../lib/motion'

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const organizationSlug = ref('')
const sending = ref(false)
const banner = ref(null)
const errors = ref({})
const successMessage = ref(null)

const showPassword = ref(false)
const showConfirmation = ref(false)

async function onSubmit() {
  sending.value = true
  banner.value = null
  errors.value = {}
  successMessage.value = null

  try {
    const payload = await register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
      organization_slug: organizationSlug.value,
    })
    successMessage.value = payload.message
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
      .from('.auth__bar', { y: -8, autoAlpha: 0, duration: 0.3 })

    if (showIntro) {
      tl.from('.auth__kicker', { x: -10, autoAlpha: 0, duration: 0.22 }, '-=0.08')
        .from('.auth__title', { y: 18, autoAlpha: 0, duration: 0.5, ease: 'power4.out' }, '-=0.04')
        .from('.auth__lede', { y: 10, autoAlpha: 0, duration: 0.36 }, '-=0.3')
        .from(
          '.auth__sweep',
          { scaleX: 0, transformOrigin: 'left center', duration: 0.45 },
          '-=0.2',
        )
        .from('.auth__point', { y: 10, autoAlpha: 0, duration: 0.3, stagger: 0.06 }, '-=0.3')
        .from('.auth__meta', { y: 8, autoAlpha: 0, duration: 0.3 }, '-=0.15')
    }

    tl.from(
      '.auth__panel',
      { y: 26, autoAlpha: 0, duration: 0.55, ease: 'power4.out' },
      showIntro ? '-=0.55' : 0.4,
    )
  })
})
</script>

<template>
  <div ref="page" class="auth">
    <header class="auth__bar">
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

      <RouterLink :to="{ name: 'login' }" class="auth__switch link-sweep">
        Sudah punya akun? <strong>Masuk</strong>
      </RouterLink>
    </header>

    <main class="auth__grid">
      <!-- Kolom kiri: judul display, kicker mono, poin nilai produk -->
      <section class="auth__intro">
        <p class="index-label auth__kicker">
          <span class="auth__kicker-rule" aria-hidden="true"></span>
          DAFTAR
        </p>

        <h1 class="auth__title">Daftar <span class="auth__title-muted">akun.</span></h1>

        <p class="lede auth__lede">
          Buat akun untuk mengajukan peminjaman dan mengoperasikan aset organisasi.
        </p>

        <span class="auth__sweep" aria-hidden="true"></span>

        <ul class="auth__points">
          <li class="auth__point">
            <span class="auth__point-label">Katalog</span>
            <span class="auth__point-text">Telusuri aset yang tersedia beserta jadwalnya.</span>
          </li>
          <li class="auth__point">
            <span class="auth__point-label">Alur</span>
            <span class="auth__point-text">Ajukan, setujui, serah terima, kembalikan.</span>
          </li>
          <li class="auth__point">
            <span class="auth__point-label">Kondisi</span>
            <span class="auth__point-text">Riwayat pemeriksaan aset tetap tercatat.</span>
          </li>
        </ul>

        <div class="auth__meta">
          <RouterLink :to="{ name: 'landing' }" class="link-sweep"
            >&larr; Kembali ke beranda</RouterLink
          >
          <span class="num">LENDORA V2</span>
        </div>
      </section>

      <!-- Kolom kanan: formulir panel / panel status -->
      <section class="auth__form-col">
        <div v-if="successMessage" class="panel auth__panel auth__panel--success" role="status">
          <svg class="success__icon" viewBox="0 0 52 52" aria-hidden="true">
            <rect class="success__frame" x="2" y="2" width="48" height="48" fill="none" />
            <path class="success__check" fill="none" d="M14 27l8 8 16-16" />
          </svg>
          <p class="index-label auth__panel-index">DITERIMA</p>
          <h2 class="auth__panel-title">Pendaftaran diterima</h2>
          <p class="text-2 auth__panel-note">{{ successMessage }}</p>
          <RouterLink :to="{ name: 'login' }" class="btn btn--primary auth__submit">
            Ke halaman masuk
          </RouterLink>
        </div>

        <div v-else class="panel auth__panel">
          <p class="index-label auth__panel-index">FORMULIR</p>
          <h2 class="auth__panel-title">Daftar</h2>
          <p class="text-2 auth__panel-note">
            Butuh kode organisasi? Isi bila kamu tahu kodenya (slug).
          </p>

          <p v-if="banner" class="form-error" role="alert">{{ banner }}</p>

          <form @submit.prevent="onSubmit" novalidate>
            <div class="field">
              <label for="name">Nama lengkap</label>
              <input
                id="name"
                v-model="name"
                class="input"
                type="text"
                name="name"
                autocomplete="name"
                required
              />
              <p v-if="errors.name" class="field-error" role="alert">{{ errors.name[0] }}</p>
            </div>

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
                  autocomplete="new-password"
                  minlength="8"
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

            <div class="field">
              <label for="password_confirmation">Ulangi kata sandi</label>
              <div class="field__control">
                <input
                  id="password_confirmation"
                  v-model="passwordConfirmation"
                  class="input"
                  :type="showConfirmation ? 'text' : 'password'"
                  name="password_confirmation"
                  autocomplete="new-password"
                  required
                />
                <button
                  type="button"
                  class="field__toggle"
                  @click="showConfirmation = !showConfirmation"
                  :aria-label="showConfirmation ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                >
                  <svg
                    v-if="showConfirmation"
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
              <p v-if="errors.password_confirmation" class="field-error" role="alert">
                {{ errors.password_confirmation[0] }}
              </p>
            </div>

            <div class="field">
              <label for="organization_slug">Kode organisasi</label>
              <input
                id="organization_slug"
                v-model="organizationSlug"
                class="input"
                type="text"
                name="organization_slug"
                autocomplete="off"
                required
              />
              <p class="hint">
                Slug organisasi tempat kamu mendaftar. Tanyakan ke admin organisasi itu. Contoh:
                lab-kamera.
              </p>
              <p v-if="errors.organization_slug" class="field-error" role="alert">
                {{ errors.organization_slug[0] }}
              </p>
            </div>

            <button type="submit" class="btn btn--primary auth__submit" :disabled="sending">
              <span v-if="sending" class="spinner" aria-hidden="true"></span>
              {{ sending ? 'Mengirim pendaftaran…' : 'Daftar' }}
            </button>
          </form>

          <p class="auth__alt">
            Sudah punya akun?
            <RouterLink :to="{ name: 'login' }" class="link-sweep">Masuk</RouterLink>
          </p>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
/* ===================== Rangka ===================== */
.auth {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-height: 100dvh;
  background: var(--ln-bg);
  color: var(--ln-text);
}

/* Bilah atas: merek kiri, silang ke login kanan. */
.auth__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  min-height: 4.75rem;
  padding: 0.5rem var(--ln-gutter);
  border-bottom: 1px solid var(--ln-border);
}

.auth__brand {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  text-decoration: none;
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

.auth__switch {
  min-height: 44px;
  padding-inline: 0.25rem;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  font-weight: 500;
}

.auth__switch strong {
  color: var(--ln-text);
  font-weight: 600;
}

/* Dua kolom ala hero landing, dipisah garis rule vertikal. */
.auth__grid {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1.02fr) minmax(0, 0.98fr);
  align-items: center;
  gap: clamp(28px, 4vw, 56px);
  width: min(88rem, 100%);
  margin-inline: auto;
  padding: var(--ln-section-y) var(--ln-gutter);
}

/* ===================== Kolom kiri ===================== */
.auth__intro {
  max-width: 42rem;
}

.auth__kicker {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.auth__kicker-rule {
  flex: none;
  width: 25px;
  height: 1px;
  background: currentcolor;
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

/* Rule tebal pemisah blok judul dan daftar poin. */
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
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.5rem 1.25rem;
  min-height: 3rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.auth__point-label {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.auth__point-text {
  color: var(--ln-text-2);
  font-size: 0.9375rem;
  line-height: 1.6;
}

.auth__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 2rem;
  padding-top: 0.5rem;
  border-top: 1px solid var(--ln-border);
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.auth__meta .link-sweep {
  min-height: 44px;
  color: var(--ln-text);
  font-family: var(--ln-font-body);
  font-size: 0.8125rem;
  letter-spacing: normal;
  text-transform: none;
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

.auth__panel {
  width: 100%;
  max-width: 32rem;
  padding: clamp(1.5rem, 3vw, 2.25rem);
  border-top: 2px solid var(--ln-border-strong);
  background: var(--ln-surface);
}

.auth__panel-index {
  margin-bottom: 0.75rem;
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

/* ===================== Panel status ===================== */
.auth__panel--success .auth__panel-note {
  margin-bottom: 1.5rem;
}

.success__icon {
  display: block;
  width: 3rem;
  height: 3rem;
  margin-bottom: 1.25rem;
}

.success__frame {
  stroke: var(--ln-border-strong);
  stroke-width: 2;
}

.success__check {
  stroke: var(--ln-ok);
  stroke-width: 3.5;
  stroke-linecap: square;
  stroke-linejoin: miter;
  stroke-dasharray: 40;
  stroke-dashoffset: 40;
  animation: auth-draw 0.5s var(--ln-ease) 0.15s forwards;
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

.input {
  transition:
    border-color var(--ln-motion-fast) var(--ln-ease),
    background-color var(--ln-motion-fast) var(--ln-ease);
}

.input:hover {
  border-color: var(--ln-border-strong);
}

.input:focus-visible {
  border-color: var(--ln-border-strong);
  background: var(--ln-surface-alt);
}

.field-error {
  animation: auth-rise 0.25s var(--ln-ease) both;
}

.hint {
  margin-top: 0.375rem;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--ln-text-2);
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
    justify-items: center;
  }

  .auth__form-col {
    width: 100%;
    padding-top: 2rem;
    padding-left: 0;
    border-top: 1px solid var(--ln-border);
    border-left: 0;
  }

  /* Mobile: fokus ke formulir saja, panel dirapatkan di tengah. */
  .auth__intro {
    display: none;
  }

  .auth__panel {
    max-width: 26rem;
  }
}

/* ===================== Keyframes ===================== */
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

@keyframes auth-draw {
  to {
    stroke-dashoffset: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .spinner {
    animation: none;
  }

  .success__check {
    stroke-dashoffset: 0;
  }
}
</style>
