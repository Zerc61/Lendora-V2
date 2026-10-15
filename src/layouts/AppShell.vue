<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
// Ikon lonceng: penanda notifikasi, dipilih per relevansi isi; X: tutup drawer (R-04).
import { Bell, X } from '@lucide/vue'
import { auth, switchOrganization } from '../lib/auth'
import { theme, toggleTheme } from '../lib/theme'
import { apiGet } from '../lib/http'
import { NAV_BY_ROLE } from '../lib/navigation'
import { roleLabel } from '../lib/roles'
import { reducedMotion } from '../lib/motion'
import NotificationCenter from '../components/NotificationCenter.vue'
import ToastHost from '../components/ui/ToastHost.vue'

gsap.registerPlugin(ScrollTrigger)

const router = useRouter()
const route = useRoute()

// Hanya tampilkan entri navigasi yang route-nya benar-benar ada (R-24).
const navItems = computed(() =>
  (NAV_BY_ROLE[auth.role] ?? []).filter((item) => router.hasRoute(item.name)),
)

// Sorotan halaman aktif dihitung sendiri: section anak (mis. /app/loans/5)
// menandai induknya, sedangkan beranda (/app) hanya aktif di root.
function isNavActive({ name }) {
  const target = router.resolve({ name }).path
  if (target === '/app') return route.path === '/app'
  return route.path === target || route.path.startsWith(`${target}/`)
}

const activeOrg = computed(
  () => auth.organizations.find((org) => org.id === auth.activeOrganizationId) ?? null,
)

const canSwitchOrg = computed(() => auth.organizations.length > 1)

async function onSwitchOrg(event) {
  await switchOrganization(Number(event.target.value))
}

function onToggleTheme() {
  toggleTheme()
}

/* ─── Panel navbar: notifikasi (profil pindah ke halaman /app/profile) ─── */

const openPanel = ref(null) // 'notes' | null
const unreadCount = ref(0)

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join(''),
)

function togglePanel(name) {
  openPanel.value = openPanel.value === name ? null : name
}

async function refreshUnread() {
  try {
    const payload = await apiGet('/notifications/unread-count')
    unreadCount.value = payload.count ?? 0
  } catch {
    // Lencana hanya penanda; kegagalan memuatnya tidak perlu mengganggu shell.
  }
}

function onDocumentClick(event) {
  if (!event.target.closest('.pop')) {
    openPanel.value = null
  }
}

/* ─── Drawer navigasi mobile (hamburger) ───
   Terbuka dengan GSAP: backdrop memudar, panel meluncur dari kanan,
   lalu tautan muncul satu per satu (stagger). */

const drawerOpen = ref(false)
const hamburgerRef = ref(null)
const drawerBackdropRef = ref(null)
const drawerPanelRef = ref(null)
const drawerNavRef = ref(null)

let drawerTween = null
let mqDesktop = null
let scrollLockPrev = ''

function setScrollLock(lock) {
  const root = document.documentElement
  if (lock) {
    scrollLockPrev = root.style.overflow
    root.style.overflow = 'hidden'
    document.body.style.overflow = 'hidden'
  } else {
    root.style.overflow = scrollLockPrev
    document.body.style.overflow = ''
  }
}

async function openDrawer() {
  if (drawerOpen.value) return
  openPanel.value = null
  drawerOpen.value = true
  await nextTick()
  setScrollLock(true)
  drawerPanelRef.value?.focus({ preventScroll: true })

  if (reducedMotion()) return

  drawerTween?.kill()
  const links = drawerNavRef.value ? Array.from(drawerNavRef.value.children) : []
  const fades = Array.from(
    drawerPanelRef.value?.querySelectorAll('.drawer__head, .drawer__foot') ?? [],
  )

  drawerTween = gsap
    .timeline({ defaults: { ease: 'power3.out' } })
    .fromTo(drawerBackdropRef.value, { opacity: 0 }, { opacity: 1, duration: 0.35 }, 0)
    .fromTo(drawerPanelRef.value, { xPercent: 100 }, { xPercent: 0, duration: 0.5 }, 0)

  if (links.length) {
    drawerTween.fromTo(
      links,
      { y: 24, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.45, stagger: 0.055 },
      0.12,
    )
  }
  if (fades.length) {
    drawerTween.fromTo(fades, { opacity: 0 }, { opacity: 1, duration: 0.45 }, 0.2)
  }
}

