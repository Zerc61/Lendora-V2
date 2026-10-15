<script setup>
/*
 * Form pengajuan pinjaman. Aset awal bisa diisi dari query `assets` (id pisah
 * koma) dan periode dari `starts_at`/`ends_at` (dikirim tombol Minta pinjam di
 * detail aset). Pengguna bisa menambah aset lain lewat pencarian katalog, lalu
 * memeriksa ketersediaan periode sebelum mengirim pengajuan.
 *
 * Presentasi: langkah bernomor dengan label mono dan garis rule antar blok,
 * plus panel ringkasan di samping. Validasi, payload, field, dan alur submit
 * tidak berubah.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { fmtDateTime } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const route = useRoute()
const router = useRouter()

const MAX_ASSETS = 20

// Load loan request data from sessionStorage (set by the calling view)
const loadLoanRequestData = () => {
  try {
    const data = sessionStorage.getItem('loan-request-data')
    if (data) {
      const parsed = JSON.parse(data)
      // Clear the data after reading
      sessionStorage.removeItem('loan-request-data')
      return parsed
    }
  } catch (e) {
    console.warn('Failed to parse loan request data from sessionStorage', e)
  }
  return null
}

const loanRequestData = loadLoanRequestData()

// Store asset IDs from the request data (to fetch asset details)
const assetIds = ref(loanRequestData?.assets ?? [])
// Store period data
const startsAt = ref(loanRequestData?.starts_at ?? '')
const endsAt = ref(loanRequestData?.ends_at ?? '')
const purpose = ref('')
const selected = ref([]) // Will be populated with asset objects
const loading = ref(true)
const loadError = ref(null)

const searchQuery = ref('')
const searchResults = ref([])
const searching = ref(false)
const searchError = ref(null)
const searchDone = ref(false)

const availChecks = ref({})
const availUpdatedAt = ref(null)
const checking = ref(false)
const availError = ref(null)

const sending = ref(false)
const submitError = ref(null)
const fieldErrors = ref({})
const createdLoan = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

// --- Client-side validation ---
function validateLoanForm() {
  const errors = {}

  // Check assets
  if (selected.value.length === 0) {
    errors.assets = ['Pilih setidaknya satu aset.']
  }

  // Check dates
  if (!startsAt.value) {
    errors.starts_at = ['Tanggal mulai harus diisi.']
  } else {
    const startsAtDate = new Date(startsAt.value)
    if (isNaN(startsAtDate.getTime())) {
      errors.starts_at = ['Format tanggal mulai tidak valid.']
    }
  }

  if (!endsAt.value) {
    errors.ends_at = ['Tanggal selesai harus diisi.']
  } else {
    const endsAtDate = new Date(endsAt.value)
    if (isNaN(endsAtDate.getTime())) {
      errors.ends_at = ['Format tanggal selesai tidak valid.']
    }
  }

  // Check date range if both are valid
  if (startsAt.value && endsAt.value && !errors.starts_at && !errors.ends_at) {
    const startsAtDate = new Date(startsAt.value)
    const endsAtDate = new Date(endsAt.value)
    if (startsAtDate >= endsAtDate) {
      errors.ends_at = ['Tanggal selesai harus setelah tanggal mulai.']
    }
    // Optionally, check that dates are not in the past
    const now = new Date()
    if (startsAtDate < now) {
      errors.starts_at = ['Tanggal mulai tidak boleh di masa lalu.']
    }
    if (endsAtDate < now) {
      // If we already set an error for starts_at, we might not want to overwrite
      if (!errors.starts_at) {
        errors.ends_at = ['Tanggal selesai tidak boleh di masa lalu.']
      }
    }
  }

  return errors
}

const selectedIds = computed(() => selected.value.map((asset) => asset.id))
const canCheck = computed(
  () => selected.value.length > 0 && startsAt.value && endsAt.value && !checking.value,
)
const canSubmit = computed(
  () => selected.value.length > 0 && startsAt.value && endsAt.value && !sending.value,
)

/* Ringkasan hasil pemeriksaan ketersediaan (presentasi saja). */
const availSummary = computed(() => {
  if (!availUpdatedAt.value) {
    return null
  }
  const checks = selected.value.map((asset) => availChecks.value[asset.id]).filter(Boolean)
  const ok = checks.filter((check) => !check.error && check.requestable).length
  const problems = checks.filter((check) => check.error || !check.requestable).length
  return { ok, problems, total: selected.value.length }
})

