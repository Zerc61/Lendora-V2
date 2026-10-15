<script setup>
defineProps({
  title: {
    type: String,
    default: 'Gagal memuat data',
  },
  message: {
    type: String,
    default: null,
  },
})

defineEmits(['retry'])
</script>

<template>
  <div class="error" role="alert">
    <p class="error__index">GALAT</p>
    <h2 class="error__title">{{ title }}</h2>
    <p v-if="message" class="error__message">{{ message }}</p>
    <button type="button" class="btn" @click="$emit('retry')">Coba lagi</button>
  </div>
</template>

<style scoped>
.error {
  display: grid;
  gap: 0.5rem;
  justify-items: start;
  max-width: 44rem;
  padding: 1.5rem;
  border: 1px solid var(--ln-err);
  background: var(--ln-err-bg);
}

.error__index {
  margin: 0;
  color: var(--ln-err);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
}

.error__title {
  margin: 0;
  color: var(--ln-err);
}

.error__message {
  margin: 0 0 0.5rem;
  max-width: 48ch;
  color: var(--ln-text-2);
}
</style>
