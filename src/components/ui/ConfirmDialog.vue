<script setup>
/*
 * Dialog konfirmasi serbaguna untuk aksi berbahaya (setujui, tolak, hapus,
 * inspeksi) — pengganti window.confirm bawaan browser. ESC dan klik
 * backdrop membatalkan; textarea alasan tampil bila requireReason.
 */
import { onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
  title: { type: String, required: true },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Konfirmasi' },
  cancelLabel: { type: String, default: 'Batal' },
  requireReason: { type: Boolean, default: false },
  reasonLabel: { type: String, default: 'Alasan' },
  busy: { type: Boolean, default: false },
  error: { type: String, default: null },
})

const emit = defineEmits(['confirm', 'cancel'])

const reason = ref('')
const confirmBtn = ref(null)

function onKeydown(event) {
  if (event.key === 'Escape' && !props.busy) {
    emit('cancel')
  }
}

function onBackdrop() {
  if (!props.busy) {
    emit('cancel')
  }
}

onMounted(() => {
  confirmBtn.value?.focus()
  window.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div class="cd">
      <div class="cd__backdrop" aria-hidden="true" @click="onBackdrop"></div>

      <div class="cd__panel" role="dialog" aria-modal="true" :aria-label="title">
        <h2 class="cd__title">{{ title }}</h2>
        <p v-if="message" class="cd__message">{{ message }}</p>

        <p v-if="error" class="form-error" role="alert">{{ error }}</p>

        <div v-if="requireReason" class="field">
          <label for="cd-reason">{{ reasonLabel }}</label>
          <textarea
            id="cd-reason"
            v-model="reason"
            class="input"
            rows="3"
            maxlength="500"
            required
          ></textarea>
        </div>

        <div class="cd__actions">
          <button type="button" class="btn btn--ghost" :disabled="busy" @click="emit('cancel')">
            {{ cancelLabel }}
          </button>
          <button
            ref="confirmBtn"
            type="button"
            class="btn btn--primary"
            :disabled="busy || (requireReason && !reason.trim())"
            @click="emit('confirm', reason.trim() || null)"
          >
            {{ busy ? 'Memproses…' : confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.cd {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: grid;
  place-items: center;
  padding: 1rem;
}

.cd__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(10, 10, 16, 0.45);
}

.cd__panel {
  position: relative;
  display: grid;
  gap: 0.75rem;
  width: min(26rem, 100%);
  max-height: calc(100vh - 2rem);
  overflow: auto;
  padding: 1.25rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-bg);
  box-shadow: 0 16px 48px -24px rgba(10, 10, 16, 0.4);
}

.cd__title {
  margin: 0;
  font-size: 1.125rem;
}

.cd__message {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.875rem;
  line-height: 1.6;
}

.cd__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 0.5rem;
}

@media (max-width: 30rem) {
  .cd__actions {
    flex-direction: column-reverse;
  }

  .cd__actions .btn {
    width: 100%;
  }
}
</style>
