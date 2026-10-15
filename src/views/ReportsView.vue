<script setup>
/*
 * Laporan admin: utilisasi aset, aset bermasalah, dan rekap peminjaman,
 * masing-masing dengan filter periode dan tombol ekspor CSV.
 *
 * Presentasi editorial: kepala halaman PageHead, ringkasan angka menghitung
 * (StatGrid), laporan disusun sebagai blok bernomor bergaris rule, dan rail
 * progres yang terisi mengikuti gulir (lib/motion.js). Data dan API tidak
 * berubah.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { apiBlob, apiGet, ApiError } from '../lib/http'
import {
  CONDITION_LABELS,
  fmtDateTime,
  LIFECYCLE_LABELS,
  LOAN_STATUS_LABELS,
  LOAN_TONES,
} from '../lib/format'
import { gsap } from 'gsap'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'
import StatGrid from '../components/ui/StatGrid.vue'

const utilization = ref([])
const problems = ref([])
const loanReport = ref(null)

const loading = ref(true)
const error = ref(null)

const from = ref('')
const to = ref('')
const limit = ref(10)

const exportError = ref(null)
const exporting = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const LOAN_ORDER = [
  'requested',
  'approved',
  'checked_out',
  'returned',
  'rejected',
  'cancelled',
  'expired',
]

function periodParams(extra = {}) {
  const params = new URLSearchParams()
  if (from.value) {
    params.set('from', from.value)
  }
  if (to.value) {
    params.set('to', to.value)
  }
  for (const [key, value] of Object.entries(extra)) {
    if (value !== '' && value != null) {
      params.set(key, String(value))
    }
  }
  return params.toString()
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const [utilPayload, problemPayload, loanPayload] = await Promise.all([
      apiGet(`/reports/utilization?${periodParams({ limit: limit.value })}`),
      apiGet(`/reports/problems?${periodParams({ limit: limit.value })}`),
      apiGet(`/reports/loans?${periodParams()}`),
    ])
    utilization.value = utilPayload.data ?? []
    problems.value = problemPayload.data ?? []
    loanReport.value = loanPayload
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat laporan.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  await nextTick()
  motion.reveal('.block', { stagger: 0.12, start: 'top 90%' })
  motion.scrubProgress('.rail__fill', '.rail-host', { start: 'top 78%', end: 'bottom 58%' })
  motion.run(() => {
    gsap.fromTo(
      '.recap__bar > span',
      { scaleX: 0, transformOrigin: 'left center' },
      { scaleX: 1, duration: 0.6, stagger: 0.05, ease: 'power3.out' },
    )
  })
  motion.refresh()
})

async function exportCsv(report) {
  exporting.value = report
  exportError.value = null
  try {
    const query = periodParams({ report, limit: 500 })
    const blob = await apiBlob(`/reports/export?${query}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${report}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    exportError.value = e instanceof ApiError ? e.message : 'Gagal mengekspor CSV.'
  } finally {
    exporting.value = null
  }
}

// Presentasi saja: ringkasan angka dan skala bar rekap.
const reportStats = computed(() => [
  { value: loanReport.value?.total ?? 0, label: 'Total peminjaman', meta: 'PERIODE AKTIF' },
  { value: loanReport.value?.overdue ?? 0, label: 'Terlambat', meta: 'PERLU TINDAK LANJUT' },
  {
    value: utilization.value.reduce((sum, row) => sum + (row.loan_count ?? 0), 0),
    label: 'Pemakaian aset',
    meta: 'DAFTAR UTILISASI',
  },
  { value: problems.value.length, label: 'Aset bermasalah', meta: 'PERLU ATENSI' },
])

const loanCounts = computed(() => LOAN_ORDER.map((key) => loanReport.value?.by_status?.[key] ?? 0))

const loanMax = computed(() => Math.max(1, ...loanCounts.value))

function share(count) {
  return Math.round((count / loanMax.value) * 100)
}

function pad2(value) {
  return String(value).padStart(2, '0')
}
</script>

<template>
  <section ref="page" class="reports">
    <PageHead
      label="LAPORAN"
      title="Laporan operasional"
      lede="Utilisasi aset, aset bermasalah, dan rekap peminjaman. Semua angka mengikuti filter periode."
    />

    <form class="filters" @submit.prevent="load">
      <div class="field">
        <label for="rep-from">Dari tanggal</label>
        <input id="rep-from" v-model="from" class="input" type="date" name="from" />
      </div>
      <div class="field">
        <label for="rep-to">Sampai tanggal</label>
        <input id="rep-to" v-model="to" class="input" type="date" name="to" />
      </div>
      <div class="field">
        <label for="rep-limit">Batas baris</label>
        <input
          id="rep-limit"
          v-model.number="limit"
          class="input num"
          type="number"
          min="1"
          max="100"
          name="limit"
        />
      </div>
      <button type="submit" class="btn btn--primary" :disabled="loading">
        {{ loading ? 'Memuat' : 'Terapkan' }}
      </button>
    </form>

    <LoadingState v-if="loading" label="Memuat laporan" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else>
      <StatGrid v-if="loanReport" :items="reportStats" />

      <p v-if="exportError" class="form-error" role="alert">{{ exportError }}</p>

      <div class="body rail-host">
        <span class="rail" aria-hidden="true"><span class="rail__fill"></span></span>

        <section class="block" aria-labelledby="util-title">
          <header class="block__head">
            <div class="block__heading">
              <p class="index-label">UTILISASI</p>
              <h2 id="util-title" class="block__title">Aset paling sering dipinjam</h2>
            </div>
            <button
              type="button"
              class="btn btn--ghost"
              :disabled="exporting === 'utilization'"
              @click="exportCsv('utilization')"
            >
              {{ exporting === 'utilization' ? 'Mengekspor' : 'Ekspor CSV' }}
            </button>
          </header>

          <EmptyState
            v-if="utilization.length === 0"
            title="Belum ada data"
            hint="Belum ada peminjaman pada periode ini."
          />
          <ol v-else class="rows">
            <li v-for="row in utilization" :key="row.asset_id" class="row">
              <span class="row__index num" aria-hidden="true">{{ pad2(row.rank) }}</span>
              <RouterLink
                :to="{ name: 'asset-detail', params: { id: row.asset_id } }"
                class="row__main link-sweep"
              >
                <strong class="row__title">{{ row.name }}</strong>
                <span class="row__meta num">
                  KODE {{ row.code }}
                  <template v-if="row.last_borrowed_at">
                    · TERAKHIR {{ fmtDateTime(row.last_borrowed_at) }}
                  </template>
                </span>
              </RouterLink>
              <span class="row__figure">
                <b class="num">{{ row.loan_count }}</b>
                <small>KALI DIPINJAM</small>
              </span>
            </li>
          </ol>
        </section>

        <section class="block" aria-labelledby="prob-title">
          <header class="block__head">
            <div class="block__heading">
              <p class="index-label">ASET BERMASALAH</p>
              <h2 id="prob-title" class="block__title">Perlu atensi</h2>
            </div>
            <button
              type="button"
              class="btn btn--ghost"
              :disabled="exporting === 'problems'"
              @click="exportCsv('problems')"
            >
              {{ exporting === 'problems' ? 'Mengekspor' : 'Ekspor CSV' }}
            </button>
          </header>

          <EmptyState
            v-if="problems.length === 0"
            title="Tidak ada aset bermasalah"
            hint="Semua aset dalam kondisi baik dan tidak ada tiket atau laporan terbuka."
          />
          <ul v-else class="rows">
            <li v-for="row in problems" :key="row.asset_id" class="row">
              <span class="row__index num" aria-hidden="true">!</span>
              <RouterLink
                :to="{ name: 'asset-detail', params: { id: row.asset_id } }"
                class="row__main link-sweep"
              >
                <strong class="row__title">{{ row.name }}</strong>
                <span class="row__meta">
                  {{ CONDITION_LABELS[row.condition] ?? row.condition }},
                  {{ LIFECYCLE_LABELS[row.lifecycle_status] ?? row.lifecycle_status }}
                </span>
              </RouterLink>
              <span class="row__figures">
                <span class="row__figure">
                  <b class="num">{{ row.open_tickets }}</b>
                  <small>TIKET</small>
                </span>
                <span class="row__figure">
                  <b class="num">{{ row.open_issues }}</b>
                  <small>LAPORAN</small>
                </span>
              </span>
            </li>
          </ul>
        </section>

        <section class="block" aria-labelledby="loan-title">
          <header class="block__head">
            <div class="block__heading">
              <p class="index-label">REKAP PINJAMAN</p>
              <h2 id="loan-title" class="block__title">Sebaran status</h2>
            </div>
            <button
              type="button"
              class="btn btn--ghost"
              :disabled="exporting === 'loans'"
              @click="exportCsv('loans')"
            >
              {{ exporting === 'loans' ? 'Mengekspor' : 'Ekspor CSV' }}
            </button>
          </header>

          <template v-if="loanReport">
            <p class="recap__total">
              <b class="num">{{ loanReport.total }}</b> peminjaman pada periode ini
              <template v-if="loanReport.overdue">, {{ loanReport.overdue }} terlambat </template>.
            </p>
            <ul class="recap">
              <li v-for="(statusKey, index) in LOAN_ORDER" :key="statusKey" class="recap__row">
                <StatusBadge :tone="LOAN_TONES[statusKey] ?? 'neutral'">
                  {{ LOAN_STATUS_LABELS[statusKey] ?? statusKey }}
                </StatusBadge>
                <span class="recap__bar" aria-hidden="true">
                  <span :style="{ width: `${share(loanCounts[index])}%` }"></span>
                </span>
                <span class="recap__count num">{{ loanCounts[index] }}</span>
              </li>
            </ul>
          </template>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.reports {
  display: grid;
  gap: 2.5rem;
}

/* Filter periode: baris bergaris, label mono huruf besar. */
.filters {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 12rem)) auto;
  gap: 0.75rem 1rem;
  align-items: end;
  padding: 1.25rem 0;
  border-block: 1px solid var(--ln-border);
}

