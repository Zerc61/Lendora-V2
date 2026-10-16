<script setup>
/*
 * Jelajah penyedia (publik, tanpa masuk). Daftar penyedia aktif yang sudah
 * diverifikasi admin platform, dari GET /public/providers.
 *
 * Bentuknya sengaja baris berindeks dengan garis hairline, bukan grid kartu:
 * bahasa editorial Lendora memakai indeks bernomor untuk daftar, dan halaman
 * ini adalah indeks penyedia.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { apiGet, ApiError } from '../lib/http'
import { usePageMotion } from '../lib/motion'
import PublicSiteHeader from '../components/PublicSiteHeader.vue'
import PageHead from '../components/ui/PageHead.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'

const q = ref('')
const providers = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const numberFmt = new Intl.NumberFormat('id-ID')
const resultCount = computed(() => numberFmt.format(meta.value?.total ?? 0))

/* Nomor baris mengikuti posisi di seluruh hasil, bukan posisi di halaman. */
function rowIndex(index) {
  const from = meta.value?.from ?? 1
  return String(from + index).padStart(2, '0')
}

async function loadProviders(pageNumber = 1) {
  loading.value = true
  error.value = null

  const params = new URLSearchParams()
  if (q.value.trim()) {
    params.set('q', q.value.trim())
  }
  params.set('page', String(pageNumber))

  try {
    const payload = await apiGet(`/public/providers?${params.toString()}`)
    providers.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value =
      e instanceof ApiError && e.status === 429
        ? 'Terlalu banyak permintaan dari jaringan ini. Tunggu sebentar lalu coba lagi.'
        : e instanceof ApiError
          ? e.message
          : 'Gagal memuat daftar penyedia.'
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  await loadProviders(1)
  await revealRows()
}

async function goToPage(pageNumber) {
  await loadProviders(pageNumber)
  await revealRows()
}

async function revealRows() {
  await nextTick()
  motion.reveal('.providers__item', { y: 12, stagger: 0.04 })
  motion.refresh()
}

function clearSearch() {
  q.value = ''
  applyFilters()
}

onMounted(applyFilters)
</script>

<template>
  <div ref="page" class="explore">
    <PublicSiteHeader />

    <main class="container explore__body">
      <PageHead
        label="JELAJAH"
        title="Penyedia kamera"
        lede="Daftar penyedia yang sudah diverifikasi. Buka salah satu untuk melihat peralatan yang tersedia dan memeriksa ketersediaannya untuk periode yang kamu rencanakan."
      />

      <form class="filters panel panel--alt" role="search" @submit.prevent="applyFilters">
        <p class="index-label">CARI</p>
        <div class="filters__row">
          <div class="field filters__q">
            <label for="explore-q" class="visually-hidden">Nama penyedia</label>
            <input
              id="explore-q"
              v-model="q"
              class="input"
              type="search"
              name="q"
              placeholder="Nama penyedia atau studio"
            />
          </div>
          <div class="filters__actions">
            <button type="submit" class="btn btn--primary">Cari penyedia</button>
            <button v-if="q" type="button" class="btn" @click="clearSearch">Bersihkan</button>
          </div>
        </div>
      </form>

      <div v-if="meta && meta.total > 0 && !loading && !error" class="results rule-top">
        <p class="index-label">HASIL</p>
        <div class="results__row">
          <h2 class="results__title">Penyedia yang cocok.</h2>
          <p class="results__count">
            <span class="results__num num">{{ resultCount }}</span>
            <span class="results__unit">penyedia</span>
          </p>
        </div>
      </div>

      <LoadingState v-if="loading" label="Memuat daftar penyedia" />

      <ErrorState
        v-else-if="error"
        title="Daftar penyedia gagal dimuat"
        :message="error"
        @retry="applyFilters"
      />

      <template v-else-if="providers.length">
        <ul class="providers__list">
          <li v-for="(provider, index) in providers" :key="provider.id" class="providers__item">
            <span class="providers__index num" aria-hidden="true">{{ rowIndex(index) }}</span>

            <div class="providers__main">
              <h3 class="providers__name">{{ provider.name }}</h3>
              <p v-if="provider.city" class="providers__city index-label">{{ provider.city }}</p>
              <p v-if="provider.description" class="providers__desc">{{ provider.description }}</p>
            </div>

            <div class="providers__contact">
              <p v-if="provider.contact_phone" class="providers__phone num">
                {{ provider.contact_phone }}
              </p>
              <RouterLink
                :to="{ name: 'explore-provider', params: { slug: provider.slug } }"
                class="btn"
              >
                Lihat peralatan
              </RouterLink>
            </div>
          </li>
        </ul>

        <Pagination
          v-if="meta"
          :current="meta.current_page"
          :last="meta.last_page"
          :total="meta.total"
          :from="meta.from"
          :to="meta.to"
          @page="goToPage"
        />
      </template>

      <EmptyState
        v-else
        title="Tidak ada penyedia yang cocok"
        hint="Ubah kata kunci pencarian. Penyedia hanya muncul setelah diverifikasi admin platform."
      />
    </main>
  </div>
</template>

<style scoped>
.explore {
  min-height: 100vh;
  background: var(--ln-bg);
}

.explore__body {
  display: grid;
  gap: 2rem;
  padding-block: var(--ln-section-y);
}

.filters {
  padding: 1.25rem;
}

.filters__row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) auto;
  gap: 0.75rem;
  align-items: end;
}

.filters .field {
  margin: 0;
}

.filters__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.results__row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
}

.results__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

.results__count {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin: 0;
}

.results__num {
  font-family: var(--ln-font-display);
  font-size: clamp(1.5rem, 2.4vw, 2.25rem);
  font-weight: 500;
  letter-spacing: -0.04em;
  line-height: 1;
}

.results__unit {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.providers__list {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.providers__item {
  display: grid;
  grid-template-columns: 2.5rem minmax(0, 1fr) auto;
  gap: 1.25rem;
  align-items: start;
  padding-block: 1.5rem;
  border-bottom: 1px solid var(--ln-border);
}

.providers__index {
  padding-top: 0.125rem;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  letter-spacing: 0.08em;
}

.providers__main {
  display: grid;
  gap: 0.375rem;
  min-width: 0;
}

.providers__name {
  margin: 0;
  font-size: var(--ln-title);
  letter-spacing: -0.02em;
}

.providers__city {
  margin: 0;
}

.providers__desc {
  max-width: 62ch;
  margin: 0;
  color: var(--ln-text-2);
  line-height: 1.65;
}

.providers__contact {
  display: grid;
  gap: 0.75rem;
  justify-items: end;
}

.providers__phone {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.875rem;
}

/* Satu kolom di bawah 60rem: nomor indeks mengecil, aksi turun ke kiri. */
@media (max-width: 60rem) {
  .providers__item {
    grid-template-columns: 2rem minmax(0, 1fr);
  }

  .providers__contact {
    grid-column: 2;
    justify-items: start;
    margin-top: 0.5rem;
  }
}

@media (max-width: 40rem) {
  .filters__row {
    grid-template-columns: 1fr;
  }

  .providers__item {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.5rem;
  }

  .providers__index,
  .providers__contact {
    grid-column: 1;
  }
}
</style>
