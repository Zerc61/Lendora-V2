<script setup>
/*
 * Dashboard Borrower pada Beranda.
 *
 * Sembilan bagian dengan satu sumber data masing-masing, semua dari API:
 * ringkasan angka, kalender jadwal, peminjaman aktif, pengajuan terbaru,
 * notifikasi, katalog singkat, aktivitas terbaru, dan titik awal. Setiap
 * bagian punya state memuat/galal/kosong sendiri sehingga satu request yang
 * gagal tidak meruntuhkan halaman.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Search } from '@lucide/vue'
import { apiGet, ApiError } from '../lib/http'
import { fmtDate, fmtDateTime, fmtPrice, LOAN_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import AssetCard from './AssetCard.vue'
import BorrowerCalendar from './BorrowerCalendar.vue'
import NotificationCenter from './NotificationCenter.vue'
import StatusBadge from './StatusBadge.vue'
import LoadingState from './LoadingState.vue'
import EmptyState from './EmptyState.vue'
import ErrorState from './ErrorState.vue'
import StatGrid from './ui/StatGrid.vue'

const overview = ref(null)
const overviewLoading = ref(true)
const overviewError = ref(null)

const assets = ref([])
const catalogQ = ref('')
const catalogLoading = ref(true)
const catalogError = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const stats = computed(() => {
  const counts = overview.value?.counts
  if (!counts) {
    return []
  }

  return [
    {
      value: counts.active,
      label: 'Peminjaman aktif',
      meta: 'BERJALAN',
      to: { name: 'loans', query: { group: 'active' } },
    },
    {
      value: counts.pending,
      label: 'Menunggu persetujuan',
      meta: 'PENGAJUAN',
      to: { name: 'loans', query: { group: 'pending' } },
    },
    {
      value: counts.unpaid,
      label: 'Perlu dibayar',
      meta: 'SEBELUM DIAMBIL',
      to: { name: 'bookings' },
    },
    {
      value: counts.due_soon,
      label: 'Mendekati tenggat',
      meta: `${overview.value?.due_soon_days ?? 3} HARI KE DEPAN`,
      to: { name: 'loans', query: { group: 'active' } },
    },
    {
      value: counts.completed,
      label: 'Sudah selesai',
      meta: 'RIWAYAT',
      to: { name: 'loans', query: { group: 'history' } },
    },
  ]
})

const activeLoans = computed(() => overview.value?.active ?? [])
const recentRequests = computed(() => overview.value?.requests ?? [])
const duePayments = computed(() => overview.value?.due_payments ?? [])
const activity = computed(() => overview.value?.activity ?? [])

function itemNames(loan) {
  const names = (loan.items ?? []).map((item) => item.asset?.name).filter(Boolean)
  if (names.length <= 2) {
    return names.join(', ')
  }
  return `${names.slice(0, 2).join(', ')} dan ${names.length - 2} lainnya`
}

function dueTag(loan) {
  if (loan.is_overdue) {
    return 'Terlambat'
  }
  if (loan.days_until_due !== null && loan.days_until_due >= 0 && loan.days_until_due <= 3) {
    return loan.days_until_due === 0
      ? 'Tenggat hari ini'
      : `Tenggat ${loan.days_until_due} hari lagi`
  }
  return null
}

async function loadOverview() {
  overviewLoading.value = true
  overviewError.value = null

  try {
    overview.value = await apiGet('/borrower/overview')
  } catch (e) {
    overviewError.value = e instanceof ApiError ? e.message : 'Gagal memuat ringkasan peminjaman.'
    overview.value = null
  } finally {
    overviewLoading.value = false
    if (overview.value) {
      await nextTick()
      motion.reveal('.dash__row', { stagger: 0.04 })
      motion.refresh()
    }
  }
}

async function loadCatalog() {
  catalogLoading.value = true
  catalogError.value = null

  const params = new URLSearchParams({ page: '1' })
  const term = catalogQ.value.trim()
  if (term) {
    params.set('q', term)
  }

  try {
    const payload = await apiGet(`/assets?${params.toString()}`)
    assets.value = (payload.data ?? []).filter((asset) => asset.is_borrowable).slice(0, 4)
  } catch (e) {
    catalogError.value = e instanceof ApiError ? e.message : 'Gagal memuat katalog aset.'
    assets.value = []
  } finally {
    catalogLoading.value = false
    if (assets.value.length) {
      await nextTick()
      motion.reveal('.dash__grid > *', { stagger: 0.05 })
      motion.refresh()
    }
  }
}

function submitSearch() {
  loadCatalog()
}

onMounted(() => {
  loadOverview()
  loadCatalog()
})
</script>

<template>
  <div ref="page" class="dash">
    <!-- 02 / RINGKASAN -->
    <section aria-labelledby="ringkasan-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">RINGKASAN</p>
        <h2 id="ringkasan-title" class="dash__title">Posisi peminjamanmu hari ini.</h2>
      </div>

      <LoadingState v-if="overviewLoading" label="Memuat ringkasan" />

      <ErrorState v-else-if="overviewError" :message="overviewError" @retry="loadOverview" />

      <StatGrid v-else-if="overview" :items="stats" />
    </section>

    <!-- 03 / JADWAL -->
    <section aria-labelledby="jadwal-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">JADWAL</p>
        <h2 id="jadwal-title" class="dash__title">Pengambilan, periode, dan tenggat.</h2>
      </div>

      <BorrowerCalendar />
    </section>

    <!-- 04 / PINJAMAN AKTIF -->
    <section aria-labelledby="aktif-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">PINJAMAN AKTIF</p>
        <h2 id="aktif-title" class="dash__title">Sedang berjalan.</h2>
      </div>

      <LoadingState v-if="overviewLoading" label="Memuat peminjaman aktif" />

      <ErrorState v-else-if="overviewError" :message="overviewError" @retry="loadOverview" />

      <EmptyState
        v-else-if="!activeLoans.length"
        title="Tidak ada peminjaman aktif"
        hint="Pengajuan yang disetujui dan pinjaman yang sedang dipinjam akan tampil di sini."
      >
        <template #action>
          <RouterLink :to="{ name: 'catalog' }" class="btn btn--primary">Cari aset</RouterLink>
        </template>
      </EmptyState>

      <ul v-else class="dash__list">
        <li v-for="loan in activeLoans" :key="loan.id" class="dash__row">
          <RouterLink :to="{ name: 'loan-detail', params: { id: loan.id } }" class="row">
            <div class="row__main">
              <div class="row__top">
                <StatusBadge :tone="LOAN_TONES[loan.status] ?? 'neutral'">
                  {{ loan.status_label }}
                </StatusBadge>
                <span
                  v-if="dueTag(loan)"
                  class="tag"
                  :class="loan.is_overdue ? 'tag--err' : 'tag--warn'"
                >
                  {{ dueTag(loan) }}
                </span>
              </div>
              <h3 class="row__title">{{ itemNames(loan) || 'Pinjaman aset' }}</h3>
              <p class="row__meta text-2">
                Ambil {{ fmtDateTime(loan.starts_at) }}
                <template v-if="loan.due_at"> · tenggat {{ fmtDateTime(loan.due_at) }}</template>
              </p>
            </div>
          </RouterLink>
        </li>
      </ul>

      <p v-if="!overviewLoading && !overviewError && activeLoans.length" class="more-link">
        <RouterLink :to="{ name: 'loans', query: { group: 'active' } }" class="btn">
          Semua peminjaman aktif
        </RouterLink>
      </p>
    </section>

    <!-- 04b / PERLU DIBAYAR — tagihan yang menahan serah terima -->
    <section v-if="duePayments.length" aria-labelledby="bayar-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">PERLU DIBAYAR</p>
        <h2 id="bayar-title" class="dash__title">Bayar dulu, lalu aset diserahkan.</h2>
        <p class="text-2" style="margin: 0.25rem 0 0">
          Pesanan ini sudah disetujui operator. Bayar dengan saldo dompet (atau Midtrans) di halaman
          Booking saya; serah terima berjalan setelah pembayaran lunas.
        </p>
      </div>

      <ul class="dash__list">
        <li v-for="loan in duePayments" :key="loan.id" class="dash__row">
          <div class="pay-row">
            <div class="row__main">
              <div class="row__top">
                <StatusBadge tone="warn">Menunggu pembayaran</StatusBadge>
                <span class="row__count num">{{ fmtPrice(loan.subtotal) }}</span>
              </div>
              <h3 class="row__title">{{ itemNames(loan) || 'Pinjaman aset' }}</h3>
              <p class="row__meta text-2">
                Ambil {{ fmtDateTime(loan.starts_at) }}
                <template v-if="loan.ends_at"> · sampai {{ fmtDateTime(loan.ends_at) }}</template>
              </p>
            </div>
            <RouterLink :to="{ name: 'bookings' }" class="btn btn--primary">Bayar</RouterLink>
          </div>
        </li>
      </ul>
    </section>

    <!-- 05 / PENGAJUAN TERBARU -->
    <section aria-labelledby="pengajuan-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">PENGAJUAN TERBARU</p>
        <h2 id="pengajuan-title" class="dash__title">Permintaan yang baru kamu kirim.</h2>
      </div>

      <LoadingState v-if="overviewLoading" label="Memuat pengajuan" />

      <ErrorState v-else-if="overviewError" :message="overviewError" @retry="loadOverview" />

      <EmptyState
        v-else-if="!recentRequests.length"
        title="Belum ada pengajuan"
        hint="Ajukan peminjaman dari halaman katalog."
      />

      <ul v-else class="dash__list">
        <li v-for="loan in recentRequests" :key="loan.id" class="dash__row">
          <RouterLink :to="{ name: 'loan-detail', params: { id: loan.id } }" class="row">
            <div class="row__main">
              <div class="row__top">
                <StatusBadge :tone="LOAN_TONES[loan.status] ?? 'neutral'">
                  {{ loan.status_label }}
                </StatusBadge>
                <span class="row__count num">{{ (loan.items ?? []).length }} aset</span>
              </div>
              <h3 class="row__title">{{ itemNames(loan) || 'Pinjaman aset' }}</h3>
              <p class="row__meta text-2">
                Diajukan {{ fmtDate(loan.created_at) }}
                <template v-if="loan.starts_at && loan.ends_at">
                  · {{ fmtDate(loan.starts_at) }} sampai {{ fmtDate(loan.ends_at) }}
                </template>
              </p>
              <p v-if="loan.status === 'rejected' && loan.rejection_reason" class="row__reason">
                Alasan penolakan: {{ loan.rejection_reason }}
              </p>
            </div>
          </RouterLink>
        </li>
      </ul>

      <p v-if="!overviewLoading && !overviewError && recentRequests.length" class="more-link">
        <RouterLink :to="{ name: 'loans' }" class="btn">Semua pengajuan</RouterLink>
      </p>
    </section>

    <!-- 06 / NOTIFIKASI -->
    <section aria-labelledby="notifikasi-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">NOTIFIKASI</p>
        <h2 id="notifikasi-title" class="dash__title">Kabar terbaru untukmu.</h2>
      </div>

      <NotificationCenter />
    </section>

    <!-- 07 / KATALOG -->
    <section aria-labelledby="katalog-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">KATALOG</p>
        <h2 id="katalog-title" class="dash__title">Alat yang bisa kamu pinjam.</h2>
      </div>

      <form class="dash__search" role="search" @submit.prevent="submitSearch">
        <div class="field">
          <label for="dash-q" class="visually-hidden">Cari alat</label>
          <input
            id="dash-q"
            v-model="catalogQ"
            class="input"
            type="search"
            placeholder="Cari nama alat atau kode aset"
          />
        </div>
        <button type="submit" class="btn">
          <Search :size="15" aria-hidden="true" />
          Cari
        </button>
      </form>

      <LoadingState v-if="catalogLoading" label="Memuat katalog" />

      <ErrorState v-else-if="catalogError" :message="catalogError" @retry="loadCatalog" />

      <EmptyState
        v-else-if="!assets.length"
        title="Tidak ada alat tersedia"
        :hint="
          catalogQ.trim()
            ? 'Tidak ada alat yang cocok dengan pencarianmu. Coba kata kunci lain.'
            : 'Belum ada alat yang bisa dipinjam di organisasi ini.'
        "
      />

      <div v-else class="dash__grid">
        <AssetCard v-for="asset in assets" :key="asset.id" :asset="asset" />
      </div>

      <p class="more-link">
        <RouterLink :to="{ name: 'catalog' }" class="btn">Buka katalog lengkap</RouterLink>
      </p>
    </section>

    <!-- 08 / AKTIVITAS -->
    <section aria-labelledby="aktivitas-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">AKTIVITAS</p>
        <h2 id="aktivitas-title" class="dash__title">Jejak terakhir.</h2>
      </div>

      <LoadingState v-if="overviewLoading" label="Memuat aktivitas" />

      <ErrorState v-else-if="overviewError" :message="overviewError" @retry="loadOverview" />

      <EmptyState
        v-else-if="!activity.length"
        title="Belum ada aktivitas"
        hint="Aktivitas peminjamanmu akan tercatat di sini."
      />

      <ul v-else class="dash__trail">
        <li
          v-for="(entry, index) in activity"
          :key="`${entry.loan_id}-${entry.key}-${index}`"
          class="dash__trail-item"
        >
          <RouterLink
            :to="{ name: 'loan-detail', params: { id: entry.loan_id } }"
            class="dash__trail-link"
          >
            {{ entry.label }}
          </RouterLink>
          <span class="dash__trail-time num" :title="fmtDateTime(entry.occurred_at)">
            {{ fmtDate(entry.occurred_at) }}
          </span>
        </li>
      </ul>
    </section>

    <!-- 09 / MULAI -->
    <section aria-labelledby="mulai-title" class="dash__section">
      <div class="dash__head">
        <p class="index-label">MULAI</p>
        <h2 id="mulai-title" class="dash__title">Dua langkah pertama.</h2>
      </div>
      <div class="start__grid">
        <RouterLink :to="{ name: 'catalog' }" class="start__card">
          <strong class="start__title">Cari aset</strong>
          <span class="start__copy">Telusuri aset yang bisa dipinjam di organisasimu.</span>
        </RouterLink>
        <RouterLink :to="{ name: 'loans' }" class="start__card">
          <strong class="start__title">Pinjaman saya</strong>
          <span class="start__copy">Lihat pengajuan, pinjaman aktif, dan riwayat.</span>
        </RouterLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dash {
  display: grid;
  gap: 2.5rem;
}

.dash__section {
  display: grid;
  gap: 1.5rem;
}

.dash__head {
  max-width: 46rem;
}

.dash__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

.dash__search {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
}

.dash__search .field {
  flex: 1 1 18rem;
  margin: 0;
}

/* Daftar baris editorial: rule antar baris, tanpa bayangan. */
.dash__list {
  display: grid;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: center;
  gap: 1.25rem;
  min-height: 44px;
  padding: 1.125rem 1rem;
  border-bottom: 1px solid var(--ln-border);
  color: var(--ln-text);
  text-decoration: none;
  transition:
    background-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease);
}

