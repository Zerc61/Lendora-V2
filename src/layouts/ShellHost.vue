<script setup>
/*
 * Pemilih shell per role pada rute induk /app: admin dan operasional memakai
 * AdminShell (sidebar), sisanya AppShell (navbar atas) — termasuk pelanggan,
 * yaitu akun tanpa membership aktif yang memakai halaman publik dan booking
 * sendiri.
 *
 * Masing-masing shell merender <RouterView/> sendiri.
 */
import { computed } from 'vue'
import { auth } from '../lib/auth'
import AppShell from './AppShell.vue'
import AdminShell from './AdminShell.vue'

const shell = computed(() =>
  auth.role === 'admin' || auth.role === 'operator' ? AdminShell : AppShell,
)
</script>

<template>
  <component :is="shell" />
</template>
