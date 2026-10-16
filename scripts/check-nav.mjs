#!/usr/bin/env node
/*
 * Cek navigasi: setiap entri menu harus menunjuk nama rute yang benar-benar
 * terdaftar, sehingga tidak ada tautan ke halaman yang belum ada (R-24).
 *
 * Tanpa dependensi. Jalankan dengan: node scripts/check-nav.mjs
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')

const navigationSource = readFileSync(resolve(root, 'src/lib/navigation.js'), 'utf8')
const routerSource = readFileSync(resolve(root, 'src/router/index.js'), 'utf8')

const routeNames = new Set(
  [...routerSource.matchAll(/name:\s*'([^']+)'/g)].map((match) => match[1]),
)

const navItems = [
  ...navigationSource.matchAll(/\{\s*name:\s*'([^']+)',\s*label:\s*'([^']+)'/g),
].map((match) => ({ name: match[1], label: match[2] }))

const roles = [...navigationSource.matchAll(/^ {2}([a-zA-Z]+):\s*\[/gm)].map((match) => match[1])

if (routeNames.size === 0) {
  console.error('Tidak ada nama rute yang terbaca dari src/router/index.js.')
  process.exit(1)
}

if (navItems.length === 0) {
  console.error('Tidak ada entri menu yang terbaca dari src/lib/navigation.js.')
  process.exit(1)
}

let failures = 0

for (const item of navItems) {
  const hasRoute = routeNames.has(item.name)

  if (!hasRoute) {
    failures += 1
  }

  console.log(`${hasRoute ? 'ok  ' : 'FAIL'}  ${item.name.padEnd(20)} ${item.label}`)
}

console.log(
  `\n${navItems.length} entri menu, ${routeNames.size} nama rute, ${failures} tanpa rute terdaftar.`,
)
console.log(`Peran: ${roles.join(', ') || '(tidak terbaca)'}`)

if (failures > 0) {
  console.error('\nAda entri menu tanpa rute terdaftar.')
  process.exit(1)
}

console.log('\nSemua entri menu punya rute terdaftar.')