function closeDrawer({ restoreFocus = false } = {}) {
  if (!drawerOpen.value) return

  const finish = () => {
    drawerOpen.value = false
    setScrollLock(false)
    if (restoreFocus) hamburgerRef.value?.focus()
  }

  if (reducedMotion()) {
    finish()
    return
  }

  drawerTween?.kill()
  drawerTween = gsap
    .timeline({ onComplete: finish })
    .to(drawerPanelRef.value, { xPercent: 100, duration: 0.4, ease: 'power3.in' }, 0)
    .to(drawerBackdropRef.value, { opacity: 0, duration: 0.35, ease: 'power2.in' }, 0.05)
}

function toggleDrawer() {
  if (drawerOpen.value) closeDrawer({ restoreFocus: true })
  else openDrawer()
}

// Jerat fokus: Tab tidak boleh lolos keluar drawer saat terbuka.
function trapDrawerFocus(event) {
  if (!drawerOpen.value) return
  const panel = drawerPanelRef.value
  if (!panel) return

  const focusables = Array.from(
    panel.querySelectorAll('a[href], button:not([disabled]), select, input'),
  )
  if (!focusables.length) return

  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  const inside = panel.contains(document.activeElement)

  if (event.shiftKey && (!inside || document.activeElement === first)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (!inside || document.activeElement === last)) {
    event.preventDefault()
    first.focus()
  }
}

function onMediaChange(event) {
  // Melewati breakpoint ke desktop: drawer ditutup otomatis.
  if (event.matches) closeDrawer()
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    if (drawerOpen.value) closeDrawer({ restoreFocus: true })
    else openPanel.value = null
    return
  }
  if (event.key === 'Tab') trapDrawerFocus(event)
}

watch(
  () => route.fullPath,
  () => {
    openPanel.value = null
    closeDrawer()
  },
)

/* ─── Gerak shell: progres gulir, header ringkas, transisi antar rute ─── */

const pageProgress = ref(null)
const compact = ref(false)
let scrollContext = null

// Avatar error handling
const avatarError = ref(false)
// Base URL for avatar
const baseUrl = import.meta.env.VITE_APP_API_BASE
// Computed avatar URL
const avatarUrl = computed(() => {
  if (!auth.user?.avatar_url) return null
  if (auth.user.avatar_url.startsWith('http')) {
    return auth.user.avatar_url
  }
  return baseUrl + auth.user.avatar_url
})

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  window.addEventListener('keydown', onKeydown)
  refreshUnread()

  mqDesktop = window.matchMedia('(min-width: 60rem)')
  mqDesktop.addEventListener('change', onMediaChange)

  if (reducedMotion()) return

  scrollContext = gsap.context(() => {
    gsap.set(pageProgress.value, { scaleX: 0, transformOrigin: 'left center' })
    gsap.to(pageProgress.value, {
      scaleX: 1,
      ease: 'none',
      scrollTrigger: { start: 0, end: 'max', scrub: 0.3 },
    })
    ScrollTrigger.create({
      start: 60,
      onEnter: () => (compact.value = true),
      onLeaveBack: () => (compact.value = false),
    })
  })
})

// Bersihkan saat shell dilepas (misalnya logout).
onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
  window.removeEventListener('keydown', onKeydown)
  mqDesktop?.removeEventListener('change', onMediaChange)
  drawerTween?.kill()
  setScrollLock(false)
  scrollContext?.revert()
})
</script>

