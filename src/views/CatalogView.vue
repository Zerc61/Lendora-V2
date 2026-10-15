<script setup>
/*
 * Cari aset. Satu halaman untuk semua role: kata kunci (full text search
 * backend) plus filter kategori dan lokasi. Data asli dari GET /assets.
 *
 * Presentasi mengikuti bahasa editorial: kepala halaman PageHead, panel
 * saring satu baris dengan tombol aksi di ujung, lalu kartu hasil yang masuk
 * lewat reveal berulang (lib/motion.js). Query param dan perilaku pagination
 * tidak berubah.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { apiGet, ApiError } from '../lib/http'
import { usePageMotion } from '../lib/motion'
import PageHead from '../components/ui/PageHead.vue'
import AssetCard from '../components/AssetCard.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'

const q = ref('')
const categoryId = ref('')
const locationId = ref('')

const categories = ref([])
const locations = ref([])
const assets = ref([])
const meta = ref(null)
const loading = ref(false)
const loadingFilters = ref(true)
const error = ref(null)
const filtersError = ref(null)

const page = ref(null)
const motion = usePageMotion(page)
const countEl = ref(null)

const numberFmt = new Intl.NumberFormat('id-ID')
const resultCount = computed(() => numberFmt.format(meta.value?.total ?? 0))

async function fetchFilters() {
  loadingFilters.value = true
  filtersError.value = null
  try {
    const [categoryPayload, locationPayload] = await Promise.all([
      apiGet('/categories'),
      apiGet('/locations'),
    ])
    categories.value = categoryPayload.data ?? []
    locations.value = locationPayload.data ?? []
  } catch (e) {
    filtersError.value = e instanceof ApiError ? e.message : 'Gagal memuat pilihan filter.'
  } finally {
    loadingFilters.value = false
  }
}

async function loadAssets(page = 1) {
  loading.value = true
  error.value = null

  const params = new URLSearchParams()
  if (q.value.trim()) {
    params.set('q', q.value.trim())
  }
  if (categoryId.value) {
    params.set('category_id', categoryId.value)
  }
  if (locationId.value) {
    params.set('location_id', locationId.value)
  }
  params.set('page', String(page))

  try {
    const payload = await apiGet(`/assets?${params.toString()}`)
    assets.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar aset.'
  } finally {
    loading.value = false
  }
}

// Setelah hasil diganti, posisi trigger reveal dihitung ulang. Gerak saja,
// tidak ada perubahan pada pemanggilan API maupun state filter.
function refreshMotion() {
  motion.refresh()
}

function applySearch() {
  loadAssets(1).then(refreshMotion)
}

function resetFilters() {
  q.value = ''
  categoryId.value = ''
  locationId.value = ''
  loadAssets(1).then(refreshMotion)
}

function onPage(nextPage) {
  loadAssets(nextPage).then(refreshMotion)
}

onMounted(async () => {
  fetchFilters()
  await loadAssets()
  await nextTick()
  motion.reveal('.catalog__results', { y: 14, duration: 0.45 })
  motion.reveal('.grid > *', { stagger: 0.05 })
  if (countEl.value && meta.value) {
    motion.countTo(countEl.value, meta.value.total)
  }
  motion.refresh()
})
</script>

<template>
  <section ref="page" class="catalog">
    <PageHead
      label="KATALOG"
      title="Cari aset"
      lede="Temukan aset yang bisa dipinjam di organisasi aktif. Detail aset menampilkan ketersediaan untuk periode yang kamu pilih."
    />

    <form class="filters panel panel--alt" role="search" @submit.prevent="applySearch">
      <p class="index-label">SARING</p>

      <div class="filters__row">
        <div class="field filters__q">
          <label for="cat-q" class="visually-hidden">Kata kunci</label>
          <input
            id="cat-q"
            v-model="q"
            class="input"
            type="search"
            name="q"
            placeholder="Cari nama, kode, atau deskripsi"
          />
        </div>

        <div class="field">
          <label for="cat-category" class="visually-hidden">Filter kategori</label>
          <select id="cat-category" v-model="categoryId" class="input" :disabled="loadingFilters">
            <option value="">Semua kategori</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
        </div>

        <div class="field">
          <label for="cat-location" class="visually-hidden">Filter lokasi</label>
          <select id="cat-location" v-model="locationId" class="input" :disabled="loadingFilters">
            <option value="">Semua lokasi</option>
            <option v-for="location in locations" :key="location.id" :value="location.id">
              {{ location.name }}
            </option>
          </select>
        </div>

        <div class="filters__actions">
          <button type="submit" class="btn btn--primary">Cari</button>
          <button type="button" class="btn" @click="resetFilters">Reset</button>
        </div>
      </div>
    </form>

    <p v-if="filtersError" class="form-error" role="alert">{{ filtersError }}</p>

    <div v-if="meta && meta.total > 0 && !loading && !error" class="catalog__results rule-top">
      <p class="index-label">HASIL</p>
      <div class="results__row">
        <h2 class="results__title">Hasil pencarian.</h2>
        <p class="results__count">
          <span ref="countEl" class="results__num">{{ resultCount }}</span>
          <span class="results__unit">aset</span>
        </p>
      </div>
    </div>

    <LoadingState v-if="loading" label="Mencari aset" />

    <ErrorState v-else-if="error" :message="error" @retry="applySearch" />

    <template v-else-if="assets.length">
      <div class="grid">
        <AssetCard v-for="asset in assets" :key="asset.id" :asset="asset" />
      </div>
      <Pagination
        v-if="meta"
        :current="meta.current_page"
        :last="meta.last_page"
        :total="meta.total"
        :from="meta.from"
        :to="meta.to"
        @page="onPage"
      />
    </template>

    <EmptyState
      v-else
      title="Tidak ada aset yang cocok"
      hint="Ubah kata kunci atau filter, atau coba lagi nanti."
    />
  </section>
</template>

<style scoped>
.catalog {
  display: grid;
  gap: 2rem;
}

/* Panel saring: satu baris, permukaan sekunder, aksi di ujung kanan. */
.filters {
  padding: 1.25rem;
}

.filters__row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: 0.75rem;
  align-items: end;
}

.filters .field {
  margin: 0;
}

.filters__actions {
  display: flex;
  gap: 0.5rem;
}

/* Kepala hasil: judul display berdampingan dengan angka total. */
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
  font-variant-numeric: tabular-nums;
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

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(21rem, 1fr));
  gap: 1.25rem;
}

@media (max-width: 52rem) {
  .filters__row {
    grid-template-columns: 1fr;
  }

  .filters__actions {
    justify-content: flex-start;
  }
}
</style>
