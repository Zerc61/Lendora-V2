# Kontrak desain — aplikasi internal Lendora

Panduan wajib untuk merombak tampilan view di `src/views/*`. Bahasa visual
diambil dari brand kit (`desain-logo/`) dan landing page (`src/views/landing/`):
editorial monokron, sudut tajam, garis rule, tipografi display, dan gerak GSAP
yang ekspresif tapi tidak menghalangi kerja.

## Prinsip

1. **Monokron dulu, warna hanya untuk status.** `--ln-text`, `--ln-bg`,
   `--ln-surface`, `--ln-surface-alt`, `--ln-border`, `--ln-border-strong`.
   Warna fungsional (`--ln-ok`, `--ln-warn`, `--ln-err`, `--ln-info`) hanya
   untuk `StatusBadge` dan pesan galat.
2. **Radius 0.** Identitas merek. Tidak ada `border-radius` baru.
3. **Tanpa bayangan dan gradient.** Pemisahan pakai garis (`1px` `--ln-border`)
   dan rule tebal (`2px` `--ln-border-strong`) untuk blok besar.
4. **Konten tetap terlihat tanpa animasi.** Animasi menambah orientasi, bukan
   menjadi syarat tampil.
5. **`prefers-reduced-motion` dihormati otomatis** oleh `lib/motion.js`.

## Token & kelas global (`src/styles/base.css`)

- Ukuran display: `--ln-display`, `--ln-display-sm`, `--ln-title`, `--ln-micro`.
- Jarak: `--ln-gutter`, `--ln-section-y`.
- Kelas siap pakai:
  - `.index-label` — label mono huruf besar, pola `<p class="index-label"><b>01</b> / JUDUL</p>`
  - `.lede` — teks pembuka judul
  - `.panel`, `.panel--alt` — permukaan bergaris
  - `.rule-top` — rule tebal 2px di atas blok
  - `.link-sweep` — tautan dengan sapuan garis saat hover (animasi 04)
  - `.marquee` + `.marquee__track` — pita berjalan, berhenti saat hover
  - `.btn`, `.btn--primary`, `.btn--ghost` — hover inversion 120ms
  - `.num` — angka monospace tabular
- Judul global `h1` sudah display-size. Jangan menimpa ukuran judul halaman
  kecuali memang perlu.

## Komponen bersama

- `components/ui/PageHead.vue` — kepala halaman:
  `<PageHead index="01" label="KATALOG" title="Judul" lede="Teks pembuka">`
  dengan slot `actions`. Gerak masuknya sudah ditangani komponen.
- `components/ui/StatGrid.vue` — `<StatGrid :items="[...]" />`, tiap item
  `{ value, label, meta, suffix?, to? }`. Angka menghitung otomatis; item
  dengan `to` menjadi tautan hover-inversion.
- `components/LoadingState.vue`, `EmptyState.vue`, `ErrorState.vue`,
  `Pagination.vue`, `StatusBadge.vue`, `AssetCard.vue` — sudah editorial.

## Gerak (`src/lib/motion.js`)

```js
import { usePageMotion } from '../lib/motion'

const page = ref(null)
const motion = usePageMotion(page)

onMounted(async () => {
  await load()
  motion.run(({ gsap }) => { /* GSAP apa pun */ })
  motion.reveal('.row', { stagger: 0.05 })   // masuk viewport, sekali
  motion.scrubProgress('.rail__fill', '.rail-host') // garis progres vs scroll
  motion.parallax('.dekorasi', { amount: 40 })
  motion.countTo(el, value)                  // angka menghitung
  motion.refresh()                           // setelah pelayoutan data berubah
})
```

Aturan:

- Selalu `usePageMotion(page)` dengan `ref` di elemen root view; semua
  instance dibersihkan otomatis saat view dilepas.
- Panggil `reveal` **setelah** data dirender, bukan sebelum.
- Reveal sekali (`once`), tidak ada animasi berulang tanpa arti.
- Scroll-scrub hanya untuk halaman panjang dengan alur yang jelas (timeline,
  rail panjang). Daftar biasa cukup `reveal`.
- Semua gerak memakai `transform` dan `opacity`, jangan menganimasikan layout.

## Susunan tipografi view

```vue
<template>
  <section class="view">
    <PageHead index="02" label="ASET" title="Judul display" lede="..." />
    <StatGrid :items="stats" />
    <div class="view__body"> ... daftar / tabel / form ... </div>
  </section>
</template>

<style scoped>
.view { display: grid; gap: 2.5rem; }
</style>
```

- Judul seksi pendukung: `h2` dengan `--ln-display-sm` bila ingin besar.
- Metadata (kode, tanggal, status) pakai `.num` atau mono huruf besar
  (`font-family: var(--ln-font-mono); font-size: var(--ln-micro);
  letter-spacing: .12em; text-transform: uppercase`).
- Daftar baris: `border-bottom: 1px solid var(--ln-border)`, tinggi minimal
  44px untuk baris yang bisa diklik.

## Aksesibilitas

- Target sentuh minimal 44×44px.
- `:focus-visible` sudah global, jangan dihapus.
- Status tidak disampaikan lewat warna saja — selalu ada teks.
- Struktur heading: satu `h1` per view, lalu `h2`, `h3`.
- Kontrol interaktif memakai `button`/`a`, bukan `div` dengan klik.
