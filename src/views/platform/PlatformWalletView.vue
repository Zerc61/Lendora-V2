<script setup>
/*
 * Pengawasan dompet lintas pengguna.
 *
 * Saldo dompet adalah uang titipan, jadi halaman ini hanya membaca: tidak ada
 * aksi yang mengubah saldo dari konsol. Ringkasan dihitung server dan sengaja
 * tidak ikut filter, supaya angka "saldo yang masih beredar" tidak berubah saat
 * admin menyaring satu pengguna atau satu jenis transaksi.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, ApiError } from '../../lib/http'
import { readQuery, writeQuery } from '../../lib/filterQuery'
import { fmtDateTime, fmtPrice, WALLET_TONES } from '../../lib/format'
import { signedWalletAmount, walletExpiry } from '../../lib/wallet'
import { usePageMotion } from '../../lib/motion'
import PageHead from '../../components/ui/PageHead.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import LoadingState from '../../components/LoadingState.vue'
import EmptyState from '../../components/EmptyState.vue'
import ErrorState from '../../components/ErrorState.vue'
import Pagination from '../../components/Pagination.vue'

const TYPE_OPTIONS = [
  { value: 'topup', label: 'Top up' },
  { value: 'deduction', label: 'Pembayaran' },
  { value: 'refund', label: 'Pengembalian dana' },
  { value: 'adjustment', label: 'Penyesuaian' },
]

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Menunggu pembayaran' },
  { value: 'success', label: 'Berhasil' },
  { value: 'failed', label: 'Gagal' },
  { value: 'reversed', label: 'Dibatalkan' },
]

const transactions = ref([])
const summary = ref(null)
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const filterQuery = ref('')
const filterType = ref('')
const filterStatus = ref('')

const page = ref(null)
const route = useRoute()
const router = useRouter()

const motion = usePageMotion(page)

const hasFilters = computed(
  () => filterQuery.value !== '' || filterType.value !== '' || filterStatus.value !== '',
)

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null

  const params = new URLSearchParams()

  if (filterQuery.value) {
    params.set('q', filterQuery.value)
  }

  if (filterType.value) {
    params.set('type', filterType.value)
  }

  if (filterStatus.value) {
    params.set('status', filterStatus.value)
  }

  params.set('page', String(pageNumber))

  try {
    const payload = await apiGet(`/platform/wallet-transactions?${params.toString()}`)
    transactions.value = payload.data ?? []
    summary.value = payload.summary ?? null
    meta.value = payload.meta ?? null
  } catch (e) {
    transactions.value = []
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat transaksi dompet.'
  } finally {
    loading.value = false
  }
}

async function revealRows() {
  await nextTick()
  motion.reveal('.row', { y: 10, stagger: 0.03 })
  motion.refresh()
}

async function applyFilters() {
  writeQuery(router, route, {
    q: filterQuery.value,
    type: filterType.value,
    status: filterStatus.value,
  })
  await load(1)
  await revealRows()
}

async function resetFilters() {
  filterQuery.value = ''
  filterType.value = ''
  filterStatus.value = ''
  await applyFilters()
}

async function goToPage(pageNumber) {
  await load(pageNumber)
  await revealRows()
}

onMounted(async () => {
  const initial = readQuery(route, ['q', 'type', 'status'])
  filterQuery.value = initial.q ?? ''
  filterType.value = initial.type ?? ''
  filterStatus.value = initial.status ?? ''

  await load(1)
  await revealRows()
})
</script>

<template>
  <section ref="page" class="platform-view">
    <PageHead
      label="DOMPET"
      title="Transaksi dompet"
      lede="Saldo dompet adalah uang titipan pengguna. Konsol ini hanya membaca: pengembalian dana dilakukan di dasbor Midtrans, bukan di sini."
    />

    <dl v-if="summary" class="summary">
      <div class="summary__item">
        <dt class="summary__label">Saldo beredar</dt>
        <dd class="summary__value num">{{ fmtPrice(summary.total_balance) }}</dd>
      </div>
      <div class="summary__item">
        <dt class="summary__label">Top up berhasil</dt>
        <dd class="summary__value num">{{ fmtPrice(summary.total_topped_up) }}</dd>
      </div>
      <div class="summary__item">
        <dt class="summary__label">Pemakaian saldo</dt>
        <dd class="summary__value num">{{ fmtPrice(summary.total_spent) }}</dd>
      </div>
    </dl>

    <form class="filters" @submit.prevent="applyFilters">
      <div class="field">
        <label for="pw-q" class="filters__label">Pemilik</label>
        <input
          id="pw-q"
          v-model="filterQuery"
          class="input"
          maxlength="100"
          placeholder="Nama atau email"
        />
      </div>
      <div class="field">
        <label for="pw-type" class="filters__label">Jenis</label>
        <select id="pw-type" v-model="filterType" class="input">
          <option value="">Semua jenis</option>
          <option v-for="opt in TYPE_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="field">
        <label for="pw-status" class="filters__label">Status</label>
        <select id="pw-status" v-model="filterStatus" class="input">
          <option value="">Semua status</option>
          <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="filters__actions">
        <button type="submit" class="btn btn--primary">Terapkan</button>
        <button type="button" class="btn" @click="resetFilters">Reset</button>
      </div>
    </form>

    <LoadingState v-if="loading" label="Memuat transaksi dompet" />

    <ErrorState
      v-else-if="error"
      title="Transaksi dompet gagal dimuat"
      :message="error"
      @retry="load(1)"
    />

    <template v-else-if="transactions.length">
      <ul class="roster" aria-label="Transaksi dompet lintas pengguna">
        <li v-for="item in transactions" :key="item.id" class="row">
          <div class="row__id">
            <p class="row__name">{{ item.user?.name ?? 'Pengguna tidak dikenal' }}</p>
            <p class="row__meta num">{{ item.user?.email }}</p>
            <p class="row__meta num">
              {{ item.type_label }} · {{ fmtDateTime(item.created_at) }}
              <template v-if="walletExpiry(item)">
                · BERLAKU SAMPAI {{ fmtDateTime(walletExpiry(item)) }}
              </template>
            </p>
          </div>

          <span class="row__amount num" :class="`row__amount--${item.direction}`">
            {{ signedWalletAmount(item) }}
          </span>

          <StatusBadge :tone="WALLET_TONES[item.status] ?? 'neutral'">
            {{ item.status_label }}
          </StatusBadge>
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
      title="Belum ada transaksi dompet"
      :hint="hasFilters ? 'Sesuaikan filter.' : 'Transaksi muncul setelah ada top up atau pembayaran dengan saldo.'"
    />
  </section>
</template>

<style scoped>
.platform-view {
  display: grid;
  gap: 1.5rem;
}

.summary {
  display: grid;
  gap: 1rem;
  margin: 0;
  padding-block: 1.25rem;
  border-block: 1px solid var(--ln-border);
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}

.summary__item {
  display: grid;
  gap: 0.25rem;
}

.summary__label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.summary__value {
  margin: 0;
  font-size: 1.25rem;
  font-variant-numeric: tabular-nums;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.75rem 1rem;
  padding-bottom: 1.25rem;
}

.filters .field {
  margin: 0;
  min-width: 11rem;
}

.filters__label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.filters__actions {
  display: flex;
  gap: 0.5rem;
}

.roster {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 1rem;
  padding-block: 1rem;
  border-bottom: 1px solid var(--ln-border);
}

.row__id {
  display: grid;
  gap: 0.125rem;
  min-width: 0;
}

.row__name {
  margin: 0;
  font-weight: 600;
}

.row__meta {
  margin: 0;
  overflow-wrap: anywhere;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  letter-spacing: 0.04em;
}

.row__amount {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.row__amount--in {
  color: var(--ln-ok);
}

@media (max-width: 48rem) {
  .row {
    grid-template-columns: minmax(0, 1fr);
    justify-items: start;
  }
}
</style>
