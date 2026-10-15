<script setup>
/*
 * Navigasi halaman untuk koleksi ber-paginasi (Laravel paginator). Memakai
 * tombol, bukan tautan, karena berpindah halaman tidak membuka URL baru
 * (daftar difilter lewat state).
 */
defineProps({
  current: { type: Number, required: true },
  last: { type: Number, required: true },
  total: { type: Number, required: true },
  from: { type: Number, default: null },
  to: { type: Number, default: null },
})

defineEmits(['page'])
</script>

<template>
  <nav v-if="last > 1" class="pager" aria-label="Halaman hasil">
    <button type="button" class="btn" :disabled="current <= 1" @click="$emit('page', current - 1)">
      Sebelumnya
    </button>

    <p class="pager__info num">
      {{ String(current).padStart(2, '0') }} / {{ String(last).padStart(2, '0') }}
      <template v-if="total > 0">
        · menampilkan {{ from }} sampai {{ to }} dari {{ total }}
      </template>
    </p>

    <button
      type="button"
      class="btn"
      :disabled="current >= last"
      @click="$emit('page', current + 1)"
    >
      Berikutnya
    </button>
  </nav>
  <p v-else-if="total > 0" class="pager__info pager__info--single num">
    {{ total }} hasil, menampilkan {{ from }} sampai {{ to }}
  </p>
</template>

<style scoped>
.pager {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--ln-border);
  flex-wrap: wrap;
}

.pager__info {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.pager__info--single {
  margin-top: 1.25rem;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
}
</style>
