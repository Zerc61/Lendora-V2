<script setup>
/*
 * Menu Keuangan konsol platform.
 *
 * Buku besar uang lintas organisasi: pembayaran sewa yang masuk, sewa yang
 * disetujui tapi belum dibayar, dan pengembalian dana ke dompet. Satu tindakan:
 * verifikasi dana untuk pembayaran yang sudah lunas — serah terima di sisi
 * penyedia menunggu verifikasi ini.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { apiGet, apiPost, ApiError } from '../../lib/http'
import { toast } from '../../lib/toast'
import { fmtDateTime, fmtPrice, PAYMENT_TONES, WALLET_TONES } from '../../lib/format'
import { usePageMotion } from '../../lib/motion'
import PageHead from '../../components/ui/PageHead.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import LoadingState from '../../components/LoadingState.vue'
import EmptyState from '../../components/EmptyState.vue'
import ErrorState from '../../components/ErrorState.vue'
import Pagination from '../../components/Pagination.vue'

const loans = ref([])
const summary = ref(null)
const payments = ref([])
const paymentsMeta = ref(null)
const unpaid = ref([])
const unpaidMeta = ref(null)
const refunds = ref([])
const refundsMeta = ref(null)
const loading = ref(false)
const error = ref(null)
const refundsLoading = ref(false)
const refundsError = ref(null)
const verifyBusyId = ref(null)
const verifyError = ref(null)

const page = ref(null)

const motion = usePageMotion(page)

const summaryCards = computed(() => {
  if (!summary.value) return []

  return [
    {
      label: 'Uang sewa masuk',
      value: summary.value.revenue_total,
      sub: `${summary.value.revenue_count} pembayaran lunas`,
    },
    {
      label: 'Belum dibayar',
      value: summary.value.unpaid_total,
      sub: `${summary.value.unpaid_count} sewa disetujui`,
    },
    {
      label: 'Menunggu verifikasi',
      value: summary.value.unverified_total,
      sub: `${summary.value.unverified_count} dana belum dipastikan`,
    },
    { label: 'Pengembalian dana', value: summary.value.refund_total, sub: 'kembali ke dompet' },
  ]
})

const paidMethodLabel = (method) => (method === 'wallet' ? 'Saldo dompet' : 'Midtrans')

async function load() {
  loading.value = true
  error.value = null

  try {
    const payload = await apiGet('/platform/finance')
    summary.value = payload.summary ?? null
    payments.value = payload.payments?.data ?? []
    paymentsMeta.value = payload.payments?.meta ?? null
    unpaid.value = payload.unpaid?.data ?? []
    unpaidMeta.value = payload.unpaid?.meta ?? null
    await loadRefunds(1)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat keuangan.'
  } finally {
    loading.value = false
  }
}

async function loadRefunds(pageNumber) {
  refundsLoading.value = true
  refundsError.value = null

  try {
    const payload = await apiGet(`/platform/wallet-transactions?type=refund&page=${pageNumber}`)
    refunds.value = payload.data ?? []
    refundsMeta.value = payload.meta ?? null
  } catch (e) {
    refunds.value = []
    refundsError.value = e instanceof ApiError ? e.message : 'Gagal memuat pengembalian dana.'
  } finally {
    refundsLoading.value = false
  }
}

async function revealRows() {
  await nextTick()
  motion.reveal('.row', { y: 10, stagger: 0.03 })
  motion.refresh()
}

async function goToPayments(pageNumber) {
  const payload = await apiGet(`/platform/finance?page=${pageNumber}`)
  payments.value = payload.payments?.data ?? payments.value
  paymentsMeta.value = payload.payments?.meta ?? paymentsMeta.value
  await revealRows()
}

async function goToUnpaid(pageNumber) {
  const payload = await apiGet(`/platform/finance?unpaid_page=${pageNumber}`)
  unpaid.value = payload.unpaid?.data ?? unpaid.value
  unpaidMeta.value = payload.unpaid?.meta ?? unpaidMeta.value
  await revealRows()
}

async function goToRefunds(pageNumber) {
  await loadRefunds(pageNumber)
  await revealRows()
}

/*
 * Verifikasi dana: admin memastikan uangnya benar masuk sebelum aset boleh
 * diserahkan. Server hanya menerima pembayaran yang sudah lunas.
 */
