<script setup>
/*
 * Form pembuatan tiket perawatan untuk operator/admin. Aset awal bisa diisi
 * dari query `asset`, dan laporan terkait dari query `issue` (dikirim tombol
 * Buat tiket di detail laporan atau detail aset).
 * Field, validasi, payload, dan alur submit tidak berubah; yang baru hanya
 * susunan langkah bernomor, garis rule, panel ringkasan, dan gerak.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { fmtDateTime, fmtPrice } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const route = useRoute()
const router = useRouter()

const asset = ref(null)
const loading = ref(true)
const loadError = ref(null)

const issueId = ref('')
const priority = ref('')
const description = ref('')
const scheduledStart = ref('')
const scheduledEnd = ref('')
const estimatedCost = ref('')

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
function validateTicketForm() {
  const errors = {}

  // Check asset
  if (!asset.value) {
    errors.asset = ['Pilih aset.']
  }

  // Check description
  if (!description.value || !description.value.trim()) {
    errors.description = ['Deskripsi harus diisi.']
  } else if (description.value.trim().length < 5) {
    errors.description = ['Deskripsi terlalu pendek (minimal 5 karakter).']
  }

  // Check issueId if provided
  if (issueId.value !== '' && issueId.value !== null) {
    const issueIdNum = Number(issueId.value)
    if (!Number.isInteger(issueIdNum) || issueIdNum <= 0) {
      errors.issue_id = ['ID laporan harus berupa bilangan bulat positif.']
    }
  }

  // Check estimatedCost if provided
  if (estimatedCost.value !== '') {
    const costNum = Number(estimatedCost.value)
    if (!Number.isFinite(costNum) || costNum < 0) {
      errors.estimated_cost = ['Perkiraan biaya harus berupa angka yang valid.']
    }
  }

  return errors
}

// --- Load ticket request data from sessionStorage (set by the calling view) ---
const loadTicketRequestData = () => {
  try {
    const data = sessionStorage.getItem('ticket-request-data')
    if (data) {
      const parsed = JSON.parse(data)
      // Clear the data after reading
      sessionStorage.removeItem('ticket-request-data')
      return parsed
    }
  } catch (e) {
    console.warn('Failed to parse ticket request data from sessionStorage', e)
  }
  return null
}

const ticketRequestData = loadTicketRequestData()

// Set initial values from the request data
if (ticketRequestData) {
  issueId.value = ticketRequestData.issue ?? ''
}

// --- Priority options ---
const PRIORITIES = [
  { value: '', label: 'Ikuti laporan (atau Sedang bila tanpa laporan)' },
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
  {
    label: 'Laporan terkait',
    value: issueId.value !== '' && issueId.value !== null ? `#${issueId.value}` : 'Tanpa laporan',
  },
  {
    label: 'Prioritas',
    value: priority.value ? labelOf(PRIORITIES, priority.value) : 'Ikuti laporan',
  },
  { label: 'Rencana mulai', value: fmtDateTime(scheduledStart.value) ?? 'Belum dijadwalkan' },
  { label: 'Rencana selesai', value: fmtDateTime(scheduledEnd.value) ?? 'Belum dijadwalkan' },
  { label: 'Perkiraan biaya', value: formatCost(estimatedCost.value) },
  {
    label: 'Deskripsi',
    value: description.value.trim() ? `${description.value.trim().length} karakter` : 'Belum diisi',
  },
])

function labelOf(options, value) {
  return options.find((opt) => opt.value === value)?.label ?? '-'
}

function formatCost(value) {
  if (value === '' || value === null) {
    return 'Belum diisi'
  }
  const amount = Number(value)
  return Number.isFinite(amount) ? fmtPrice(amount) : 'Belum diisi'
}

async function loadInitial() {
  loading.value = true
  loadError.value = null
  try {
    // If we have an asset ID from the request data, fetch the asset
    if (ticketRequestData?.asset) {
      const assetId = Number(ticketRequestData.asset)
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

async function submit() {
  sending.value = true
  submitError.value = null
  fieldErrors.value = {}
  // Client-side validation
  const validationErrors = validateTicketForm()
  if (Object.keys(validationErrors).length > 0) {
    fieldErrors.value = validationErrors
    sending.value = false
    return
  }
  const body = {
    description: description.value.trim(),
  }
  if (issueId.value !== '' && issueId.value !== null) {
    body.issue_id = Number(issueId.value)
  }
  if (priority.value) {
    body.priority = priority.value
  }
  if (scheduledStart.value) {
    body.scheduled_start = scheduledStart.value
  }
  if (scheduledEnd.value) {
    body.scheduled_end = scheduledEnd.value
  }
  if (estimatedCost.value !== '') {
    body.estimated_cost = Number(estimatedCost.value)
  }

  try {
    const payload = await apiPost(`/assets/${asset.value.id}/maintenance-tickets`, body)
    router.replace({ name: 'ticket-detail', params: { id: payload.data.id } })
  } catch (e) {
    if (e instanceof ApiError) {
      submitError.value = e.message
      fieldErrors.value = e.errors ?? {}
    } else {
      submitError.value = 'Gagal membuat tiket.'
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section ref="page" class="ticket-create">
    <PageHead
      label="TIKET PERAWATAN"
      title="Buat tiket perawatan"
      lede="Catat pekerjaan perbaikan untuk satu aset."
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
              <h2 id="asset-title" class="step__title">Aset yang diperbaiki</h2>
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
        </section>

        <form class="step" aria-labelledby="work-title" @submit.prevent="submit">
          <header class="step__head">
            <div class="step__titles">
              <p class="index-label">RINCIAN PEKERJAAN</p>
              <h2 id="work-title" class="step__title">Isi tiket</h2>
            </div>
          </header>

          <div class="grid">
            <div class="field">
              <label for="ticket-issue">ID laporan terkait (opsional)</label>
              <input
                id="ticket-issue"
                v-model="issueId"
                class="input num"
                type="number"
                min="1"
                inputmode="numeric"
                placeholder="Contoh: 4"
              />
              <span v-if="fieldErrors.issue_id" class="field-error">{{
                fieldErrors.issue_id[0]
              }}</span>
            </div>
            <div class="field">
              <label for="ticket-priority">Prioritas</label>
              <select id="ticket-priority" v-model="priority" class="input">
                <option v-for="opt in PRIORITIES" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <span v-if="fieldErrors.priority" class="field-error">{{
                fieldErrors.priority[0]
              }}</span>
            </div>
          </div>

          <div class="field">
            <label for="ticket-desc">Deskripsi pekerjaan</label>
            <textarea
              id="ticket-desc"
              v-model="description"
              class="input"
              rows="3"
              maxlength="5000"
              required
              placeholder="Jelaskan pekerjaan perbaikan yang perlu dilakukan."
            ></textarea>
            <span v-if="fieldErrors.description" class="field-error">{{
              fieldErrors.description[0]
            }}</span>
          </div>

          <div class="grid">
            <div class="field">
              <label for="ticket-start">Rencana mulai (opsional)</label>
              <input
                id="ticket-start"
                v-model="scheduledStart"
                class="input"
                type="datetime-local"
                name="scheduled_start"
              />
              <span v-if="fieldErrors.scheduled_start" class="field-error">{{
                fieldErrors.scheduled_start[0]
              }}</span>
            </div>
            <div class="field">
              <label for="ticket-end">Rencana selesai (opsional)</label>
              <input
                id="ticket-end"
                v-model="scheduledEnd"
                class="input"
                type="datetime-local"
                name="scheduled_end"
              />
              <span v-if="fieldErrors.scheduled_end" class="field-error">{{
                fieldErrors.scheduled_end[0]
              }}</span>
            </div>
          </div>

          <div class="field">
            <label for="ticket-cost">Perkiraan biaya (Rp, opsional)</label>
            <input
              id="ticket-cost"
              v-model="estimatedCost"
              class="input num"
              type="number"
              min="0"
              inputmode="numeric"
              placeholder="Contoh: 150000"
            />
            <span v-if="fieldErrors.estimated_cost" class="field-error">{{
              fieldErrors.estimated_cost[0]
            }}</span>
          </div>

          <div class="act-row act-row--foot">
            <button type="submit" class="btn btn--primary" :disabled="!canSubmit">
              {{ sending ? 'Membuat' : 'Buat tiket' }}
            </button>
            <p class="act-row__hint text-2">Pastikan aset dan deskripsi pekerjaan sudah terisi.</p>
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
          Tiket masuk sebagai baru dan menunggu penugasan teknisi sebelum pengerjaan dimulai.
        </p>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.ticket-create {
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
