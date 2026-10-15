<script setup>
/*
 * Badge status fungsional, memakai warna status brand kit. Bukan hiasan:
 * label status selalu berasal dari data API (*_label). Sudut tajam mengikuti
 * identitas (radius 0).
 */
defineProps({
  tone: {
    type: String,
    default: 'neutral',
  },
})
</script>

<template>
  <span class="badge" :class="`badge--${tone}`"><slot /></span>
</template>

<style scoped>
.badge {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.25rem 0.5rem;
  border: 1px solid transparent;
  border-radius: var(--ln-radius);
  font-family: var(--ln-font-mono);
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  line-height: 1.25;
  text-transform: uppercase;
  white-space: nowrap;
}

.badge--neutral {
  color: var(--ln-text-2);
  background: var(--ln-surface-alt);
  border-color: var(--ln-border);
}

.badge--ok {
  color: var(--ln-ok);
  background: var(--ln-ok-bg);
}

.badge--warn {
  color: var(--ln-warn);
  background: var(--ln-warn-bg);
}

.badge--err {
  color: var(--ln-err);
  background: var(--ln-err-bg);
}

.badge--info {
  color: var(--ln-info);
  background: var(--ln-info-bg);
}
</style>
