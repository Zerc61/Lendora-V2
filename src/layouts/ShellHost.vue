<script setup>
/*
 * Pemilih shell per role pada rute induk /app: borrower memakai AppShell
 * (navbar atas), admin dan operasional memakai AdminShell (sidebar).
 * Masing-masing shell merender <RouterView/> sendiri.
 */
import { computed } from 'vue'
import { auth } from '../lib/auth'
import AppShell from './AppShell.vue'
import AdminShell from './AdminShell.vue'

const shell = computed(() => (auth.role === 'borrower' ? AppShell : AdminShell))
</script>

<template>
  <component :is="shell" />
</template>
