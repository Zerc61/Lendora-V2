<script setup>
/*
 * Kepala halaman editorial: label kicker, judul display, teks pembuka,
 * dan aksi. Gerak masuknya stagger (kicker → judul → lede → aksi) dan otomatis
 * nonaktif saat prefers-reduced-motion.
 */
import { ref } from 'vue'
import { gsap } from 'gsap'
import { usePageMotion } from '../../lib/motion'

defineProps({
  label: { type: String, default: null },
  title: { type: String, required: true },
  lede: { type: String, default: null },
})

const root = ref(null)
const motion = usePageMotion(root)

motion.run(() => {
  gsap
    .timeline({ defaults: { ease: 'power3.out' } })
    .from('.page-head__index', { x: -8, autoAlpha: 0, duration: 0.22 })
    .from('.page-head__title', { y: 18, autoAlpha: 0, duration: 0.5 }, '-=0.08')
    .from('.page-head__lede', { y: 10, autoAlpha: 0, duration: 0.36 }, '-=0.3')
    .from('.page-head__actions > *', { y: 8, autoAlpha: 0, duration: 0.3, stagger: 0.05 }, '-=0.24')
})
</script>

<template>
  <header ref="root" class="page-head">
    <p v-if="label" class="page-head__index index-label">{{ label }}</p>
    <h1 class="page-head__title">{{ title }}</h1>
    <p v-if="lede || $slots.default" class="page-head__lede lede">
      <slot>{{ lede }}</slot>
    </p>
    <div v-if="$slots.actions" class="page-head__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.page-head {
  display: grid;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--ln-border);
}

.page-head__index {
  margin-bottom: 1.25rem;
}

.page-head__title {
  max-width: 20ch;
  margin-bottom: 1rem;
}

.page-head__lede {
  margin: 0;
}

.page-head__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1.5rem;
}
</style>