<template>
  <div class="shell">
    <a class="skip-link" href="#konten">Langsung ke konten</a>

    <header class="topbar" :class="{ 'topbar--compact': compact }">
      <div class="container topbar__inner">
        <RouterLink :to="{ name: 'home' }" class="brand" aria-label="Lendora, ke beranda">
          <img
            class="brand__img brand__img--default"
            src="/brand/lendora-lockup.svg"
            alt=""
            width="150"
            height="36"
          />
          <img
            class="brand__img brand__img--inverse"
            src="/brand/lendora-lockup-inverse.svg"
            alt=""
            width="150"
            height="36"
          />
        </RouterLink>

        <nav class="nav" aria-label="Navigasi utama">
          <RouterLink
            v-for="item in navItems"
            :key="item.name"
            :to="{ name: item.name }"
            :class="['nav__link', isNavActive(item) && 'nav__link--active']"
            :aria-current="isNavActive(item) ? 'page' : undefined"
          >
            <span class="nav__label">{{ item.label }}</span>
          </RouterLink>
        </nav>

        <div class="toolbar">
          <label v-if="canSwitchOrg" for="org-switch" class="visually-hidden">
            Organisasi aktif
          </label>
          <select
            v-if="canSwitchOrg"
            id="org-switch"
            class="org-switch"
            :value="auth.activeOrganizationId"
            @change="onSwitchOrg"
          >
            <option v-for="org in auth.organizations" :key="org.id" :value="org.id">
              {{ org.name }}
            </option>
          </select>
          <span v-else-if="activeOrg" class="org-name">{{ activeOrg.name }}</span>

          <div class="pop">
            <button
              type="button"
              class="tool"
              aria-haspopup="dialog"
              :aria-expanded="openPanel === 'notes'"
              @click.stop="togglePanel('notes')"
            >
              <Bell :size="18" aria-hidden="true" />
              <span v-if="unreadCount" class="tool__badge num">
                {{ unreadCount > 99 ? '99+' : unreadCount }}
              </span>
              <span class="visually-hidden">Notifikasi</span>
            </button>

            <div
              v-if="openPanel === 'notes'"
              class="pop__panel"
              role="region"
              aria-label="Notifikasi"
            >
              <NotificationCenter @unread="(count) => (unreadCount = count)" />
            </div>
          </div>

          <button
            type="button"
            class="tool"
            :aria-label="theme === 'dark' ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'"
            @click="onToggleTheme"
          >
            <svg
              v-if="theme === 'dark'"
              aria-hidden="true"
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
            >
              <circle cx="12" cy="12" r="4" />
              <path
                d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"
              />
            </svg>
            <svg
              v-else
              aria-hidden="true"
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
            >
              <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
            </svg>
            <span class="visually-hidden">Tema</span>
          </button>

          <RouterLink
            :to="{ name: 'profile' }"
            class="avatar"
            :aria-label="`Profil ${auth.user?.name ?? ''}`"
          >
            <img
              v-if="avatarUrl && !avatarError"
              class="avatar__img"
              :src="avatarUrl"
              @error="avatarError = true"
              alt=""
            />
            <span v-else class="avatar__initials num" aria-hidden="true">{{ initials }}</span>
          </RouterLink>

          <!-- Hamburger: hanya tampil di mobile, morphing menjadi ✕ -->
          <button
            ref="hamburgerRef"
            type="button"
            class="tool burger"
            :class="{ 'burger--open': drawerOpen }"
            :aria-expanded="drawerOpen"
            aria-controls="drawer-panel"
            aria-label="Buka menu navigasi"
            @click="toggleDrawer"
          >
            <span class="burger__box" aria-hidden="true">
              <span class="burger__line"></span>
              <span class="burger__line"></span>
              <span class="burger__line"></span>
            </span>
          </button>
        </div>
      </div>

      <span class="topbar__progress" aria-hidden="true"><span ref="pageProgress"></span></span>
    </header>

    <!-- Drawer navigasi mobile; di-teleport ke body agar bebas dari stacking context topbar -->
    <Teleport to="body">
      <div
        v-if="drawerOpen"
        class="drawer"
        role="dialog"
        aria-modal="true"
        aria-label="Menu navigasi"
      >
        <div
          ref="drawerBackdropRef"
          class="drawer__backdrop"
          aria-hidden="true"
          @click="closeDrawer({ restoreFocus: true })"
        ></div>

        <div id="drawer-panel" ref="drawerPanelRef" class="drawer__panel" tabindex="-1">
          <div class="drawer__head">
            <span class="drawer__eyebrow">Menu</span>
            <button
              type="button"
              class="tool"
              aria-label="Tutup menu"
              @click="closeDrawer({ restoreFocus: true })"
            >
              <X :size="18" aria-hidden="true" />
            </button>
          </div>

          <nav ref="drawerNavRef" class="drawer__nav" aria-label="Navigasi utama">
            <RouterLink
              v-for="item in navItems"
              :key="item.name"
              :to="{ name: item.name }"
              :class="['drawer__link', isNavActive(item) && 'drawer__link--active']"
              :aria-current="isNavActive(item) ? 'page' : undefined"
            >
              <span class="drawer__label">{{ item.label }}</span>
            </RouterLink>
          </nav>

          <div class="drawer__foot">
            <template v-if="canSwitchOrg">
              <label class="drawer__label" for="org-switch-drawer">Organisasi aktif</label>
              <select
                id="org-switch-drawer"
                class="org-switch"
                :value="auth.activeOrganizationId"
                @change="onSwitchOrg"
              >
                <option v-for="org in auth.organizations" :key="org.id" :value="org.id">
                  {{ org.name }}
                </option>
              </select>
            </template>
            <span v-else-if="activeOrg" class="org-name">{{ activeOrg.name }}</span>
            <span class="drawer__role">{{ roleLabel(auth.role) }}</span>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Navigasi cepat mobile: bar bawah dengan ikon -->
    <nav class="botnav" aria-label="Navigasi cepat">
      <RouterLink
        v-for="item in navItems"
        :key="item.name"
        :to="{ name: item.name }"
        :class="['botnav__link', isNavActive(item) && 'botnav__link--active']"
        :aria-current="isNavActive(item) ? 'page' : undefined"
      >
        <component :is="item.icon" :size="19" :stroke-width="1.75" aria-hidden="true" />
        <span class="botnav__label">{{ item.label }}</span>
      </RouterLink>
    </nav>

    <main id="konten" class="container main" tabindex="-1">
      <RouterView v-slot="{ Component, route: current }">
        <component :is="Component" :key="`${current.name}:${current.params.id ?? ''}`" />
      </RouterView>
    </main>

    <footer class="shell-foot container">
      <span>© LENDORA V2</span>
      <span class="shell-foot__sep" aria-hidden="true">/</span>
      <span>ASET · PEMINJAMAN · KONDISI</span>
      <span class="shell-foot__role">{{ roleLabel(auth.role) }}</span>
    </footer>
  </div>

  <ToastHost />