.filters .field {
  margin: 0;
}

.filters label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/* Badan laporan dengan rail progres vertikal. */
.body {
  position: relative;
  display: grid;
  gap: 2.5rem;
  padding-left: 2.5rem;
}

.rail {
  position: absolute;
  top: 0.5rem;
  bottom: 0.5rem;
  left: 0;
  width: 1px;
  background: var(--ln-border);
}

.rail__fill {
  position: absolute;
  inset: 0;
  background: var(--ln-text);
  transform: scaleY(0);
  transform-origin: top center;
}

/* Blok laporan bernomor. */
.block {
  display: grid;
  gap: 1.25rem;
  padding-top: 1.5rem;
  border-top: 2px solid var(--ln-border-strong);
}

.block__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-end;
  gap: 1rem;
}

.block__heading {
  max-width: 46rem;
}

.block__heading .index-label {
  margin-bottom: 0.75rem;
}

.block__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

/* Baris data: indeks mono, isi, angka kontras. */
.rows {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.row {
  display: grid;
  grid-template-columns: 2.5rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 1rem;
  min-height: 4.5rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.row__index {
  color: var(--ln-n400);
  font-size: 1.125rem;
}

.row__main {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
  color: var(--ln-text);
  text-decoration: none;
}

.row__title {
  font-size: 1rem;
}

.row__meta {
  color: var(--ln-text-2);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.row__figures {
  display: flex;
  gap: 1.5rem;
}

.row__figure {
  display: grid;
  justify-items: end;
  gap: 0.125rem;
}

.row__figure b {
  font-family: var(--ln-font-display);
  font-size: 1.5rem;
  font-weight: 500;
  letter-spacing: -0.03em;
  line-height: 1;
}

.row__figure small {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
}

/* Rekap status: badge, bar proporsional, angka. */
.recap__total {
  margin: 0;
  max-width: 46ch;
  color: var(--ln-text-2);
}

.recap__total b {
  color: var(--ln-text);
  font-family: var(--ln-font-display);
  font-size: var(--ln-display-sm);
  font-weight: 500;
  letter-spacing: -0.04em;
}

.recap {
  margin: 0;
  padding: 0;
  list-style: none;
  max-width: 44rem;
  border-top: 1px solid var(--ln-border);
}

.recap__row {
  display: grid;
  grid-template-columns: 9rem minmax(0, 1fr) 4rem;
  align-items: center;
  gap: 1rem;
  min-height: 3rem;
  border-bottom: 1px solid var(--ln-border);
}

.recap__bar {
  display: block;
  height: 2px;
  background: var(--ln-border);
}

.recap__bar > span {
  display: block;
  height: 100%;
  background: var(--ln-text);
  transform: scaleX(0);
  transform-origin: left center;
}

.recap__count {
  text-align: right;
  font-size: 0.9375rem;
}

@media (max-width: 48rem) {
  .filters {
    grid-template-columns: 1fr;
  }

  .body {
    padding-left: 1.5rem;
  }

  .row {
    grid-template-columns: 2rem minmax(0, 1fr);
    align-items: start;
  }

  .row__figures,
  .row__figure {
    grid-column: 2;
    justify-items: start;
  }

  .recap__row {
    grid-template-columns: 8rem minmax(0, 1fr) 3rem;
    gap: 0.75rem;
  }
}
</style>
