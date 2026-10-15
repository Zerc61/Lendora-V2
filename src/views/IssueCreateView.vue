<script setup>
/*
 * Form pelaporan masalah untuk semua role. Aset awal bisa diisi dari query
 * `asset` (dikirim tombol Laporkan masalah di detail aset). Severity kritis
 * wajib disertai minimal satu foto (aturan backend M-4).
 * Field, validasi, payload, dan alur submit tidak berubah; yang baru hanya
 * susunan langkah bernomor, garis rule, panel ringkasan, dan gerak.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const route = useRoute()
const router = useRouter()

const asset = ref(null)
const loading = ref(true)
const loadError = ref(null)

const type = ref('damage')
const severity = ref('medium')
const description = ref('')
const loanId = ref('')
const photos = ref([])

const searchQuery = ref('')
const searchResults = ref([])
const searching = ref(false)
const searchError = ref(null)
const searchDone = ref(false)

const sending = ref(false)
const submitError = ref(null)
const fieldErrors = ref({})

const page = ref(null)
const motion = usePageMotion(page)

// --- Client-side validation ---
function validateIssueForm() {
  const errors = {}

  // Check asset
  if (!asset.value) {
    errors.asset_id = ['Pilih aset.']
  }

  // Check description
  if (!description.value || !description.value.trim()) {
    errors.description = ['Deskripsi harus diisi.']
  } else if (description.value.trim().length < 5) {
    errors.description = ['Deskripsi terlalu pendek (minimal 5 karakter).']
  }

  // Check photos if severity is critical
  if (severity.value === 'critical' && photos.value.length === 0) {
    errors.photos = ['Untuk severity kritis, wajib menambahkan minimal satu foto.']
  }

  return errors
}

// --- Load issue request data from sessionStorage (set by the calling view) ---
const loadIssueRequestData = () => {
  try {
    const data = sessionStorage.getItem('issue-request-data')
    if (data) {
      const parsed = JSON.parse(data)
      // Clear the data after reading
      sessionStorage.removeItem('issue-request-data')
      return parsed
    }
  } catch (e) {
    console.warn('Failed to parse issue request data from sessionStorage', e)
  }
  return null
}

const issueRequestData = loadIssueRequestData()

const TYPES = [
  { value: 'damage', label: 'Kerusakan' },
  { value: 'loss', label: 'Kehilangan' },
  { value: 'other', label: 'Lainnya' },
]

const SEVERITIES = [
  { value: 'low', label: 'Rendah' },
  { value: 'medium', label: 'Sedang' },
  { value: 'high', label: 'Tinggi' },
  { value: 'critical', label: 'Kritis' },
]

const canSubmit = computed(() => asset.value && description.value.trim() && !sending.value)

/* Ringkasan samping: turunan dari state yang sudah ada, tanpa logika baru. */
const summary = computed(() => [
  { label: 'Aset', value: asset.value ? asset.value.name : 'Belum dipilih' },
  { label: 'Kode aset', value: asset.value ? asset.value.code : '-' },
  { label: 'Tipe', value: labelOf(TYPES, type.value) },
  { label: 'Severity', value: labelOf(SEVERITIES, severity.value) },
  {
    label: 'Deskripsi',
    value: description.value.trim() ? `${description.value.trim().length} karakter` : 'Belum diisi',
  },
  {
    label: 'Foto',
    value: photos.value.length
      ? `${photos.value.length} berkas`
      : severity.value === 'critical'
        ? 'Wajib minimal 1'
        : 'Belum ada',
  },
  {
    label: 'Peminjaman terkait',
    value: loanId.value.trim() ? `#${loanId.value.trim()}` : 'Tidak dilampirkan',
  },
])

function labelOf(options, value) {
  return options.find((opt) => opt.value === value)?.label ?? '-'
}

async function loadInitial() {
  loading.value = true
  loadError.value = null
  try {
    // If we have an asset ID from the request data, fetch the asset
    if (issueRequestData?.asset) {
      const assetId = Number(issueRequestData.asset)
      if (Number.isInteger(assetId) && assetId > 0) {
        asset.value = (await apiGet(`/assets/${assetId}`)).data
      }
    }
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Gagal memuat aset pilihan.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadInitial()
  motion.reveal('.step, .summary', { stagger: 0.1, y: 16 })
  motion.refresh()
})

async function searchAssets() {
  const query = searchQuery.value.trim()
  if (!query) {
    return
  }
  searching.value = true
  searchError.value = null
  searchDone.value = true
  try {
    const payload = await apiGet(`/assets?q=${encodeURIComponent(query)}`)
    searchResults.value = payload.data ?? []
  } catch (e) {
    searchError.value = e instanceof ApiError ? e.message : 'Gagal mencari aset.'
  } finally {
    searching.value = false
  }
}