.row:hover {
  background: var(--ln-text);
  color: var(--ln-bg);
}

.row:hover .row__meta,
.row:hover .row__count,
.row:hover .row__reason {
  color: var(--ln-bg);
}

.row__main {
  display: grid;
  gap: 0.375rem;
  min-width: 0;
}

.row__top {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.row__title {
  margin: 0;
  font-size: 1.125rem;
  letter-spacing: -0.03em;
}

.row__meta {
  margin: 0;
  font-size: 0.875rem;
  transition: color var(--ln-motion-fast) var(--ln-ease);
}

.row__reason {
  margin: 0;
  color: var(--ln-err);
  font-size: 0.8125rem;
}

.row__count {
  color: var(--ln-text-2);
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

/* Baris tagihan yang menahan serah terima: tanpa efek hover membalik warna
   karena ada tombol di dalamnya. */
.pay-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 1rem;
  padding: 1.125rem 1rem;
  border-bottom: 1px solid var(--ln-border);
}

.tag {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 0.125rem 0.5rem;
  white-space: nowrap;
}

.tag--warn {
  color: var(--ln-warn);
  border: 1px solid var(--ln-warn);
}

.tag--err {
  color: var(--ln-err);
  border: 1px solid var(--ln-err);
}

.row:hover .tag--warn {
  color: var(--ln-bg);
  border-color: var(--ln-bg);
}

.row:hover .tag--err {
  color: var(--ln-bg);
  border-color: var(--ln-bg);
}

.more-link {
  display: flex;
  justify-content: center;
  margin: 0;
}

/* Aktivitas: baris ringkas dengan indeks dan waktu. */
.dash__trail {
  display: grid;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.dash__trail-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.dash__trail-link {
  color: var(--ln-text);
  text-decoration: none;
}

.dash__trail-link:hover {
  text-decoration: underline;
}

.dash__trail-time {
  color: var(--ln-text-2);
  font-size: 0.75rem;
  white-space: nowrap;
}

.dash__grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
}

/* Titik awal: dua kartu hover-inversion. */
.start__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  background: var(--ln-border);
  border: 1px solid var(--ln-border);
}