async function verify(item) {
  verifyBusyId.value = item.id
  verifyError.value = null

  try {
    await apiPost(`/platform/finance/payments/${item.id}/verify`, {})
    await load()
    await revealRows()
    toast.ok('Dana diverifikasi.')
  } catch (e) {
    verifyError.value = e instanceof ApiError ? e.message : 'Verifikasi dana gagal.'
  } finally {
    verifyBusyId.value = null
  }
}

onMounted(async () => {
  await load()
  await revealRows()
})
</script>

<template>
  <section ref="page" class="platform-view">
    <PageHead
      label="KEUANGAN"
      title="Buku besar uang"
      lede="Uang sewa yang masuk, sewa yang belum dibayar, dan pengembalian dana ke dompet — lintas organisasi. Verifikasi dana pada pembayaran lunas supaya penyedia boleh menyerahkan aset."
    />

    <LoadingState v-if="loading" label="Memuat keuangan" />

    <ErrorState
      v-else-if="error"
      title="Data keuangan gagal dimuat"
      :message="error"
      @retry="load"
    />

    <template v-else>
      <dl v-if="summary" class="summary">
        <div v-for="card in summaryCards" :key="card.label" class="summary__item">
          <dt class="summary__label">{{ card.label }}</dt>
          <dd class="summary__value num">{{ fmtPrice(card.value) }}</dd>
          <dt class="summary__hint">{{ card.sub }}</dt>
        </div>
      </dl>

      <section class="block" aria-label="Pembayaran sewa">
        <h2 class="block__title index-label">PEMBAYARAN SEWA</h2>

        <p v-if="verifyError" class="form-error" role="alert">{{ verifyError }}</p>

        <ul v-if="payments.length" class="roster" aria-label="Pembayaran sewa masuk">
          <li v-for="item in payments" :key="item.id" class="row">
            <div class="row__id">
              <p class="row__name">
                {{ item.loan?.borrower?.name ?? 'Penyewa' }}
                <template v-if="item.loan?.organization_name">
                  · {{ item.loan.organization_name }}
                </template>
              </p>
              <p class="row__meta num">
                {{ item.order_id }} · {{ paidMethodLabel(item.payment_method) }} ·
                {{ fmtDateTime(item.paid_at ?? item.created_at) }}
              </p>
            </div>
            <span class="row__amount num">{{ fmtPrice(item.amount) }}</span>
            <div class="row__actions">
              <StatusBadge :tone="PAYMENT_TONES[item.status] ?? 'neutral'">
                {{ item.status_label }}
              </StatusBadge>
              <template v-if="item.status === 'paid'">
                <StatusBadge :tone="item.is_verified ? 'ok' : 'warn'">
                  {{ item.is_verified ? 'Dana terverifikasi' : 'Menunggu verifikasi' }}
                </StatusBadge>
                <button
                  v-if="!item.is_verified"
                  type="button"
                  class="btn btn--primary row__verify"
                  :disabled="verifyBusyId === item.id"
                  @click="verify(item)"
                >
                  {{ verifyBusyId === item.id ? 'Memverifikasi…' : 'Verifikasi dana' }}
                </button>
              </template>
            </div>
          </li>
        </ul>

        <EmptyState
          v-else
          title="Belum ada pembayaran sewa"
          hint="Muncul setelah penyewa bayar sewa."
        />

        <Pagination
          v-if="paymentsMeta && paymentsMeta.total > 0"
          :current="paymentsMeta.current_page"
          :last="paymentsMeta.last_page"
          :total="paymentsMeta.total"
          :from="paymentsMeta.from"
          :to="paymentsMeta.to"
          @page="goToPayments"
        />
      </section>

      <section class="block" aria-label="Sewa belum dibayar">
        <h2 class="block__title index-label">SEWA BELUM DIBAYAR</h2>

        <ul v-if="unpaid.length" class="roster" aria-label="Sewa yang belum dibayar">
          <li v-for="item in unpaid" :key="item.id" class="row">
            <div class="row__id">
              <p class="row__name">
                {{ item.borrower?.name ?? 'Penyewa' }}
              </p>
              <p class="row__meta num">
                Sewa #{{ item.id }} · {{ fmtDateTime(item.starts_at) }} s/d
                {{ fmtDateTime(item.ends_at) }}
              </p>
            </div>
            <span class="row__amount num">{{ fmtPrice(item.subtotal) }}</span>
            <StatusBadge tone="warn">Menunggu pembayaran</StatusBadge>
          </li>
        </ul>

        <EmptyState
          v-else
          title="Semua sewa lunas"
          hint="Tidak ada sewa yang disetujui tanpa pembayaran."
        />

        <Pagination
          v-if="unpaidMeta && unpaidMeta.total > 0"
          :current="unpaidMeta.current_page"
          :last="unpaidMeta.last_page"
          :total="unpaidMeta.total"
          :from="unpaidMeta.from"
          :to="unpaidMeta.to"
          @page="goToUnpaid"
        />
      </section>

      <section class="block" aria-label="Pengembalian dana">
        <h2 class="block__title index-label">PENGEMBALIAN DANA</h2>

        <LoadingState v-if="refundsLoading" label="Memuat pengembalian dana" />

        <ErrorState
          v-else-if="refundsError"
          title="Pengembalian dana gagal dimuat"
          :message="refundsError"
          @retry="loadRefunds(1)"
        />

        <ul v-else-if="refunds.length" class="roster" aria-label="Riwayat pengembalian dana">
          <li v-for="item in refunds" :key="item.id" class="row">
            <div class="row__id">
              <p class="row__name">{{ item.user?.name ?? 'Pengguna tidak dikenal' }}</p>
              <p class="row__meta num">
                {{ item.reference_id }} · {{ fmtDateTime(item.created_at) }}
              </p>
            </div>
            <span class="row__amount num row__amount--in">+{{ fmtPrice(item.amount) }}</span>
            <StatusBadge :tone="WALLET_TONES[item.status] ?? 'neutral'">
              {{ item.status_label }}
            </StatusBadge>
          </li>
        </ul>

        <EmptyState
          v-else
          title="Belum ada pengembalian dana"
          hint="Muncul saat booking dibatalkan setelah dibayar dengan saldo."
        />

        <Pagination
          v-if="refundsMeta && refundsMeta.total > 0"
          :current="refundsMeta.current_page"
          :last="refundsMeta.last_page"
          :total="refundsMeta.total"
          :from="refundsMeta.from"
          :to="refundsMeta.to"
          @page="goToRefunds"
        />
      </section>
    </template>
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

.summary__hint {
  margin: 0;
  color: var(--ln-text-3);
  font-size: var(--ln-micro);
}

.summary__value {
  margin: 0;
  font-size: 1.25rem;
  font-variant-numeric: tabular-nums;
}

.block {
  display: grid;
  gap: 0.75rem;
}

.block__title {
  margin: 0;
  color: var(--ln-text-2);
  font-weight: 700;
}

.roster {
  display: grid;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.7rem 0.9rem;
  border: 1px solid var(--ln-border);
  border-radius: var(--ln-radius, 0.75rem);
  background: var(--ln-surface, var(--color-surface, transparent));
}

.row__id {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.row__name {
  margin: 0;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.row__meta {
  margin: 0;
  color: var(--ln-text-2);
  font-size: var(--ln-micro);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.row__amount {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.row__amount--in {
  color: var(--ln-ok, var(--color-success, inherit));
}

.row__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.row__verify {
  font-size: var(--ln-micro);
  padding: 0.35rem 0.7rem;
}

@media (max-width: 40rem) {
  .row {
    grid-template-columns: minmax(0, 1fr) auto;
  }
}
</style>
