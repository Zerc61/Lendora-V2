/*
 * Toast singkat untuk umpan balik aksi (berhasil/gagal). Satu daftar
 * reactive; host dirender sekali di AppShell. Pesan hilang otomatis.
 */
import { reactive } from 'vue'

let seq = 0

export const toasts = reactive([])

export function dismissToast(id) {
  const index = toasts.findIndex((toast) => toast.id === id)
  if (index !== -1) {
    toasts.splice(index, 1)
  }
}

export function toast(message, tone = 'ok') {
  const id = ++seq
  toasts.push({ id, message, tone })
  window.setTimeout(() => dismissToast(id), 4500)
  return id
}

toast.ok = (message) => toast(message, 'ok')
toast.err = (message) => toast(message, 'err')
