<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue'
import { gsap } from 'gsap'
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { motion } from '../utils/motion'

defineProps<{ theme: 'light' | 'dark' }>()
const emit = defineEmits<{ toggleTheme: [] }>()

/* ─── Menu seluler: hamburger + panel disclosure beranimasi GSAP ───
   Panel dan scrim dirender lewat v-if, dan baru dilepas setelah tween
   tutup selesai — buka/tutup selalu mulus tanpa kedip. */

const menuOpen = ref(false)
const burgerRef = ref<HTMLButtonElement | null>(null)
const scrimRef = ref<HTMLElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const listRef = ref<HTMLElement | null>(null)

let panelTween: gsap.core.Timeline | null = null

function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

async function openMenu() {
  if (menuOpen.value) return
  menuOpen.value = true
  await nextTick()

  const scrim = scrimRef.value
  const panel = panelRef.value
  if (!panel || prefersReducedMotion()) return

  panelTween?.kill()
  const items = listRef.value ? Array.from(listRef.value.children) : []

  panelTween = gsap
    .timeline({ defaults: { ease: motion.ease.standard } })
    .fromTo(scrim, { autoAlpha: 0 }, { autoAlpha: 1, duration: 0.28 }, 0)
    .fromTo(panel, { autoAlpha: 0, y: -14 }, { autoAlpha: 1, y: 0, duration: 0.32 }, 0.02)
    .fromTo(
      items,
      { autoAlpha: 0, y: 12 },
      { autoAlpha: 1, y: 0, duration: 0.26, stagger: 0.05 },
      0.1,
    )
}

function closeMenu(options: { restoreFocus?: boolean } = {}) {
  if (!menuOpen.value) return

  const finish = () => {
    menuOpen.value = false
    if (options.restoreFocus) burgerRef.value?.focus()
  }

  const scrim = scrimRef.value
  const panel = panelRef.value
  if (!panel || prefersReducedMotion()) {
    finish()
    return
  }

  panelTween?.kill()
  panelTween = gsap
    .timeline({ onComplete: finish })
    .to(panel, { autoAlpha: 0, y: -10, duration: 0.2, ease: 'power2.in' }, 0)
    .to(scrim, { autoAlpha: 0, duration: 0.22, ease: 'power2.in' }, 0.02)
}

function toggleMenu() {
  if (menuOpen.value) closeMenu({ restoreFocus: true })
  else void openMenu()
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && menuOpen.value) closeMenu({ restoreFocus: true })
}

// Layar membesar ke desktop: pastikan panel tidak tertinggal terbuka.
function onDesktopMedia(event: MediaQueryListEvent) {
  if (event.matches) closeMenu()
}

let desktopMq: MediaQueryList | undefined

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  desktopMq = window.matchMedia('(min-width: 761px)')
  desktopMq.addEventListener('change', onDesktopMedia)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  desktopMq?.removeEventListener('change', onDesktopMedia)
  panelTween?.kill()
})
</script>

<template>
  <header class="site-header">
    <a class="brand-link" href="#awal" aria-label="Lendora, kembali ke awal" @click="closeMenu()">
      <img
        :src="theme === 'dark' ? '/brand/lendora-lockup-inverse.svg' : '/brand/lendora-lockup.svg'"
        width="150"
        height="24"
        alt="Lendora"
      />
    </a>

    <nav class="desktop-nav" aria-label="Navigasi utama">
      <a href="#manfaat">Manfaat</a>
      <a href="#fitur">Fitur</a>
      <a href="#alur">Alur kerja</a>
    </nav>

    <div class="header-actions">
      <button
        class="theme-toggle"
        type="button"
        :aria-label="theme === 'light' ? 'Aktifkan mode gelap' : 'Aktifkan mode terang'"
        :aria-pressed="theme === 'dark'"
        @click="emit('toggleTheme')"
      >
        <Moon v-if="theme === 'light'" :size="18" aria-hidden="true" />
        <Sun v-else :size="18" aria-hidden="true" />
      </button>
      <a class="button button-ghost header-login" href="/login">Masuk</a>
      <a class="button button-dark header-cta" href="/login"> Mulai </a>

      <!-- Hamburger: satu kotak dengan theme-toggle, dapat gap dari .header-actions -->
      <button
        ref="burgerRef"
        type="button"
        class="menu-toggle"
        :class="{ 'menu-toggle--open': menuOpen }"
        :aria-expanded="menuOpen"
        aria-controls="mobile-nav"
        :aria-label="menuOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi'"
        @click="toggleMenu"
      >
        <span class="menu-label" aria-hidden="true">Menu</span>
        <span class="menu-icon" aria-hidden="true"><span></span><span></span></span>
      </button>
    </div>

    <!-- Scrim: klik di mana pun di bawah header untuk menutup -->
    <div
      v-if="menuOpen"
      ref="scrimRef"
      class="menu-scrim"
      aria-hidden="true"
      @click="closeMenu({ restoreFocus: true })"
    ></div>

    <!-- Panel menu seluler -->
    <div v-if="menuOpen" id="mobile-nav" ref="panelRef" class="mobile-panel">
      <nav ref="listRef" class="mobile-nav" aria-label="Navigasi seluler">
        <a href="#manfaat" @click="closeMenu()"> Manfaat </a>
        <a href="#fitur" @click="closeMenu()"> Fitur </a>
        <a href="#alur" @click="closeMenu()"> Alur kerja </a>
        <div class="mobile-nav-actions">
          <a href="/login" class="button button-ghost" @click="closeMenu()">Masuk</a>
          <a href="/login" class="button button-dark" @click="closeMenu()">Mulai</a>
        </div>
      </nav>
    </div>
  </header>
</template>
