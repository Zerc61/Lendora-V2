<script setup>
/*
 * Kartu aset untuk daftar (katalog dan manajemen). Seluruh kartu adalah satu
 * tautan ke detail aset. Foto memakai srcset sederhana dari endpoint
 * terautorisasi `/api/attachments/{id}` (same-origin, session ikut).
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import StatusBadge from './StatusBadge.vue'

const props = defineProps({
  asset: { type: Object, required: true },
  management: { type: Boolean, default: false },
})

const photo = computed(() => props.asset.photos?.[0] ?? null)

const LIFECYCLE_TONES = {
  active: 'ok',
  awaiting_inspection: 'info',
  under_maintenance: 'warn',
  retired: 'neutral',
}

const lifecycleTone = computed(() => LIFECYCLE_TONES[props.asset.lifecycle_status] ?? 'neutral')

const initials = computed(() =>
  props.asset.name
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0])
    .join(''),
)
</script>

<template>
  <RouterLink :to="{ name: 'asset-detail', params: { id: asset.id } }" class="card">
    <div class="card__thumb">
      <img
        v-if="photo"
        class="card__img"
        :src="photo.url"
        :alt="`Foto ${asset.name}`"
        loading="lazy"
      />
      <span v-else class="card__placeholder" aria-hidden="true">{{ initials }}</span>
      <span class="card__code num">{{ asset.code }}</span>
    </div>

    <div class="card__body">
      <div class="card__top">
        <StatusBadge v-if="management" :tone="lifecycleTone">{{
          asset.lifecycle_status_label
        }}</StatusBadge>
        <StatusBadge v-else-if="asset.is_borrowable" tone="ok">Bisa dipinjam</StatusBadge>
        <StatusBadge v-else tone="neutral">Tidak tersedia</StatusBadge>
      </div>

      <h3 class="card__name">{{ asset.name }}</h3>

      <p class="card__meta text-2">
        {{ asset.category?.name ?? 'Tanpa kategori' }}
        <template v-if="asset.location"> · {{ asset.location.name }}</template>
      </p>

      <p v-if="management && asset.condition_label" class="card__condition">
        Kondisi: {{ asset.condition_label }}
      </p>
    </div>
  </RouterLink>
</template>

<style scoped>
.card {
  display: grid;
  grid-template-rows: auto 1fr;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface);
  color: var(--ln-text);
  text-decoration: none;
  transition:
    border-color var(--ln-motion-base) var(--ln-ease),
    transform var(--ln-motion-base) var(--ln-ease);
}

.card:hover {
  border-color: var(--ln-border-strong);
  transform: translateY(-2px);
}

.card__thumb {
  position: relative;
  min-height: 10.5rem;
  overflow: hidden;
  background: var(--ln-surface-alt);
  border-bottom: 1px solid var(--ln-border);
  display: grid;
  place-items: center;
}

.card__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform var(--ln-motion-slow) var(--ln-ease);
}

.card:hover .card__img {
  transform: scale(1.04);
}

.card__placeholder {
  color: var(--ln-n400);
  font-family: var(--ln-font-display);
  font-size: 2.25rem;
  font-weight: 500;
  letter-spacing: -0.04em;
}

.card__code {
  position: absolute;
  top: 0.625rem;
  left: 0.625rem;
  padding: 0.125rem 0.375rem;
  border: 1px solid var(--ln-border);
  background: color-mix(in srgb, var(--ln-surface) 88%, transparent);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  color: var(--ln-text-2);
}

.card__body {
  display: grid;
  gap: 0.375rem;
  align-content: start;
  padding: 1rem;
  min-width: 0;
}

.card__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.card__name {
  margin: 0;
  font-size: 1.125rem;
  letter-spacing: -0.02em;
}

.card__meta {
  margin: 0;
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.card__condition {
  margin: 0.25rem 0 0;
  font-size: 0.8125rem;
  color: var(--ln-text-2);
}
</style>
