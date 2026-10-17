<script setup>
/*
 * Daftar peminjaman. Borrower melihat pinjaman miliknya sendiri dengan tab
 * kelompok (semua, menunggu, aktif, riwayat); operator dan admin melihat
 * semua pinjaman organisasi dengan filter status. Data asli dari GET /loans.
 * Presentasi berupa baris editorial bergaris; state, API, dan handler tidak
 * berubah — hanya markup, gaya, dan gerak (lib/motion.js).
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiGet, ApiError } from '../lib/http'
import { readQuery, writeQuery } from '../lib/filterQuery'
import { auth } from '../lib/auth'
import { fmtDateTime, LOAN_TONES, PAYMENT_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'
import PageHead from '../components/ui/PageHead.vue'

const isManagement = computed(() => auth.role === 'operator' || auth.role === 'admin')

/*
 * Label pembayaran satu baris. "Lunas" saja menyesatkan: penyewa harus tahu
 * dananya masih menunggu verifikasi staf, dan staf harus tahu mana yang bisa
 * langsung diambil.
 */
function paymentLabel(loan) {
  if (loan.is_paid && loan.is_payment_verified) {
    return 'Dana terverifikasi'
  }
  if (loan.is_paid) {
    return 'Menunggu verifikasi dana'
  }
  return loan.payment_status_label
}

function paymentTone(loan) {
  if (loan.is_paid) {
    return loan.is_payment_verified ? 'ok' : 'warn'
  }
  return PAYMENT_TONES[loan.payment_status] ?? 'neutral'
}

const GROUP_TABS = [
  { value: '', label: 'Semua' },
  { value: 'pending', label: 'Menunggu persetujuan' },
  { value: 'active', label: 'Aktif' },
  { value: 'history', label: 'Riwayat' },
]

const STATUS_OPTIONS = [
  { value: '', label: 'Semua status' },
  { value: 'requested', label: 'Menunggu persetujuan' },
  { value: 'approved', label: 'Disetujui, menunggu diambil' },
  { value: 'checked_out', label: 'Sedang dipinjam' },
  { value: 'returned', label: 'Sudah dikembalikan' },
  { value: 'rejected', label: 'Ditolak' },
  { value: 'cancelled', label: 'Dibatalkan' },
  { value: 'expired', label: 'Kedaluwarsa' },
]

const route = useRoute()
const router = useRouter()

/*
 * Kelompok tab bisa datang dari tautan dashboard (mis. /app/loans?group=active).
 * Nilai divalidasi ke daftar tab agar tidak pernah mengirim kelompok asing
 * ke API.
 */
const queryGroup = String(route.query.group ?? '')
const group = ref(GROUP_TABS.some((tab) => tab.value === queryGroup) ? queryGroup : '')
const status = ref(isManagement.value ? (readQuery(route, ['status']).status ?? '') : '')
const loans = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

function itemNames(loan) {
  const names = (loan.items ?? []).map((item) => item.asset?.name).filter(Boolean)
  if (names.length <= 2) {
    return names.join(', ')
  }
  return `${names.slice(0, 2).join(', ')} dan ${names.length - 2} lainnya`
}

async function load(page = 1) {
  loading.value = true
  error.value = null

  const params = new URLSearchParams({ page: String(page) })
  if (group.value && !status.value) {
    params.set('group', group.value)
  }
  if (status.value) {
    params.set('status', status.value)
  }

  try {
    const payload = await apiGet(`/loans?${params.toString()}`)
    loans.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar peminjaman.'
  } finally {
    loading.value = false
  }
}

function selectGroup(value) {
  group.value = value
  writeQuery(router, route, { group: value })
  load(1)
}

function applyStatus() {
  writeQuery(router, route, { status: status.value })
  load(1)
}

onMounted(async () => {
  await load()
  await nextTick()
  motion.reveal('.loan-row', { stagger: 0.05 })
  motion.refresh()
})

// Setelah paginasi/filter mengganti isi daftar, hitung ulang posisi ScrollTrigger.
watch(loading, async (isLoading) => {
  if (isLoading) {
    return
  }
  await nextTick()
  motion.refresh()
})
</script>

