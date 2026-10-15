<script setup>
/*
 * Manajemen aset (operator dan admin). Daftar dengan filter status dan
 * kondisi, tombol tambah aset (admin), serta panel data induk kategori dan
 * lokasi (admin). Data asli dari GET /assets.
 *
 * Presentasi editorial: PageHead, panel saring satu baris, kepala hasil
 * dengan angka total menghitung, dan reveal berulang untuk kartu serta blok
 * data induk. Filter, permission, dan pagination tidak berubah.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { readQuery, writeQuery } from '../lib/filterQuery'
import { auth } from '../lib/auth'
import { usePageMotion } from '../lib/motion'
import { LIFECYCLE_OPTIONS, CONDITION_OPTIONS } from '../lib/format'
import PageHead from '../components/ui/PageHead.vue'
import AssetCard from '../components/AssetCard.vue'
import AssetForm from '../components/AssetForm.vue'
import CategoryManager from '../components/CategoryManager.vue'
import LocationManager from '../components/LocationManager.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'

const isAdmin = computed(() => auth.role === 'admin')

const q = ref('')
const categoryId = ref('')
const locationId = ref('')
const status = ref('')
const condition = ref('')
const sort = ref('') // '' = nama A–Z; newest = terbaru; condition = kondisi

const route = useRoute()
const router = useRouter()

const categories = ref([])
const locations = ref([])
const assets = ref([])
const meta = ref(null)
const loading = ref(false)
const loadingFilters = ref(true)
const error = ref(null)
const filtersError = ref(null)

const showForm = ref(false)

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
  if (status.value) {
    params.set('status', status.value)
  }
  if (condition.value) {
    params.set('condition', condition.value)
  }
  if (sort.value === 'newest') {
    params.set('sort', 'created_at')
    params.set('direction', 'desc')
  } else if (sort.value === 'condition') {
    params.set('sort', 'condition')
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

// Hitung ulang posisi reveal setelah hasil diganti. Gerak saja.
function refreshMotion() {
  motion.refresh()
}

function syncQuery() {
  writeQuery(router, route, {
    q: q.value.trim(),
    category_id: categoryId.value,
    location_id: locationId.value,
    status: status.value,
    condition: condition.value,
    sort: sort.value,
  })
}

function applyFilters() {
  showForm.value = false
  syncQuery()
  loadAssets(1).then(refreshMotion)
}

function resetFilters() {
  q.value = ''
  categoryId.value = ''
  locationId.value = ''
  status.value = ''
  condition.value = ''
  sort.value = ''
  applyFilters()
}

function onPage(nextPage) {
  loadAssets(nextPage).then(refreshMotion)
}

async function onSaved() {
  showForm.value = false
  toast.ok('Aset disimpan.')
  await loadAssets(1)
  refreshMotion()
}

onMounted(async () => {
  const initial = readQuery(route, [
    'q',
    'category_id',
    'location_id',
    'status',
    'condition',
    'sort',
  ])
  q.value = initial.q ?? ''
  categoryId.value = initial.category_id ?? ''
  locationId.value = initial.location_id ?? ''
  status.value = initial.status ?? ''
  condition.value = initial.condition ?? ''
  sort.value = initial.sort ?? ''

  fetchFilters()
  await loadAssets()
  await nextTick()
  motion.reveal('.assets__results', { y: 14, duration: 0.45 })
  motion.reveal('.grid > *', { stagger: 0.05 })
  if (countEl.value && meta.value) {
    motion.countTo(countEl.value, meta.value.total)
  }
  motion.reveal('.masters__grid > *', { stagger: 0.08 })
  motion.refresh()
})
</script>

<template>
  <section ref="page" class="assets">
    <PageHead
      label="ASET"
      title="Aset"
      lede="Daftar dan kondisi aset organisasi aktif. Pilih kartu untuk detail, perbaikan, dan riwayat."
    />

    <div v-if="isAdmin" class="toolbar">
      <p class="toolbar__note">
        Inventaris dikelola di sini: tambah aset, perbarui kondisi, dan rapikan data induk.
      </p>
      <button type="button" class="btn btn--primary" @click="showForm = !showForm">
        {{ showForm ? 'Tutup form' : 'Tambah aset' }}
      </button>
    </div>

    <AssetForm
      v-if="showForm"
      :categories="categories"
      :locations="locations"
      @saved="onSaved"
      @cancel="showForm = false"
    />

    <form class="filters panel panel--alt" @submit.prevent="applyFilters">
      <p class="index-label">SARING</p>

      <div class="filters__row">
        <div class="field filters__q">
          <label for="as-q" class="visually-hidden">Kata kunci</label>
          <input
            id="as-q"
            v-model="q"
            class="input"
            type="search"
            name="q"
            placeholder="Cari nama, kode, atau deskripsi"
          />
        </div>

        <div class="field">
          <label for="as-category" class="visually-hidden">Filter kategori</label>
          <select id="as-category" v-model="categoryId" class="input" :disabled="loadingFilters">
            <option value="">Semua kategori</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
        </div>

        <div class="field">
          <label for="as-location" class="visually-hidden">Filter lokasi</label>
          <select id="as-location" v-model="locationId" class="input" :disabled="loadingFilters">
            <option value="">Semua lokasi</option>
            <option v-for="location in locations" :key="location.id" :value="location.id">
              {{ location.name }}
            </option>
          </select>
        </div>

        <div class="field">
          <label for="as-status" class="visually-hidden">Filter status</label>
          <select id="as-status" v-model="status" class="input">
            <option value="">Semua status</option>
            <option v-for="option in LIFECYCLE_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div class="field">
          <label for="as-condition" class="visually-hidden">Filter kondisi</label>
          <select id="as-condition" v-model="condition" class="input">
            <option value="">Semua kondisi</option>
            <option v-for="option in CONDITION_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div class="field">
          <label for="as-sort" class="visually-hidden">Urutkan</label>
          <select id="as-sort" v-model="sort" class="input" @change="applyFilters">
            <option value="">Urut nama (A–Z)</option>
            <option value="newest">Terbaru</option>
            <option value="condition">Kondisi</option>
          </select>
        </div>

        <div class="filters__actions">
          <button type="submit" class="btn btn--primary">Terapkan</button>
          <button type="button" class="btn" @click="resetFilters">Reset</button>
        </div>
      </div>
    </form>

    <p v-if="filtersError" class="form-error" role="alert">{{ filtersError }}</p>

    <div v-if="meta && meta.total > 0 && !loading && !error" class="assets__results rule-top">
      <p class="index-label">HASIL</p>
      <div class="results__row">
        <h2 class="results__title">Hasil penelusuran.</h2>
        <p class="results__count">
          <span ref="countEl" class="results__num">{{ resultCount }}</span>
          <span class="results__unit">aset</span>
        </p>
      </div>
    </div>

    <LoadingState v-if="loading" label="Memuat daftar aset" />

    <ErrorState v-else-if="error" :message="error" @retry="applyFilters" />

    <template v-else-if="assets.length">
      <div class="grid">
        <AssetCard v-for="asset in assets" :key="asset.id" :asset="asset" management />
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
      title="Belum ada aset"
      hint="Cocokkan kembali filter, atau tambahkan aset pertama lewat tombol di atas (admin)."
    />

    <div v-if="isAdmin" class="masters">
      <p class="index-label">DATA INDUK</p>
      <div class="masters__grid">
        <CategoryManager />
        <LocationManager />
      </div>
    </div>
  </section>
</template>

<style scoped>
.assets {
  display: grid;
  gap: 2rem;
}

/* Baris aksi admin: catatan mono di kiri, tombol di ujung kanan. */
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--ln-border);
}

.toolbar__note {
  max-width: 56ch;
  margin: 0;
  font-family: var(--ln-font-mono);
  font-size: 0.75rem;
  letter-spacing: 0.06em;
  color: var(--ln-text-2);
}

/* Panel saring satu baris dengan aksi di ujung. */
.filters {
  padding: 1.25rem;
}

.filters__row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) repeat(4, minmax(0, 1fr)) auto;
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

/* Kepala hasil: judul display + angka total menghitung. */
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

/* Data induk: dua kolom di layar lebar, tetap satu kolom di bawah. */
.masters__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(28rem, 1fr));
  gap: 0 2.5rem;
}

@media (max-width: 60rem) {
  .filters__row {
    grid-template-columns: 1fr 1fr;
  }

  .filters__actions {
    grid-column: 1 / -1;
  }
}

@media (max-width: 36rem) {
  .filters__row {
    grid-template-columns: 1fr;
  }

  .toolbar {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
