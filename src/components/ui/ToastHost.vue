<script setup>
/*
 * Host toast: panel melayang di kanan-bawah, menumpuk bila banyak.
 * Dirender sekali di AppShell; aria-live agar pembaca layar mengumumkan.
 */
import { dismissToast, toasts } from '../../lib/toast'
</script>

<template>
  <Teleport to="body">
    <div class="toasts" role="status" aria-live="polite">
      <div v-for="item in toasts" :key="item.id" class="toast" :class="`toast--${item.tone}`">
        <span class="toast__msg">{{ item.message }}</span>
        <button
          type="button"
          class="toast__close"
          aria-label="Tutup pesan"
          @click="dismissToast(item.id)"
        >
          ×
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.toasts {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 70;
  display: grid;
  gap: 0.5rem;
  width: min(22rem, calc(100vw - 2rem));
}

.toast {
  display: flex;
  align-items: start;
  gap: 0.75rem;
  padding: 0.75rem 0.875rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-surface);
  box-shadow: 0 16px 48px -24px rgba(10, 10, 16, 0.4);
  font-size: 0.875rem;
}

.toast--ok {
  border-color: var(--ln-ok);
  color: var(--ln-ok);
  background: var(--ln-ok-bg);
}

.toast--err {
  border-color: var(--ln-err);
  color: var(--ln-err);
  background: var(--ln-err-bg);
}

.toast__msg {
  flex: 1;
}

.toast__close {
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font-size: 1.125rem;
  line-height: 1;
  cursor: pointer;
}
</style>
