/*
 * Tema terang/gelap. Kedua tema bekerja penuh (R-34); default ikut preferensi
 * sistem, pilihan disimpan di localStorage.
 */

import { ref } from 'vue'

const STORAGE_KEY = 'ln-theme'

export const theme = ref('light')

export function initTheme() {
  const saved = localStorage.getItem(STORAGE_KEY)
  const preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  apply(saved === 'dark' || saved === 'light' ? saved : preferred, false)
}

export function setTheme(value) {
  apply(value, true)
}

export function toggleTheme() {
  apply(theme.value === 'dark' ? 'light' : 'dark', true)
}

function apply(value, persist) {
  theme.value = value
  document.documentElement.dataset.theme = value
  if (persist) {
    localStorage.setItem(STORAGE_KEY, value)
  }
}