.start__card {
  position: relative;
  display: grid;
  gap: 0.5rem;
  align-content: start;
  min-height: 12rem;
  padding: 1.5rem;
  background: var(--ln-surface);
  color: var(--ln-text);
  text-decoration: none;
  transition:
    background-color var(--ln-motion-base) var(--ln-ease),
    color var(--ln-motion-base) var(--ln-ease);
}

.start__card:hover {
  background: var(--ln-text);
  color: var(--ln-bg);
}

.start__card:hover .start__copy {
  color: var(--ln-bg);
}

.start__title {
  font-family: var(--ln-font-display);
  font-size: clamp(1.5rem, 2.4vw, 2.25rem);
  font-weight: 500;
  letter-spacing: -0.04em;
  line-height: 1.05;
}

.start__copy {
  max-width: 30ch;
  color: var(--ln-text-2);
  font-size: 0.9375rem;
  line-height: 1.6;
}

@media (max-width: 64rem) {
  .dash__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 40rem) {
  .dash {
    gap: 2rem;
  }

  .dash__grid,
  .start__grid {
    grid-template-columns: 1fr;
  }

  .more-link .btn {
    width: 100%;
  }

  .row {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.75rem;
    padding-inline: 0.5rem;
  }

  .dash__trail-item {
    grid-template-columns: minmax(0, 1fr);
    row-gap: 0.25rem;
  }
}
</style>
