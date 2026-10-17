<script setup>
/*
 * Booking saya (butuh masuk, tanpa membership). Riwayat pemesanan customer dari
 * GET /bookings: lintas penyedia, memakai harga yang tersimpan saat memesan.
 *
 * Halaman ini sengaja tidak berada di dalam /app: shell aplikasi memerlukan
 * membership pada satu organisasi, sedangkan customer cukup punya akun.
 */
import { nextTick, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { fmtDateTime, fmtPrice, LOAN_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import PublicSiteHeader from '../components/PublicSiteHeader.vue'
import PageHead from '../components/ui/PageHead.vue'
import ConfirmDialog from '../components/ui/ConfirmDialog.vue'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'

const route = useRoute()
const router = useRouter()

const bookings = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const currentPage = ref(1)
const cancelTarget = ref(null)
const cancelBusy = ref(false)
const cancelError = ref(null)
const payError = ref(null)
const walletBalance = ref(null)
const walletPendingId = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

function periodText(booking) {
  if (!booking.starts_at || !booking.ends_at) {
    return 'Periode tidak dicatat'
  }

  const days = booking.days ? `, ${booking.days} hari` : ''

  return `${fmtDateTime(booking.starts_at)} sampai ${fmtDateTime(booking.ends_at)}${days}`
}

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null
  currentPage.value = pageNumber

  try {
    const payload = await apiGet(`/bookings?page=${pageNumber}`)
    bookings.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      router.push({ name: 'login', query: { redirect: route.fullPath } })
      return
    }

    bookings.value = []
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar booking.'
  } finally {
    loading.value = false
  }
}

async function revealRows() {
  await nextTick()
  motion.reveal('.bookings__item', { y: 12, stagger: 0.04 })
  motion.refresh()
}

async function goToPage(pageNumber) {
  await load(pageNumber)
  await revealRows()
}

function askCancel(booking) {
  cancelError.value = null
  cancelTarget.value = booking
}

/*
 * Saldo dompet hanya untuk menampilkan pilihan pembayaran saldo. Kalau gagal
 * dimuat, tombolnya tidak muncul dan status pembayaran tetap terlihat.
 */
async function loadWalletBalance() {
  try {
    const payload = await apiGet('/wallet/balance')
    walletBalance.value = payload.data.wallet_balance
  } catch {
    walletBalance.value = null
  }
}

/* Saldo hanya bisa menutup pembayaran penuh (belum ada pembayaran campuran). */
function canUseWallet(booking) {
  return walletBalance.value !== null && Number(booking.subtotal) <= Number(walletBalance.value)
}

/*
 * Bayar dengan saldo: server memotong saldo atomik dan menandai booking lunas,
 * jadi tidak ada halaman pembayaran yang dibuka. Midtrans tidak dipakai untuk
 * membayar sewa — isi saldo dulu di menu Dompet.
 */
async function payWithWallet(booking) {
  if (!booking?.provider?.slug) {
    return
  }

  walletPendingId.value = booking.id
  payError.value = null

  try {
    await apiPost(`/providers/${booking.provider.slug}/bookings/${booking.id}/payment`, {
      payment_method: 'wallet',
    })

    await load(currentPage.value)
    await loadWalletBalance()
  } catch (e) {
    payError.value = e instanceof ApiError ? e.message : 'Pembayaran saldo gagal.'
  } finally {
    walletPendingId.value = null
  }
}

/*
 * Pembatalan hanya tersedia sebelum penyedia menyetujui (keputusan 12), jadi
 * tombolnya hanya muncul pada status `requested`. Setelah disetujui, jadwal
 * sudah dikunci dan pembatalan lewat penyedia.
 */
async function confirmCancel() {
  const booking = cancelTarget.value

  if (!booking?.provider?.slug) {
    return
  }

  cancelBusy.value = true
  cancelError.value = null

  try {
    await apiPost(`/providers/${booking.provider.slug}/bookings/${booking.id}/cancel`, {})
    cancelTarget.value = null
    await goToPage(currentPage.value)
  } catch (e) {
    cancelError.value = e instanceof ApiError ? e.message : 'Booking gagal dibatalkan.'
  } finally {
    cancelBusy.value = false
  }
}