async function loadInitial() {
  loading.value = true
  loadError.value = null
  try {
    // If we have asset IDs, fetch the asset details
    if (assetIds.value.length > 0) {
      const ids = assetIds.value
      const payloads = await Promise.all(ids.map((assetId) => apiGet(`/assets/${assetId}`)))
      selected.value = payloads.map((payload) => payload.data)
    }
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Gagal memuat aset pilihan.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadInitial()
  await nextTick()
  motion.reveal('.step', { stagger: 0.1 })
  motion.reveal('.summary', { delay: 0.15 })
  motion.refresh()
})

watch([startsAt, endsAt], () => {
  availChecks.value = {}
  availUpdatedAt.value = null
})

function addAsset(asset) {
  if (selected.value.some((item) => item.id === asset.id)) {
    return
  }
  if (selected.value.length >= MAX_ASSETS) {
    return
  }
  selected.value.push(asset)
  availChecks.value = {}
  availUpdatedAt.value = null
}

function removeAsset(assetId) {
  selected.value = selected.value.filter((asset) => asset.id !== assetId)
  availChecks.value = {}
  availUpdatedAt.value = null
}

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

async function checkAvailability() {
  if (!canCheck.value) {
    return
  }
  // Client-side validation
  const validationErrors = validateLoanForm()
  if (Object.keys(validationErrors).length > 0) {
    fieldErrors.value = validationErrors
    return
  }
  checking.value = true
  availError.value = null
  availChecks.value = {}
  availUpdatedAt.value = null

  const period = {
    starts_at: startsAt.value,
    ends_at: endsAt.value,
  }

  await Promise.all(
    selected.value.map(async (asset) => {
      const params = new URLSearchParams(period)
      try {
        const payload = await apiGet(`/assets/${asset.id}/availability?${params.toString()}`)
        availChecks.value[asset.id] = { error: null, ...payload.data }
      } catch (e) {
        availChecks.value[asset.id] = {
          error: e instanceof ApiError ? e.message : 'Gagal memeriksa.',
        }
      }
    }),
  )

  checking.value = false
  availUpdatedAt.value = startsAt.value + endsAt.value
}