<template>
  <section ref="page" class="loans">
    <PageHead
      label="PEMINJAMAN"
      :title="isManagement ? 'Peminjaman' : 'Pinjaman saya'"
      :lede="
        isManagement
          ? 'Semua peminjaman organisasi aktif, dengan filter status.'
          : 'Daftar pengajuan dan riwayat pinjaman milikmu.'
      "
    />

    <nav v-if="!isManagement" class="tabs" aria-label="Kelompok peminjaman">
      <button
        v-for="tab in GROUP_TABS"
        :key="tab.value"
        type="button"
        class="tabs__btn"
        :class="{ 'tabs__btn--active': group === tab.value }"
        :aria-pressed="group === tab.value"
        @click="selectGroup(tab.value)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <form v-else class="filter" @submit.prevent="applyStatus">
      <div class="field">
        <label for="loan-status" class="visually-hidden">Filter status</label>
        <select id="loan-status" v-model="status" class="input">
          <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>
      <button type="submit" class="btn btn--primary">Terapkan</button>
    </form>

    <LoadingState v-if="loading" label="Memuat peminjaman" />

    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="loans.length">
      <ul class="list">
        <li v-for="loan in loans" :key="loan.id" class="loan-row">
          <RouterLink :to="{ name: 'loan-detail', params: { id: loan.id } }" class="row">
            <div class="row__main">
              <div class="row__top">
                <StatusBadge :tone="LOAN_TONES[loan.status] ?? 'neutral'">
                  {{ loan.status_label }}
                </StatusBadge>
                <span v-if="loan.is_overdue" class="tag tag--warn">Terlambat</span>
                <span v-if="loan.is_awaiting_pickup" class="tag tag--ok"> Siap diambil </span>
                <!-- Sumbu pembayaran: penyewa dan pengelola sama-sama perlu tahu
                     booking mana yang tertahan karena bayar atau verifikasi. -->
                <StatusBadge v-if="loan.payment_status" :tone="paymentTone(loan)">
                  {{ paymentLabel(loan) }}
                </StatusBadge>
              </div>
              <h2 class="row__title">{{ itemNames(loan) || 'Pinjaman aset' }}</h2>
              <p class="row__meta text-2">
                {{ fmtDateTime(loan.starts_at) }} sampai {{ fmtDateTime(loan.ends_at) }}
                <template v-if="isManagement && loan.borrower">
                  · {{ loan.borrower.name }}
                </template>
              </p>
            </div>
            <span class="row__count num">{{ (loan.items ?? []).length }} aset</span>
          </RouterLink>
        </li>
      </ul>

      <Pagination
        v-if="meta"
        :current="meta.current_page"
        :last="meta.last_page"
        :total="meta.total"
        :from="meta.from"
        :to="meta.to"
        @page="load"
      />
    </template>

    <EmptyState
      v-else
      :title="isManagement ? 'Belum ada peminjaman' : 'Belum ada pinjaman'"
      :hint="
        isManagement ? 'Sesuaikan filter status.' : 'Cari aset lalu minta pinjam lewat detail aset.'
      "
    />
  </section>
</template>

<style scoped>
.loans {
  display: grid;
  gap: 1.75rem;
}

/* Tab kelompok: label mono huruf besar, rule tebal untuk tab aktif. */
.tabs {
  display: flex;
  gap: 0;
  flex-wrap: wrap;
  border-bottom: 2px solid var(--ln-border-strong);
}

.tabs__btn {
  display: inline-flex;
  align-items: baseline;
  gap: 0.5rem;
  min-height: 44px;
  margin-bottom: -2px;
  padding: 0.625rem 1.125rem 0.625rem 0;
  border: 0;
  border-bottom: 2px solid transparent;
  background: none;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  cursor: pointer;
  transition: color var(--ln-motion-fast) var(--ln-ease);
}

.tabs__btn:hover {
  color: var(--ln-text);
}

.tabs__btn--active {
  border-bottom-color: var(--ln-text);
  color: var(--ln-text);
}

/* Filter status: baris editorial di atas garis rule. */
.filter {
  display: flex;
  gap: 0.75rem;
  align-items: end;
  padding: 1rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
}

.filter .field {
  margin: 0;
  min-width: 16rem;
}

/* Daftar baris: garis rule antar baris, tanpa kartu berbayang. */
.list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  border-top: 1px solid var(--ln-border);
}

.row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
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

.row:hover .row__count,
.row:hover .row__meta {
  color: var(--ln-bg);
}

.row__main {
  display: grid;
  gap: 0.375rem;
}

.row__top {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
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

.row__count {
  color: var(--ln-text-2);
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  white-space: nowrap;
  transition: color var(--ln-motion-fast) var(--ln-ease);
}

.tag {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 0.125rem 0.5rem;
}

.tag--ok {
  color: var(--ln-ok);
  border: 1px solid var(--ln-ok);
}

.tag--warn {
  color: var(--ln-warn);
  border: 1px solid var(--ln-warn);
}

@media (max-width: 40rem) {
  .row {
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.75rem 1rem;
  }

  .filter {
    flex-direction: column;
    align-items: stretch;
  }

  .filter .field {
    min-width: 0;
  }
}
</style>