</template>

<style scoped>
.shell {
  display: flex;
  min-height: 100vh;
  flex-direction: column;
}

.topbar {
  position: sticky;
  z-index: 30;
  top: 0;
  border-bottom: 1px solid var(--ln-border);
  background: color-mix(in srgb, var(--ln-bg) 94%, transparent);
  backdrop-filter: blur(10px);
  transition: background-color var(--ln-motion-base) var(--ln-ease);
}

.topbar__inner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1.5rem;
  padding-block: 1rem;
  transition: padding-block var(--ln-motion-base) var(--ln-ease);
}

.topbar--compact .topbar__inner {
  padding-block: 0.5rem;
}

.topbar__progress {
  position: absolute;
  right: 0;
  bottom: -1px;
  left: 0;
  height: 2px;
  overflow: hidden;
}

.topbar__progress span {
  display: block;
  width: 100%;
  height: 100%;
  background: var(--ln-text);
  transform: scaleX(0);
  transform-origin: left center;
}

.brand {
  display: inline-flex;
  flex-shrink: 0;
  min-height: 44px;
  align-items: center;
  text-decoration: none;
}

.brand__img {
  height: 1.5rem;
  width: auto;
  display: block;
  transition: height var(--ln-motion-base) var(--ln-ease);
}

.topbar--compact .brand__img {
  height: 1.25rem;
}

[data-theme='dark'] .brand__img--default {
  display: none;
}

.brand__img--inverse {
  display: none;
}

[data-theme='dark'] .brand__img--inverse {
  display: block;
}

.nav {
  display: flex;
  gap: 0.25rem;
  overflow-x: auto;
  scrollbar-width: none;
}

.nav::-webkit-scrollbar {
  display: none;
}

