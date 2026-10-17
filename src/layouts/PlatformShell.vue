<script setup>
/*
 * Shell konsol platform. Rute /platform sengaja di luar /app: admin platform
 * bekerja lintas organisasi dan tidak memerlukan membership.
 *
 * Kepala tipis: tiga menu (pengguna, penyedia, dompet), tombol tema, pintu kembali ke
 * aplikasi bila akun juga punya membership, dan tombol keluar.
 */
import { ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { Moon, Sun } from '@lucide/vue'
import { auth, logout } from '../lib/auth'
import { theme, toggleTheme } from '../lib/theme'
import ToastHost from '../components/ui/ToastHost.vue'

const router = useRouter()
const signingOut = ref(false)

const nav = [
  { name: 'platform-users', label: 'Pengguna' },
  { name: 'platform-providers', label: 'Penyedia' },
  { name: 'platform-wallet', label: 'Dompet' },
  { name: 'platform-finance', label: 'Keuangan' },
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
  <div class="console">
    <a class="skip-link" href="#konten">Lewati ke konten</a>

    <header class="console__top">
      <div class="container console__inner">
        <RouterLink
          :to="{ name: 'platform-users' }"
          class="console__brand"
          aria-label="Lendora, konsol platform"
        >
          <img
            :src="
              theme === 'dark' ? '/brand/lendora-lockup-inverse.svg' : '/brand/lendora-lockup.svg'
            "
            width="132"
            height="21"
            alt="Lendora"
          />
        </RouterLink>

        <p class="console__tag index-label">KONSOL PLATFORM</p>

        <nav class="console__nav" aria-label="Navigasi konsol">
          <RouterLink
            v-for="item in nav"
            :key="item.name"
            :to="{ name: item.name }"
            class="console__link"
          >
            {{ item.label }}
          </RouterLink>
        </nav>

        <div class="console__actions">
          <button
            type="button"
            class="console__theme"
            :aria-label="theme === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
            :aria-pressed="theme === 'dark'"
            @click="toggleTheme()"
          >
            <Moon v-if="theme === 'light'" :size="18" aria-hidden="true" />
            <Sun v-else :size="18" aria-hidden="true" />
          </button>
          <RouterLink v-if="auth.role" :to="{ name: 'home' }" class="btn">Ke aplikasi</RouterLink>
          <span class="console__who">{{ auth.user?.name }}</span>
          <button type="button" class="btn" :disabled="signingOut" @click="onLogout">
            {{ signingOut ? 'Sedang keluar' : 'Keluar' }}
          </button>
        </div>
      </div>
    </header>

    <main id="konten" class="container console__body" tabindex="-1">
      <RouterView />
    </main>

    <ToastHost />
  </div>
</template>

<style scoped>
.console {
  min-height: 100vh;
  background: var(--ln-bg);
}

.console__top {
  border-bottom: 1px solid var(--ln-border);
}

.console__inner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  min-height: 4.25rem;
  padding-block: 0.5rem;
}

.console__brand {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
}

.console__tag {
  margin: 0;
  padding-left: 1rem;
  border-left: 1px solid var(--ln-border);
}

.console__nav {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1.25rem;
  margin-inline-start: 0.5rem;
}

.console__link {
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

.console__link:hover,
.console__link.router-link-active {
  color: var(--ln-text);
}

.console__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin-inline-start: auto;
}

.console__who {
  max-width: 16ch;
  overflow: hidden;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.08em;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.console__theme {
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

.console__theme:hover {
  border-color: var(--ln-border-strong);
}

.console__body {
  display: grid;
  gap: 1.5rem;
  padding-block: var(--ln-section-y);
}
</style>
