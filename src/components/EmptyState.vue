<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  hint: {
    type: String,
    default: null,
  },
})
</script>

<template>
  <div class="empty">
    <div class="empty__body">
      <h2 class="empty__title">{{ title }}</h2>
      <p v-if="hint" class="empty__hint text-2">{{ hint }}</p>
      <div v-if="$slots.action" class="empty__action">
        <slot name="action"></slot>
      </div>
    </div>
  </div>
</template>

<style scoped>
.empty {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  max-width: 44rem;
  padding: 2.5rem 0;
  border-top: 1px solid var(--ln-border);
}

.empty__body {
  display: grid;
  gap: 0.375rem;
  align-content: start;
}

.empty__title {
  margin: 0;
}

.empty__hint {
  margin: 0;
  max-width: 42ch;
}

.empty__action {
  margin-top: 1rem;
}
</style>