async function submit() {
  sending.value = true
  submitError.value = null
  fieldErrors.value = {}
  // Client-side validation
  const validationErrors = validateLoanForm()
  if (Object.keys(validationErrors).length > 0) {
    fieldErrors.value = validationErrors
    sending.value = false
    return
  }
  try {
    const payload = await apiPost('/loans', {
      starts_at: startsAt.value,
      ends_at: endsAt.value,
      purpose: purpose.value.trim() ? purpose.value.trim() : null,
      assets: selectedIds.value,
    })
    createdLoan.value = payload.data
    router.replace({ name: 'loan-detail', params: { id: payload.data.id } })
  } catch (e) {
    if (e instanceof ApiError) {
      submitError.value = e.message
      fieldErrors.value = e.errors ?? {}
    } else {
      submitError.value = 'Gagal mengirim pengajuan.'
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section ref="page" class="loan-create">
    <PageHead
      label="PEMINJAMAN"
      title="Minta pinjam"
      lede="Pilih aset, tentukan periode pemakaian, lalu kirim pengajuan. Operator akan menyetujui pengajuan sebelum aset diambil."
    />

    <LoadingState v-if="loading" label="Memuat aset pilihan" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="loadInitial" />

    <template v-else>
      <p v-if="submitError" class="form-error" role="alert">{{ submitError }}</p>
      <p v-if="createdLoan" class="notice" role="status">Pengajuan terkirim.</p>

      <div class="layout">
        <div class="steps">
          <section class="step" aria-labelledby="assets-title">
            <p class="index-label">PILIH ASET</p>
            <h2 id="assets-title" class="step__title">Aset yang diminta</h2>
            <p class="step__note text-2">
              {{ selected.length }} dari {{ MAX_ASSETS }} aset maksimal.
            </p>

            <EmptyState
              v-if="selected.length === 0"
              title="Belum ada aset"
              hint="Cari aset di bawah, lalu tambahkan ke pengajuan."
            />

            <ul v-else class="pick-list">
              <li v-for="asset in selected" :key="asset.id" class="pick">
                <span class="pick__main">
                  <strong>{{ asset.name }}</strong>
                  <span class="text-2 num">Kode {{ asset.code }}</span>
                </span>

                <span v-if="availChecks[asset.id]" class="pick__avail">
                  <template v-if="availChecks[asset.id].error">
                    <StatusBadge tone="warn">{{ availChecks[asset.id].error }}</StatusBadge>
                  </template>
                  <template v-else>
                    <StatusBadge :tone="availChecks[asset.id].requestable ? 'ok' : 'err'">
                      {{ availChecks[asset.id].requestable ? 'Tersedia periode ini' : 'Bentrok' }}
                    </StatusBadge>
                    <span
                      v-if="
                        !availChecks[asset.id].requestable && availChecks[asset.id].reasons?.length
                      "
                      class="pick__why"
                    >
                      <span v-for="reason in availChecks[asset.id].reasons" :key="reason">{{
                        reason
                      }}</span>
                    </span>
                  </template>
                </span>

                <button
                  type="button"
                  class="btn btn--small"
                  :aria-label="`Hapus ${asset.name} dari pengajuan`"
                  @click="removeAsset(asset.id)"
                >
                  Hapus
                </button>
              </li>
            </ul>

            <form class="pick-search" @submit.prevent="searchAssets">
              <div class="field">
                <label for="pick-query" class="visually-hidden">Cari aset untuk ditambahkan</label>
                <input
                  id="pick-query"
                  v-model="searchQuery"
                  class="input"
                  type="search"
                  name="q"
                  placeholder="Cari aset lain"
                />
              </div>
              <button type="submit" class="btn" :disabled="searching">
                {{ searching ? 'Mencari' : 'Cari' }}
              </button>
            </form>

            <p v-if="searchError" class="form-error" role="alert">{{ searchError }}</p>

            <ul v-if="searchDone && searchResults.length" class="pick-results">
              <li v-for="asset in searchResults" :key="asset.id" class="pick">
                <RouterLink
                  :to="{ name: 'asset-detail', params: { id: asset.id } }"
                  class="pick__main"
                >
                  <strong>{{ asset.name }}</strong>
                  <span class="text-2 num">Kode {{ asset.code }}</span>
                </RouterLink>
                <button
                  type="button"
                  class="btn btn--small"
                  :disabled="
                    selected.some((item) => item.id === asset.id) || selected.length >= MAX_ASSETS
                  "
                  @click="addAsset(asset)"
                >
                  Tambahkan
                </button>
              </li>
            </ul>
          </section>

          <form class="step" aria-labelledby="period-title" @submit.prevent="submit">
            <p class="index-label">PERIODE</p>
            <h2 id="period-title" class="step__title">Periode dan keperluan</h2>

            <div class="period-grid">
              <div class="field">
                <label for="loan-starts">Mulai</label>
                <input
                  id="loan-starts"
                  v-model="startsAt"
                  class="input"
                  type="datetime-local"
                  name="starts_at"
                  required
                />
                <span v-if="fieldErrors.starts_at" class="field-error">{{
                  fieldErrors.starts_at[0]
                }}</span>
              </div>
              <div class="field">
                <label for="loan-ends">Selesai</label>
                <input
                  id="loan-ends"
                  v-model="endsAt"
                  class="input"
                  type="datetime-local"
                  name="ends_at"
                  required
                />
                <span v-if="fieldErrors.ends_at" class="field-error">{{
                  fieldErrors.ends_at[0]
                }}</span>
              </div>
            </div>

            <div class="field">
              <label for="loan-purpose">Keperluan (opsional)</label>
              <textarea
                id="loan-purpose"
                v-model="purpose"
                class="input"
                name="purpose"
                rows="3"
                maxlength="2000"
                placeholder="Contoh: dokumentasi lapangan, peminjaman untuk event, dan lain-lain."
              ></textarea>
              <span v-if="fieldErrors.purpose" class="field-error">{{
                fieldErrors.purpose[0]
              }}</span>
            </div>

            <div class="act-row">
              <button type="button" class="btn" :disabled="!canCheck" @click="checkAvailability">
                {{ checking ? 'Memeriksa' : 'Periksa ketersediaan' }}
              </button>
              <button type="submit" class="btn btn--primary" :disabled="!canSubmit">
                {{ sending ? 'Mengirim' : 'Kirim pengajuan' }}
              </button>
            </div>

            <p class="step__note text-2">
              Pemeriksaan ketersediaan memakai periode yang sama dengan pengajuan. Hasilnya hanya
              petunjuk; konflik tetap dicek ulang saat pengajuan dikirim.
            </p>
          </form>
        </div>

        <aside class="summary panel panel--alt" aria-label="Ringkasan pengajuan">
          <p class="index-label">RINGKASAN</p>
          <dl class="summary__list">
            <div>
              <dt>Aset terpilih</dt>
              <dd class="num">{{ selected.length }}</dd>
            </div>
            <div>
              <dt>Periode</dt>
              <dd>
                {{ fmtDateTime(startsAt) || 'Belum diisi' }}
                <template v-if="startsAt && endsAt"> sampai </template>
                {{ endsAt ? fmtDateTime(endsAt) : '' }}
              </dd>
            </div>
            <div v-if="availSummary">
              <dt>Ketersediaan</dt>
              <dd class="num">{{ availSummary.ok }}/{{ availSummary.total }} tersedia</dd>
            </div>
            <div>
              <dt>Keperluan</dt>
              <dd class="summary__purpose">{{ purpose.trim() || 'Belum diisi' }}</dd>
            </div>
          </dl>
          <p class="summary__foot text-2">
            Pengajuan dikirim ke operator organisasi untuk disetujui sebelum aset diambil.
          </p>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.loan-create {
  display: grid;
  gap: 1.75rem;
}

.layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 20rem;
  align-items: start;
  gap: 2.5rem;
}

.steps {
  display: grid;
  gap: 2.5rem;
}

/* Langkah bernomor: rule tebal di atas, label mono, judul display kecil. */
.step {
  display: grid;
  gap: 1rem;
  padding-top: 1.5rem;
  border-top: 2px solid var(--ln-border-strong);
}

.step__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

.step__note {
  margin: 0;
  font-size: 0.875rem;
}

.notice {
  margin: 0;
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--ln-ok);
  background: var(--ln-ok-bg);
  color: var(--ln-ok);
}

.pick-list,
.pick-results {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  border-top: 1px solid var(--ln-border);
}

.pick-results {
  margin-top: 1rem;
}

.pick {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.pick__main {
  display: grid;
  gap: 0.125rem;
  color: var(--ln-text);
  text-decoration: none;
  flex: 1;
}

.pick__avail {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  justify-content: center;
}

.pick__why {
  display: grid;
  gap: 0.125rem;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
}

.pick-search {
  display: flex;
  gap: 0.75rem;
  align-items: end;
  padding-top: 0.5rem;
  border-top: 1px solid var(--ln-border);
}

.pick-search .field {
  margin: 0;
  flex: 1;
}

.period-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 1.25rem;
}

.act-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: center;
}