onMounted(async () => {
  await load(1)
  await loadWalletBalance()
  await revealRows()
})
</script>

<template>
  <div ref="page" class="bookings">
    <PublicSiteHeader />

    <main class="container bookings__body">
      <PageHead
        label="BOOKING"
        title="Booking saya"
        lede="Pemesanan yang kamu ajukan, lintas penyedia. Harga yang tampil tersimpan saat kamu memesan, jadi tidak ikut berubah walau tarif penyedia diperbarui."
      />

      <LoadingState v-if="loading" label="Memuat booking" />

      <ErrorState
        v-else-if="error"
        title="Booking gagal dimuat"
        :message="error"
        @retry="load(1)"
      />

      <template v-else-if="bookings.length">
        <p v-if="payError" class="form-error" role="alert">{{ payError }}</p>

        <ul class="bookings__list">
          <li v-for="booking in bookings" :key="booking.id" class="bookings__item">
            <div class="bookings__main">
              <div class="bookings__head">
                <h3 class="bookings__provider">
                  <RouterLink
                    v-if="booking.provider?.slug"
                    :to="{ name: 'explore-provider', params: { slug: booking.provider.slug } }"
                  >
                    {{ booking.provider.name }}
                  </RouterLink>
                  <template v-else>{{ booking.provider?.name ?? 'Penyedia' }}</template>
                </h3>

                <StatusBadge :tone="LOAN_TONES[booking.status] ?? 'neutral'">
                  {{ booking.status_label ?? booking.status }}
                </StatusBadge>
              </div>

              <p class="bookings__period">{{ periodText(booking) }}</p>

              <p v-if="booking.status === 'requested'" class="bookings__hint">
                Belum ditagih: menunggu persetujuan operator. Setelah disetujui, kamu diminta
                membayar yang dulu sebelum aset diserahkan.
              </p>

              <p
                v-else-if="booking.status === 'approved' && !booking.payment"
                class="bookings__hint"
              >
                Booking sudah disetujui. Bayar dengan saldo dompet — setelah admin memverifikasi
                dana masuk, aset diserahkan sesuai jadwal pengambilan.
              </p>

              <ul v-if="booking.items?.length" class="bookings__lines">
                <li v-for="line in booking.items" :key="line.asset_id" class="bookings__line">
                  <span class="bookings__line-name">{{ line.name ?? line.code }}</span>
                  <span class="bookings__line-calc">
                    {{ line.days }} hari &times; {{ fmtPrice(line.daily_rate) }}
                  </span>
                </li>
              </ul>

              <p v-if="booking.rejection_reason" class="bookings__reason">
                Alasan ditolak: {{ booking.rejection_reason }}
              </p>
            </div>

            <div class="bookings__money">
              <p class="index-label">TOTAL</p>
              <p class="bookings__total num">{{ fmtPrice(booking.subtotal) ?? 'Belum dicatat' }}</p>
              <p v-if="Number(booking.deposit) > 0" class="bookings__deposit">
                Deposit {{ fmtPrice(booking.deposit) }}, belum ditagih
              </p>

              <p v-if="booking.payment" class="bookings__payment">
                Pembayaran: {{ booking.payment.status_label }}
                <template v-if="booking.payment.status === 'pending' && booking.payment.expires_at">
                  sampai {{ fmtDateTime(booking.payment.expires_at) }}
                </template>
              </p>

              <p
                v-if="booking.payment?.status === 'paid' && booking.status === 'approved'"
                class="bookings__hint"
              >
                <template v-if="booking.payment.is_verified">
                  Dana sudah diverifikasi admin — aset diserahkan sesuai jadwal pengambilan.
                </template>
                <template v-else>
                  Pembayaranmu sudah masuk. Menunggu verifikasi admin sebelum aset diserahkan.
                </template>
              </p>

              <button
                v-if="booking.can_pay && walletBalance !== null"
                type="button"
                class="btn btn--primary bookings__pay"
                :disabled="walletPendingId === booking.id || !canUseWallet(booking)"
                @click="payWithWallet(booking)"
              >
                {{ walletPendingId === booking.id ? 'Memotong saldo…' : 'Bayar dengan saldo' }}
              </button>

              <p
                v-if="booking.can_pay && walletBalance !== null && !canUseWallet(booking)"
                class="bookings__hint"
              >
                Saldo dompet {{ fmtPrice(walletBalance) }} belum cukup.
                <RouterLink :to="{ name: 'wallet' }" class="link-sweep">Isi saldo</RouterLink>
              </p>

              <button
                v-if="booking.status === 'requested'"
                type="button"
                class="btn btn--ghost bookings__cancel"
                @click="askCancel(booking)"
              >
                Batalkan
              </button>
            </div>
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
        title="Belum ada booking"
        hint="Pilih penyedia di Jelajah, tentukan periode, lalu ajukan sewa. Booking yang kamu ajukan muncul di sini."
      >
        <template #action>
          <RouterLink :to="{ name: 'explore' }" class="btn">Jelajah penyedia</RouterLink>
        </template>
      </EmptyState>
    </main>

    <ConfirmDialog
      v-if="cancelTarget"
      title="Batalkan booking ini?"
      :message="`${cancelTarget.provider?.name ?? 'Penyedia'} belum menyetujui pengajuanmu, jadi pembatalan sekarang tidak dikenakan biaya.`"
      confirm-label="Batalkan"
      :busy="cancelBusy"
      :error="cancelError"
      @confirm="confirmCancel"
      @cancel="cancelTarget = null"
    />
  </div>