.nav__link {
  display: inline-flex;
  align-items: baseline;
  gap: 0.375rem;
  min-height: 44px;
  padding: 0.625rem 0.75rem;
  border: 1px solid transparent;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  transition:
    color var(--ln-motion-fast) var(--ln-ease),
    background-color var(--ln-motion-fast) var(--ln-ease),
    border-color var(--ln-motion-fast) var(--ln-ease);
}

.nav__link:hover {
  color: var(--ln-text);
}

.nav__link--active {
  color: var(--ln-bg);
  background: var(--ln-text);
  border-color: var(--ln-text);
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-left: auto;
}

.org-switch {
  min-height: 44px;
  max-width: 14rem;
  padding: 0.375rem 0.5rem;
  border: 1px solid var(--ln-border);
  border-radius: var(--ln-radius);
  background: var(--ln-surface);
  color: var(--ln-text);
  font: inherit;
  font-size: 0.875rem;
}

.org-name {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.tool {
  position: relative;
  display: inline-grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 1px solid var(--ln-border);
  border-radius: var(--ln-radius);
  background: transparent;
  color: var(--ln-text);
  cursor: pointer;
  transition:
    background-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease);
}

.tool:hover {
  background: var(--ln-text);
  color: var(--ln-bg);
}

.tool__badge {
  position: absolute;
  top: -0.375rem;
  right: -0.375rem;
  min-width: 1.125rem;
  padding: 0 0.25rem;
  border: 1px solid var(--ln-bg);
  background: var(--ln-err);
  color: #fff;
  font-size: 0.625rem;
  line-height: 1.25rem;
  text-align: center;
}

/* ─── Tombol hamburger (hanya mobile) ─── */

.burger {
  display: none;
}

.burger__box {
  position: relative;
  display: block;
  width: 18px;
  height: 12px;
}

.burger__line {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 2px;
  border-radius: 1px;
  background: currentColor;
  transition:
    transform 0.4s var(--ln-ease),
    opacity 0.3s var(--ln-ease);
}

.burger__line:nth-child(1) {
  transform: translateY(0);
}

.burger__line:nth-child(2) {
  transform: translateY(5px);
}

.burger__line:nth-child(3) {
  transform: translateY(10px);
}

.burger--open .burger__line:nth-child(1) {
  transform: translateY(5px) rotate(45deg);
  transition-delay: 0.05s;
}

.burger--open .burger__line:nth-child(2) {
  opacity: 0;
  transform: translateY(5px) scaleX(0.2);
}

.burger--open .burger__line:nth-child(3) {
  transform: translateY(5px) rotate(-45deg);
  transition-delay: 0.05s;
}

/* ─── Bar navigasi bawah (hanya mobile) ─── */

.botnav {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 30;
  display: none;
  border-top: 1px solid var(--ln-border);
  background: color-mix(in srgb, var(--ln-bg) 94%, transparent);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  padding-bottom: env(safe-area-inset-bottom);
}

.botnav__link {
  display: grid;
  place-items: center;
  gap: 0.2rem;
  min-height: 54px;
  padding: 0.5rem 0.25rem 0.375rem;
  color: var(--ln-text-2);
  font-size: 0.625rem;
  font-weight: 600;
  text-align: center;
  text-decoration: none;
  transition:
    color var(--ln-motion-fast) var(--ln-ease),
    background-color var(--ln-motion-fast) var(--ln-ease);
}

.botnav__link--active {
  color: var(--ln-text);
  background: var(--ln-surface-alt);
}

.botnav__label {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ─── Drawer navigasi mobile ─── */

.drawer {
  position: fixed;
  inset: 0;
  z-index: 60;
}

.drawer__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(9, 9, 14, 0.55);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}

.drawer__panel {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  width: min(21rem, 88vw);
  overflow-y: auto;
  overscroll-behavior: contain;
  background: var(--ln-bg);
  border-left: 1px solid var(--ln-border-strong);
  box-shadow: -32px 0 80px -40px rgba(10, 10, 16, 0.55);
  outline: none;
  will-change: transform;
}

.drawer__head {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: max(0.75rem, env(safe-area-inset-top)) 0.875rem 0.75rem;
  border-bottom: 1px solid var(--ln-border);
}