.field-error {
  display: block;
  margin-top: 0.25rem;
  color: var(--ln-err);
  font-size: 0.8125rem;
}

/* Panel ringkasan di samping. */
.summary {
  position: sticky;
  top: 2rem;
  display: grid;
  gap: 0.75rem;
}

.summary__list {
  margin: 0;
  display: grid;
}

.summary__list > div {
  padding: 0.625rem 0;
  border-top: 1px solid var(--ln-border);
}

.summary__list dt {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.summary__list dd {
  margin: 0.25rem 0 0;
  font-weight: 600;
  font-size: 0.9375rem;
  overflow-wrap: anywhere;
}

.summary__purpose {
  font-weight: 400 !important;
  color: var(--ln-text-2);
}

.summary__foot {
  margin: 0;
  padding-top: 0.75rem;
  border-top: 1px solid var(--ln-border);
  font-size: 0.8125rem;
  line-height: 1.6;
}

@media (max-width: 64rem) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
    gap: 2rem;
  }

  .summary {
    position: static;
  }
}

@media (max-width: 36rem) {
  .period-grid,
  .pick-search {
    grid-template-columns: 1fr;
  }

  .pick-search {
    flex-direction: column;
    align-items: stretch;
  }

  .pick {
    flex-wrap: wrap;
  }
}
</style>