</template>

<style scoped>
.bookings {
  min-height: 100vh;
  background: var(--ln-bg);
}

.bookings__body {
  display: grid;
  gap: 2rem;
  padding-block: var(--ln-section-y);
}

.bookings__list {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.bookings__item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 1.5rem;
  align-items: start;
  padding-block: 1.5rem;
  border-bottom: 1px solid var(--ln-border);
}

.bookings__main {
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}

.bookings__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.bookings__provider {
  margin: 0;
  font-size: var(--ln-title);
  letter-spacing: -0.02em;
}

.bookings__provider a {
  color: inherit;
  text-decoration: none;
}

.bookings__provider a:hover {
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.bookings__period {
  margin: 0;
  font-family: var(--ln-font-mono);
  font-size: 0.8125rem;
  letter-spacing: 0.02em;
  color: var(--ln-text-2);
}

.bookings__lines {
  display: grid;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.bookings__line {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.5rem;
}

.bookings__line-name {
  max-width: 46ch;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bookings__line-calc {
  color: var(--ln-text-2);
  font-size: 0.8125rem;
}

.bookings__reason {
  max-width: 60ch;
  margin: 0;
  color: var(--ln-text-2);
}

.bookings__money {
  display: grid;
  gap: 0.25rem;
  justify-items: end;
  text-align: right;
}

.bookings__money .index-label {
  margin: 0;
}

.bookings__total {
  margin: 0;
  font-family: var(--ln-font-display);
  font-size: var(--ln-title);
  letter-spacing: -0.02em;
  white-space: nowrap;
}

.bookings__deposit {
  max-width: 22ch;
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.75rem;
}

.bookings__payment {
  max-width: 26ch;
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.75rem;
}

/* Catatan saldo kurang: mengarahkan ke halaman dompet tanpa menutup tombol. */
.bookings__hint {
  max-width: 30ch;
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.75rem;
  line-height: 1.5;
}

.bookings__pay,
.bookings__cancel {
  margin-top: 0.25rem;
}

@media (max-width: 60rem) {
  .bookings__item {
    grid-template-columns: minmax(0, 1fr);
  }

  .bookings__money {
    justify-items: start;
    text-align: left;
  }
}
</style>