function addPhotos(event) {
  photos.value.push(...Array.from(event.target.files ?? []))
}

function removePhoto(index) {
  photos.value.splice(index, 1)
}

async function submit() {
  sending.value = true
  submitError.value = null
  fieldErrors.value = {}
  // Client-side validation
  const validationErrors = validateIssueForm()
  if (Object.keys(validationErrors).length > 0) {
    fieldErrors.value = validationErrors
    sending.value = false
    return
  }
  const fd = new FormData()
  fd.append('asset_id', String(asset.value.id))
  fd.append('type', type.value)
  fd.append('severity', severity.value)
  fd.append('description', description.value.trim())
  if (loanId.value.trim()) {
    fd.append('loan_id', loanId.value.trim())
  }
  photos.value.forEach((file) => {
    fd.append('photos[]', file)
  })

  try {
    const payload = await apiPost('/issues', fd)
    router.replace({ name: 'issue-detail', params: { id: payload.data.id } })
  } catch (e) {
    if (e instanceof ApiError) {
      submitError.value = e.message
      fieldErrors.value = e.errors ?? {}
    } else {
      submitError.value = 'Gagal mengirim laporan.'
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section ref="page" class="issue-create">
    <PageHead
      label="LAPORAN MASALAH"
      title="Laporkan masalah"
      lede="Laporkan kerusakan, kehilangan, atau masalah lain pada aset. Severity kritis wajib disertai minimal satu foto."
    />

    <LoadingState v-if="loading" label="Memuat aset pilihan" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="loadInitial" />

    <div v-else class="create__grid">
      <div class="create__main">
        <p v-if="submitError" class="form-error" role="alert">{{ submitError }}</p>

        <section class="step" aria-labelledby="asset-title">
          <header class="step__head">
            <div class="step__titles">
              <p class="index-label">PILIH ASET</p>
              <h2 id="asset-title" class="step__title">Aset yang bermasalah</h2>
            </div>
          </header>

          <p v-if="asset" class="pick">
            <span class="pick__main">
              <strong>{{ asset.name }}</strong>
              <span class="text-2 num">Kode {{ asset.code }}</span>
            </span>
            <button type="button" class="btn btn--small" @click="asset = null">Ganti</button>
          </p>

          <template v-else>
            <form class="pick-search" @submit.prevent="searchAssets">
              <div class="field">
                <label for="asset-query" class="visually-hidden">Cari aset</label>
                <input
                  id="asset-query"
                  v-model="searchQuery"
                  class="input"
                  type="search"
                  name="q"
                  placeholder="Cari aset"
                />
              </div>
              <button type="submit" class="btn" :disabled="searching">
                {{ searching ? 'Mencari' : 'Cari' }}
              </button>
            </form>

            <p v-if="searchError" class="form-error" role="alert">{{ searchError }}</p>

            <ul v-if="searchDone && searchResults.length" class="pick-results">
              <li v-for="item in searchResults" :key="item.id" class="pick">
                <span class="pick__main">
                  <strong>{{ item.name }}</strong>
                  <span class="text-2 num">Kode {{ item.code }}</span>
                </span>
                <button type="button" class="btn btn--small" @click="asset = item">Pilih</button>
              </li>
            </ul>
          </template>
          <span v-if="fieldErrors.asset_id" class="field-error">{{ fieldErrors.asset_id[0] }}</span>
        </section>

        <form class="step" aria-labelledby="desc-title" @submit.prevent="submit">
          <header class="step__head">
            <div class="step__titles">
              <p class="index-label">RINCIAN MASALAH</p>
              <h2 id="desc-title" class="step__title">Isi laporan</h2>
            </div>
          </header>

          <div class="grid">
            <div class="field">
              <label for="issue-type">Tipe</label>
              <select id="issue-type" v-model="type" class="input" required>
                <option v-for="opt in TYPES" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <span v-if="fieldErrors.type" class="field-error">{{ fieldErrors.type[0] }}</span>
            </div>
            <div class="field">
              <label for="issue-severity">Severity</label>
              <select id="issue-severity" v-model="severity" class="input" required>
                <option v-for="opt in SEVERITIES" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <span v-if="fieldErrors.severity" class="field-error">{{
                fieldErrors.severity[0]
              }}</span>
            </div>
          </div>

          <div class="field">
            <label for="issue-desc">Deskripsi</label>
            <textarea
              id="issue-desc"
              v-model="description"
              class="input"
              rows="3"
              maxlength="5000"
              required
              placeholder="Jelaskan masalah yang ditemukan."
            ></textarea>
            <span v-if="fieldErrors.description" class="field-error">{{
              fieldErrors.description[0]
            }}</span>
          </div>

          <div class="field">
            <label for="issue-loan">ID peminjaman terkait (opsional)</label>
            <input
              id="issue-loan"
              v-model="loanId"
              class="input num"
              type="number"
              min="1"
              inputmode="numeric"
              placeholder="Contoh: 12"
            />
            <span v-if="fieldErrors.loan_id" class="field-error">{{ fieldErrors.loan_id[0] }}</span>
          </div>

          <div class="field">
            <label for="issue-photos">
              Foto{{ severity === 'critical' ? ' (wajib minimal 1 untuk kritis)' : ' (opsional)' }}
            </label>
            <input
              id="issue-photos"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              multiple
              class="input"
              @change="addPhotos"
            />
            <span v-if="fieldErrors.photos" class="field-error">{{ fieldErrors.photos[0] }}</span>
            <ul v-if="photos.length" class="photo-list">
              <li v-for="(photo, index) in photos" :key="index" class="photo-item">
                <span class="text-2">{{ photo.name }}</span>
                <button type="button" class="btn btn--small" @click="removePhoto(index)">
                  Hapus
                </button>
              </li>
            </ul>
          </div>

          <div class="act-row act-row--foot">
            <button type="submit" class="btn btn--primary" :disabled="!canSubmit">
              {{ sending ? 'Mengirim' : 'Kirim laporan' }}
            </button>
            <p class="act-row__hint text-2">
              Pastikan aset dan deskripsi sudah terisi sebelum mengirim.
            </p>
          </div>
        </form>
      </div>

      <aside class="summary" aria-labelledby="summary-title">
        <p class="index-label">RINGKASAN</p>
        <h2 id="summary-title" class="summary__title">Isianmu sejauh ini</h2>
        <dl class="summary__list">
          <div v-for="row in summary" :key="row.label" class="summary__row">
            <dt>{{ row.label }}</dt>
            <dd>{{ row.value }}</dd>
          </div>
        </dl>
        <p class="summary__note text-2">
          Periksa kembali aset dan severity sebelum mengirim. Laporan bisa ditinjau manajemen
          setelah masuk.
        </p>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.issue-create {
  display: grid;
  gap: 2.5rem;
}

.create__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 20rem;
  gap: 2.5rem;
  align-items: start;
}

.create__main {
  display: grid;
  gap: 2rem;
}

/* Langkah, dipisah garis rule tebal. */
.step {
  display: grid;
  gap: 1rem;
  padding-top: 1.25rem;
  border-top: 2px solid var(--ln-border-strong);
}

.step__head {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: start;
  gap: 1rem;
}

.step__titles .index-label {
  margin-bottom: 0.375rem;
}

.step__title {
  margin: 0;
  font-size: var(--ln-title);
}

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 1.25rem;
}

.pick {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  margin: 0;
  padding: 0.75rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
}

.pick__main {
  display: grid;
  gap: 0.125rem;
  flex: 1;
}

.pick-results {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}

.pick-search {
  display: flex;
  gap: 0.75rem;
  align-items: end;
}

.pick-search .field {
  margin: 0;
  flex: 1;
}

.photo-list {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.25rem;
}

.photo-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  min-height: 44px;
  padding: 0.25rem 0;
}