.drawer__eyebrow {
  color: var(--ln-text);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.drawer__nav {
  display: grid;
  flex: 1;
  align-content: start;
  gap: 0.25rem;
  padding: 1rem 0.875rem;
}

.drawer__link {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  min-height: 52px;
  padding: 0.75rem 0.875rem;
  border: 1px solid transparent;
  border-radius: var(--ln-radius);
  color: var(--ln-text);
  font-size: 1rem;
  font-weight: 600;
  text-decoration: none;
  transition:
    background-color var(--ln-motion-fast) var(--ln-ease),
    border-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease),
    transform var(--ln-motion-fast) var(--ln-ease);
}

.drawer__link:active {
  transform: scale(0.98);
}

.drawer__link:focus-visible {
  outline: 2px solid var(--ln-text);
  outline-offset: 2px;
}

.drawer__label {
  min-width: 0;
}

.drawer__link:hover {
  background: var(--ln-surface-alt);
}

.drawer__link--active {
  color: var(--ln-bg);
  background: var(--ln-text);
  border-color: var(--ln-text);
}

.drawer__foot {
  display: grid;
  flex: none;
  gap: 0.5rem;
  padding: 1rem 0.875rem max(1rem, env(safe-area-inset-bottom));
  border-top: 1px solid var(--ln-border);
}

.drawer__foot .org-switch {
  width: 100%;
  max-width: none;
}

.drawer__label,
.drawer__role {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.drawer__role {
  margin-top: 0.25rem;
}

/* Panel dropdown navbar: notifikasi. Bayangan: satu-satunya penanda elevasi
   di shell, supaya panel terbaca mengambang di atas isi halaman saat ini. */
.pop {
  position: relative;
  display: inline-flex;
}

.pop__panel {
  position: absolute;
  top: calc(100% + 0.5rem);
  right: 0;
  z-index: 40;
  display: grid;
  gap: 0.875rem;
  width: min(24rem, calc(100vw - 2rem));
  max-height: min(70vh, 34rem);
  overflow: auto;
  padding: 1rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-surface);
  box-shadow: 0 16px 48px -24px rgba(10, 10, 16, 0.4);
}

.avatar {
  display: inline-grid;
  place-items: center;
  width: 44px;
  height: 44px;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--ln-border-strong);
  border-radius: 999px;
  background: var(--ln-surface-alt);
  color: var(--ln-text);
  cursor: pointer;
}

.avatar__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar__initials {
  font-size: 0.8125rem;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.main {
  flex: 1;
  padding-block: var(--ln-section-y) 4rem;
}

.shell-foot {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding-block: 1.5rem;
  border-top: 1px solid var(--ln-border);
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.shell-foot__sep {
  color: var(--ln-n400);
}

.shell-foot__role {
  margin-left: auto;
  color: var(--ln-text);
}

@media (max-width: 60rem) {
  .botnav {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
  }

  /* Ruang untuk bar bawah agar konten dan footer tidak tertutup. */
  .main {
    padding-bottom: 6.5rem;
  }

  .topbar__inner {
    gap: 0.5rem;
    padding-block: 0.75rem;
  }

  /* Mobile: nav inline digantikan drawer hamburger (Teleport di atas). */
  .nav {
    display: none;
  }

  .toolbar {
    gap: 0.5rem;
  }

  .toolbar .org-switch,
  .toolbar .org-name {
    display: none;
  }

  .tool {
    width: 40px;
    height: 40px;
  }

  .avatar {
    width: 36px;
    height: 36px;
  }

  .burger {
    display: inline-grid;
  }

  .brand__img {
    height: 1.25rem;
  }

  /* Panel notifikasi: melebar penuh sepanjang bar atas; tinggi mengikuti
     isi dengan batas + gulir, agar tidak keluar layar (permintaan mobile). */
  .topbar__inner {
    position: relative;
  }

  .pop {
    position: static;
  }

  .pop__panel {
    right: 0;
    left: 0;
    width: auto;
    max-height: 65vh;
  }
}

@media (prefers-reduced-motion: reduce) {
  .topbar__progress {
    display: none;
  }

  .burger__line {
    transition: none;
  }

  .drawer__link:active {
    transform: none;
  }
}
</style>
