<script setup>
/*
 * Status memuat: pulse opasitas (animasi 09), tanpa gradient. Dipakai di
 * seluruh view dengan data async sehingga tampilan tetap stabil.
 */
defineProps({
  label: {
    type: String,
    default: 'Memuat data',
  },
})
</script>

<template>
  <div class="loading" role="status" aria-live="polite">
    <span class="loading__bar" aria-hidden="true"><span></span></span>
    <span class="loading__label">{{ label }}</span>
  </div>
</template>

<style scoped>
.loading {
  display: grid;
  gap: 0.75rem;
  max-width: 24rem;
  padding: 2rem 0;
}

.loading__bar {
  position: relative;
  display: block;
  height: 2px;
  overflow: hidden;
  background: var(--ln-border);
}

.loading__bar > span {
  position: absolute;
  inset: 0;
  width: 40%;
  background: var(--ln-text);
  animation: ln-load-slide 1.1s var(--ln-ease) infinite;
}

@keyframes ln-load-slide {
  0% {
    transform: translateX(-100%);
  }

  100% {
    transform: translateX(250%);
  }
}

.loading__label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
  text-transform: uppercase;
  animation: ln-load-pulse 1.1s ease-in-out infinite;
}

@keyframes ln-load-pulse {
  50% {
    opacity: 0.45;
  }
}
</style>