.act-row {
  display: flex;
  gap: 0.75rem;
}

.act-row--foot {
  align-items: center;
  padding-top: 1rem;
  border-top: 1px solid var(--ln-border);
  flex-wrap: wrap;
}

.act-row__hint {
  margin: 0;
  font-size: 0.8125rem;
}

/* Panel ringkasan samping. */
.summary {
  position: sticky;
  top: 1.5rem;
  display: grid;
  gap: 0.75rem;
  padding: 1.25rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface);
}

.summary .index-label {
  margin-bottom: 0;
}

.summary__title {
  margin: 0 0 0.25rem;
  font-size: var(--ln-title);
}

.summary__list {
  margin: 0;
  border-top: 1px solid var(--ln-border);
}

.summary__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.75rem;
  align-items: baseline;
  padding: 0.625rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.summary__row dt {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.summary__row dd {
  margin: 0;
  font-size: 0.875rem;
  font-weight: 600;
  text-align: right;
}

.summary__note {
  margin: 0;
  font-size: 0.8125rem;
  line-height: 1.7;
}

.field-error {
  display: block;
  margin-top: 0.25rem;
  color: var(--ln-err);
  font-size: 0.8125rem;
}

@media (max-width: 60rem) {
  .create__grid {
    grid-template-columns: 1fr;
  }

  .summary {
    position: static;
    order: -1;
  }
}

@media (max-width: 36rem) {
  .grid {
    grid-template-columns: 1fr;
  }

  .pick-search {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
