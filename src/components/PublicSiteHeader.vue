<script setup>
/*
 * Kepala halaman publik (di luar /app). Bahasa visual sama dengan landing:
 * lockup brand, tautan mono, tombol inversion. Tautan di sini hanya yang benar
 * ada tujuannya: beranda, jelajah, booking, masuk.
 *
 * Customer yang sudah punya akun tidak lewat /app (itu butuh membership), jadi
 * riwayat pemesanannya ada di /booking dan tautannya berdiri di sini.
 */
import { ref } from 'vue'
import { Moon, Sun } from '@lucide/vue'
import { RouterLink, useRouter } from 'vue-router'
import { auth, logout } from '../lib/auth'
import { theme, toggleTheme } from '../lib/theme'

const router = useRouter()
const signingOut = ref(false)

const nav = [
  { name: 'explore', label: 'Jelajah' },
  { name: 'bookings', label: 'Booking saya' },
]

async function onLogout() {
  signingOut.value = true

  try {
    await logout()
    await router.push({ name: 'landing' })
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <header class="pub-top">
    <div class="container pub-top__inner">
      <RouterLink to="/" class="pub-top__brand" aria-label="Lendora, ke beranda">
        <img
          :src="
            theme === 'dark' ? '/brand/lendora-lockup-inverse.svg' : '/brand/lendora-lockup.svg'
          "
          width="132"
          height="21"
          alt="Lendora"
        />
      </RouterLink>

      <nav class="pub-top__nav" aria-label="Navigasi publik">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          class="pub-top__link"
        >
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="pub-top__actions">
        <button
          type="button"
          class="pub-top__theme"
          :aria-label="theme === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
          :aria-pressed="theme === 'dark'"
          @click="toggleTheme()"
        >
          <Moon v-if="theme === 'light'" :size="18" aria-hidden="true" />
          <Sun v-else :size="18" aria-hidden="true" />
        </button>
        <template v-if="auth.user">
          <span class="pub-top__who">{{ auth.user.name }}</span>
          <button type="button" class="btn" :disabled="signingOut" @click="onLogout">
            {{ signingOut ? 'Sedang keluar' : 'Keluar' }}
          </button>
        </template>
        <RouterLink v-else to="/login" class="btn btn--primary">Masuk</RouterLink>
      </div>
    </div>
  </header>
</template>

<style scoped>
.pub-top {
  border-bottom: 1px solid var(--ln-border);
  background: var(--ln-bg);
}

.pub-top__inner {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-height: 4.25rem;
}

.pub-top__brand {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
}

.pub-top__nav {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  margin-inline-start: 1rem;
}

.pub-top__link {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  text-decoration: none;
}

.pub-top__link:hover,
.pub-top__link.router-link-active {
  color: var(--ln-text);
}

.pub-top__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-inline-start: auto;
}

.pub-top__who {
  max-width: 14ch;
  overflow: hidden;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.08em;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pub-top__theme {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  padding: 0;
  border: 1px solid transparent;
  background: transparent;
  color: var(--ln-text);
  cursor: pointer;
  transition:
    border-color var(--ln-motion-fast) var(--ln-ease),
    background-color var(--ln-motion-fast) var(--ln-ease);
}

.pub-top__theme:hover {
  border-color: var(--ln-border-strong);
}
</style>
